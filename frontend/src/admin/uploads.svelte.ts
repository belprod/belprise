/**
 * Upload manager (§5.7): one master at a time, in 32 MiB chunks sent in
 * order, each with its SHA-256. It lives outside the pages, so the upload
 * goes on while the admin navigates. After a network cut it waits and
 * resumes from what the server actually received.
 */
import { api, ApiError, getCsrfToken, notifyUnauthorized } from '../lib/api';
import { sha256Hex } from '../lib/sha256';
import { current } from './current.svelte';
import type { Stage } from './jobProgress';
import type { MediaInfo } from './media/analyze';
import { fingerprint } from './media/fingerprint';
import { generateImages } from './media/images';
import { fileSource } from './media/source';
import { toMeta, uploadImages } from './media/versions';
import type { PendingUpload, Version } from './types';

export const CHUNK_SIZE = 32 * 1024 * 1024;
const SPEED_WINDOW_MS = 20_000;
const MAX_CORRUPT_RETRIES = 3;

export type JobState = 'preparing' | 'uploading' | 'offline' | 'finishing' | 'images' | 'encoding' | 'done' | 'error' | 'cancelled';

export interface UploadJob {
  key: number;
  /** The file being sent (the master, then the playback file) */
  file: File;
  /** What the admin sees: the master's name, never the playback files' */
  name: string;
  info: MediaInfo;
  videoId: number;
  videoLabel: string;
  uploadId: number | null;
  size: number;
  /** Bytes confirmed by the server plus the chunk in flight */
  sent: number;
  state: JobState;
  message: string;
  /** Bytes per second over the last seconds */
  speed: number;
  /** Seconds left, null while unknown */
  eta: number | null;
  imagesProgress: number;
  version: Version | null;
  /** 'master': the master is being sent; 'playback': its playback versions (§5.7 bis) */
  phase: 'master' | 'playback';
  /** What the playback versions are encoded from: the master itself, a joined H.264 file, or the server's copy; null: by the server (§5.7 bis) */
  encodeSource: File | string | null;
  /** What the poster and thumbnails are taken from: the master, or its joined H.264 file (ProRes, MXF) */
  imageFile: File | null;
  encodeProgress: number;
  /** Steps of this job, for one overall progress bar */
  stages: Stage[];
  stage: Stage;
  /** Playback file being sent, out of how many */
  partIndex: number;
  partCount: number;
}

const OPTIMISING = 'Laissez cette page ouverte jusqu’à la fin.';

/** The operator chose to encode the playback versions on the server (§5.7 bis). */
export function serverEncoding(): boolean {
  return current.session?.encoding === 'server';
}

class NetworkError extends Error {}

class UploadManager {
  jobs = $state<UploadJob[]>([]);
  /** Increments when a version is created (pages reload their list). */
  completed = $state(0);

  #nextKey = 1;
  /** Stops an encoding in progress when its job is cancelled. */
  #encodings = new Map<number, AbortController>();
  #running = false;
  #xhr: XMLHttpRequest | null = null;

  get busy(): boolean {
    return this.jobs.some((job) => !['done', 'error', 'cancelled'].includes(job.state));
  }

  /** The job shown in the side menu. */
  get current(): UploadJob | null {
    return this.jobs.find((job) => !['done', 'error', 'cancelled'].includes(job.state)) ?? null;
  }

  add(file: File, info: MediaInfo, video: { id: number; number: number; title: string }, playbackFile: File | null = null): UploadJob {
    const imageFile = info.playable ? file : playbackFile;
    // Encoding on the server: the queue of the scheduled task takes over after the upload.
    const encodeSource = serverEncoding() ? null : imageFile;
    this.jobs.push({
      imageFile,
      phase: 'master',
      encodeSource,
      encodeProgress: 0,
      stages: ['upload', ...(encodeSource ? (['images', 'encode', 'send'] as Stage[]) : imageFile ? (['images'] as Stage[]) : [])],
      stage: 'upload',
      partIndex: 0,
      partCount: 1,
      key: this.#nextKey++,
      file,
      name: file.name,
      info,
      videoId: video.id,
      videoLabel: `N° ${video.number} · ${video.title}`,
      uploadId: null,
      size: file.size,
      sent: 0,
      state: 'preparing',
      message: 'En attente…',
      speed: 0,
      eta: null,
      imagesProgress: 0,
      version: null,
    });
    void this.#runQueue();
    return this.jobs[this.jobs.length - 1]!;
  }

  /** Playback versions of an existing version (button "Générer la version de lecture"). */
  addPlayback(version: Version, video: { id: number; number: number; title: string }, source: File | string): UploadJob {
    this.jobs.push({
      key: this.#nextKey++,
      file: new File([], ''),
      name: `V${version.number}`,
      info: null as unknown as MediaInfo,
      videoId: video.id,
      videoLabel: `N° ${video.number} · ${video.title}`,
      uploadId: null,
      size: 0,
      sent: 0,
      state: 'preparing',
      message: 'En attente…',
      speed: 0,
      eta: null,
      imagesProgress: 0,
      version,
      phase: 'playback',
      encodeSource: source,
      imageFile: null,
      encodeProgress: 0,
      stages: ['encode', 'send'],
      stage: 'encode',
      partIndex: 0,
      partCount: 1,
    });
    void this.#runQueue();
    return this.jobs[this.jobs.length - 1]!;
  }

  /** Retries a failed job (same File object, resumes where the server stopped). */
  retry(job: UploadJob): void {
    job.state = 'preparing';
    job.message = 'En attente…';
    void this.#runQueue();
  }

  async cancel(job: UploadJob): Promise<void> {
    const wasRunning = job.state !== 'done' && job.state !== 'error';
    this.#encodings.get(job.key)?.abort();
    job.state = 'cancelled';
    job.message = job.stage === 'upload' ? 'Envoi annulé.' : 'Optimisation annulée : la version est importée, « Générer la version de lecture » la relance.';
    if (wasRunning) this.#xhr?.abort();
    if (job.uploadId !== null) {
      await api('DELETE', `/api/admin/uploads/${job.uploadId}`).catch(() => {});
    }
  }

  dismiss(job: UploadJob): void {
    this.jobs = this.jobs.filter((j) => j.key !== job.key);
  }

  async #runQueue(): Promise<void> {
    if (this.#running) return;
    this.#running = true;
    try {
      for (;;) {
        const job = this.jobs.find((j) => j.state === 'preparing');
        if (!job) break;
        await this.#run(job);
      }
    } finally {
      this.#running = false;
    }
  }

  async #run(job: UploadJob): Promise<void> {
    if (job.phase === 'playback') {
      await this.#playback(job);
      return;
    }
    try {
      job.stage = 'upload';
      job.message = 'Préparation…';
      const print = await fingerprint(fileSource(job.file));
      const upload = await api<PendingUpload>('POST', '/api/admin/uploads', {
        videoId: job.videoId,
        name: job.file.name,
        size: job.size,
        fingerprint: print,
      });
      job.uploadId = upload.id;
      await this.#sendChunks(job, upload.bytesReceived);
      if (this.#cancelled(job)) return;

      job.state = 'finishing';
      job.message = 'Création de la version…';
      job.eta = null;
      // An MXF without its H.264 file, encoded on the server: its metadata is read there (§5.7 bis).
      const unknown = job.info.container === 'mxf' && job.info.fpsNum === 0;
      const version = await api<Version>('POST', `/api/admin/uploads/${upload.id}/complete`, { meta: unknown ? null : toMeta(job.info) });
      job.version = version;
      job.uploadId = null;
      this.completed++;

      // Images from the master, or from its playback file when browsers cannot decode it (ProRes, MXF).
      const imageSource = job.imageFile;
      job.message = `V${version.number} importée.`;
      if (imageSource) {
        if (!job.stages.includes('images')) job.stages = [...job.stages, 'images'];
        job.stage = 'images';
        job.state = 'images';
        job.message = OPTIMISING;
        const url = URL.createObjectURL(imageSource);
        try {
          const images = await generateImages(url, job.info, (ratio) => (job.imagesProgress = ratio));
          job.version = await uploadImages(version.id, images);
          job.message = `V${version.number} importée.`;
        } catch (e) {
          job.message = `V${version.number} importée, mais les miniatures n’ont pas pu être générées (${(e as Error).message}).`;
        } finally {
          URL.revokeObjectURL(url);
        }
      }
      if (this.#cancelled(job)) return;
      if (job.encodeSource) {
        await this.#playback(job);
        return;
      }
      if (serverEncoding()) {
        job.message += ' Optimisation sur le serveur : vous pouvez fermer cette page.';
      } else if (!job.info.playable) {
        job.message += ' Joignez une version de lecture en H.264 (« Générer la version de lecture ») pour que les clients puissent la voir.';
      }
      job.state = 'done';
      this.completed++;
    } catch (e) {
      if (this.#cancelled(job)) return;
      job.state = 'error';
      job.message = e instanceof ApiError || e instanceof Error ? e.message : 'Une erreur est survenue.';
    }
  }

  /**
   * Playback versions (§5.7 bis): encoded here, sent like masters into the
   * version's hls/ folder, then the playlists make the version use them.
   */
  async #playback(job: UploadJob): Promise<void> {
    const version = job.version!;
    job.phase = 'playback';
    if (this.#cancelled(job)) return;
    let dispose: (() => Promise<void>) | null = null;
    try {
      job.stage = 'encode';
      job.state = 'encoding';
      job.encodeProgress = 0;
      job.message = OPTIMISING;
      await api('DELETE', `/api/admin/versions/${version.id}/hls`);
      // The encoder (mediabunny) is only loaded when a playback version is prepared.
      const { encodePlayback } = await import('./media/playback');
      const controller = new AbortController();
      this.#encodings.set(job.key, controller);
      const encoded = await encodePlayback(job.encodeSource!, (ratio) => (job.encodeProgress = ratio), controller.signal).finally(() =>
        this.#encodings.delete(job.key),
      );
      dispose = encoded.dispose;
      if (this.#cancelled(job)) return;

      job.stage = 'send';
      job.partCount = encoded.files.length;
      for (const [index, part] of encoded.files.entries()) {
        job.partIndex = index;
        job.file = part.file;
        job.size = part.file.size;
        job.sent = 0;
        const upload = await api<PendingUpload>('POST', '/api/admin/uploads', {
          videoId: job.videoId,
          versionId: version.id,
          target: `hls/${part.name}`,
          name: part.name,
          size: part.file.size,
          fingerprint: await fingerprint(fileSource(part.file)),
        });
        job.uploadId = upload.id;
        await this.#sendChunks(job, upload.bytesReceived);
        if (this.#cancelled(job)) return;
        await api('POST', `/api/admin/uploads/${upload.id}/complete`, {});
        job.uploadId = null;
      }
      job.version = await api<Version>('POST', `/api/admin/versions/${version.id}/hls`, { playlists: encoded.playlists });
      job.message = `V${version.number} importée et optimisée pour la lecture.`;
      job.state = 'done';
      this.completed++;
    } catch (e) {
      if (this.#cancelled(job)) return;
      job.state = 'error';
      job.message = `Version de lecture : ${e instanceof ApiError || e instanceof Error ? e.message : 'une erreur est survenue.'}`;
    } finally {
      await dispose?.();
    }
  }

  async #sendChunks(job: UploadJob, received: number): Promise<void> {
    let confirmed = received;
    let corrupt = 0;
    let backoff = 1000;
    const samples: { time: number; bytes: number }[] = [];
    const record = (bytes: number): void => {
      const now = performance.now();
      job.sent = bytes;
      samples.push({ time: now, bytes });
      while (samples.length > 2 && now - samples[0]!.time > SPEED_WINDOW_MS) samples.shift();
      const first = samples[0]!;
      const elapsed = (now - first.time) / 1000;
      if (elapsed > 1) {
        job.speed = (bytes - first.bytes) / elapsed;
        job.eta = job.speed > 0 ? (job.size - bytes) / job.speed : null;
      }
    };

    job.state = 'uploading';
    job.message = job.stage !== 'upload' ? OPTIMISING : confirmed > 0 ? 'Reprise de l’envoi…' : 'Envoi en cours…';
    record(confirmed);

    while (confirmed < job.size) {
      if (this.#cancelled(job)) return;
      const end = Math.min(job.size, confirmed + CHUNK_SIZE);
      const chunk = await job.file.slice(confirmed, end).arrayBuffer();
      const hash = await sha256Hex(chunk);
      const offset = confirmed;

      let result: { status: number; data: Record<string, unknown> | null };
      try {
        result = await this.#put(job.uploadId!, offset, chunk, hash, (loaded) => record(offset + loaded));
      } catch (e) {
        if (this.#cancelled(job) || !(e instanceof NetworkError)) throw e;
        confirmed = await this.#waitForServer(job, backoff);
        backoff = Math.min(backoff * 2, 30_000);
        record(confirmed);
        continue;
      }

      const serverReceived = typeof result.data?.bytesReceived === 'number' ? result.data.bytesReceived : null;
      if (result.status === 200 && serverReceived !== null) {
        confirmed = serverReceived;
        corrupt = 0;
        backoff = 1000;
        if (job.state !== 'uploading') {
          job.state = 'uploading';
          job.message = job.stage === 'upload' ? 'Envoi en cours…' : OPTIMISING;
        }
      } else if (result.status === 422 && ++corrupt <= MAX_CORRUPT_RETRIES) {
        // Chunk damaged on the way: send it again.
      } else if ((result.status === 409 || result.status === 400) && serverReceived !== null && serverReceived !== offset) {
        confirmed = serverReceived;
      } else if (result.status === 401) {
        notifyUnauthorized();
        throw new Error('Session expirée : reconnectez-vous, puis cliquez sur « Reprendre ».');
      } else if (result.status === 507) {
        throw new Error(typeof result.data?.error === 'string' ? result.data.error : 'Espace disque insuffisant.');
      } else if (result.status >= 500 || result.status === 0) {
        confirmed = await this.#waitForServer(job, backoff);
        backoff = Math.min(backoff * 2, 30_000);
      } else {
        throw new Error(typeof result.data?.error === 'string' ? result.data.error : `Erreur ${result.status} pendant l’envoi.`);
      }
      record(confirmed);
    }
  }

  /** Waits for the network, then asks the server how much it has. */
  async #waitForServer(job: UploadJob, delay: number): Promise<number> {
    job.state = 'offline';
    job.message = 'Connexion perdue : reprise automatique dès son retour…';
    job.speed = 0;
    job.eta = null;
    for (;;) {
      await new Promise<void>((resolve) => {
        const done = (): void => {
          clearTimeout(timer);
          window.removeEventListener('online', done);
          resolve();
        };
        const timer = setTimeout(done, delay);
        window.addEventListener('online', done);
      });
      if (this.#cancelled(job)) return job.sent;
      try {
        const status = await api<PendingUpload>('GET', `/api/admin/uploads/${job.uploadId}`);
        job.state = 'uploading';
        job.message = job.stage === 'upload' ? 'Reprise de l’envoi…' : OPTIMISING;
        return status.bytesReceived;
      } catch (e) {
        if (e instanceof ApiError && e.status !== 0 && e.status < 500) throw e;
        delay = Math.min(delay * 2, 30_000);
      }
    }
  }

  #put(
    uploadId: number,
    offset: number,
    body: ArrayBuffer,
    hash: string,
    onProgress: (loaded: number) => void,
  ): Promise<{ status: number; data: Record<string, unknown> | null }> {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      this.#xhr = xhr;
      xhr.open('PUT', `/api/admin/uploads/${uploadId}?offset=${offset}`);
      xhr.setRequestHeader('Content-Type', 'application/octet-stream');
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-CSRF-Token', getCsrfToken());
      xhr.setRequestHeader('X-Chunk-Sha256', hash);
      xhr.timeout = 15 * 60 * 1000;
      xhr.upload.onprogress = (event) => onProgress(event.loaded);
      xhr.onload = () => {
        let data: Record<string, unknown> | null = null;
        try {
          data = JSON.parse(xhr.responseText) as Record<string, unknown>;
        } catch {
          data = null;
        }
        resolve({ status: xhr.status, data });
      };
      xhr.onerror = () => reject(new NetworkError('network'));
      xhr.ontimeout = () => reject(new NetworkError('timeout'));
      xhr.onabort = () => reject(new NetworkError('aborted'));
      xhr.send(body);
    });
  }

  #cancelled(job: UploadJob): boolean {
    return job.state === 'cancelled';
  }
}

export const uploads = new UploadManager();

// Leaving the page would stop the upload: ask first.
window.addEventListener('beforeunload', (event) => {
  if (uploads.busy) event.preventDefault();
});
