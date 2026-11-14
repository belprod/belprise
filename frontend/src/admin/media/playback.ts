/**
 * Playback version (§5.7 bis), encoded in the admin's browser: 720p at
 * 3 Mbit/s (the master's height when it is smaller), H.264 + AAC, same
 * frames as the master, in HLS with one file (segments read through byte
 * ranges). Nothing is computed on
 * the server. Files are written to the browser's private storage (OPFS)
 * while encoding, then sent like a master.
 */
import {
  ALL_FORMATS,
  BlobSource,
  BufferTarget,
  CmafOutputFormat,
  Conversion,
  HlsOutputFormat,
  Input,
  Output,
  PathedTarget,
  StreamTarget,
  UrlSource,
  canEncodeVideo,
  type ConversionVideoOptions,
  type Target,
} from 'mediabunny';

export interface Quality {
  height: number;
  bitrate: number;
}

/** The quality of the specification. */
export const QUALITIES: Quality[] = [{ height: 720, bitrate: 3_000_000 }];
const AUDIO_BITRATE = 128_000;
const SEGMENT_SECONDS = 2;

export class PlaybackError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'PlaybackError';
  }
}

/**
 * Qualities to produce for a master of this height: those not above it; a
 * master under 720p gets one at its own height (even number).
 */
export function qualitiesFor(sourceHeight: number): Quality[] {
  const fitting = QUALITIES.filter((q) => q.height <= sourceHeight);
  if (fitting.length > 0) return fitting;
  return [{ height: Math.max(2, sourceHeight - (sourceHeight % 2)), bitrate: QUALITIES[0]!.bitrate }];
}

export interface PlaybackFiles {
  /** name → content (master.m3u8 and one playlist per quality) */
  playlists: Record<string, string>;
  /** One file per quality, in the browser's private storage */
  files: { name: string; file: File }[];
  /** Frees the private storage once the files are sent. */
  dispose(): Promise<void>;
}

/**
 * Encodes the playback versions of a master (a local File, or the server's
 * copy through HTTP ranges).
 */
export async function encodePlayback(
  source: File | string,
  onProgress: (ratio: number) => void,
  signal?: AbortSignal,
): Promise<PlaybackFiles> {
  if (typeof navigator.storage?.getDirectory !== 'function') {
    throw new PlaybackError('Ce navigateur ne peut pas préparer la version de lecture : utilisez Chrome, Edge ou Safari à jour.');
  }
  const input = new Input({
    formats: ALL_FORMATS,
    source: typeof source === 'string' ? new UrlSource(source, { requestInit: { credentials: 'same-origin' } }) : new BlobSource(source),
  });
  const track = await input.getPrimaryVideoTrack();
  if (!track) {
    throw new PlaybackError('Ce fichier ne contient aucune piste vidéo.');
  }
  const qualities = qualitiesFor(track.displayHeight);
  for (const quality of qualities) {
    const width = Math.round((track.displayWidth * quality.height) / track.displayHeight / 2) * 2;
    if (!(await canEncodeVideo('avc', { width, height: quality.height, bitrate: quality.bitrate }))) {
      throw new PlaybackError('Ce navigateur ne sait pas encoder en H.264 : utilisez Chrome, Edge ou Safari à jour.');
    }
  }

  const folder = await navigator.storage.getDirectory();
  const prefix = `belprise-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}-`;
  const written: { name: string; handle: FileSystemFileHandle; writable: FileSystemWritableFileStream }[] = [];
  const playlists: Record<string, string> = {};

  const output = new Output({
    format: new HlsOutputFormat({
      segmentFormat: new CmafOutputFormat(),
      targetDuration: SEGMENT_SECONDS,
      singleFilePerPlaylist: true,
      onMaster: (content) => (playlists['master.m3u8'] = content),
      onPlaylist: (content, info) => (playlists[`playlist-${info.n}.m3u8`] = content),
    }),
    target: new PathedTarget('master.m3u8', async (request): Promise<Target> => {
      const name = request.path.split('/').pop() ?? request.path;
      if (name.endsWith('.m3u8')) {
        return new BufferTarget(); // contents come through onMaster / onPlaylist
      }
      const handle = await folder.getFileHandle(prefix + name, { create: true });
      const writable = await handle.createWritable();
      written.push({ name, handle, writable });
      // The private file accepts { type: 'write', data, position } chunks as they are.
      return new StreamTarget(writable as unknown as WritableStream);
    }),
  });

  const conversion = await Conversion.init({
    input,
    output,
    tracks: 'primary',
    video: qualities.map(
      (q): ConversionVideoOptions => ({
        height: q.height,
        codec: 'avc',
        bitrate: q.bitrate,
        keyFrameInterval: SEGMENT_SECONDS,
        forceTranscode: true,
      }),
    ),
    audio: { codec: 'aac', bitrate: AUDIO_BITRATE },
  });
  const discarded = conversion.discardedTracks.find((d) => d.track.type === 'audio' || d.track.type === 'video');
  if (!conversion.isValid || discarded) {
    throw new PlaybackError(
      discarded?.track.type === 'audio'
        ? 'Le son de ce fichier ne peut pas être converti dans ce navigateur : joignez une version de lecture en H.264 / AAC.'
        : 'La vidéo de ce fichier ne peut pas être décodée dans ce navigateur : joignez une version de lecture en H.264.',
    );
  }
  conversion.onProgress = (progress) => onProgress(progress);
  const abort = (): void => void conversion.cancel();
  signal?.addEventListener('abort', abort);
  try {
    await conversion.execute();
  } finally {
    signal?.removeEventListener('abort', abort);
  }

  const files: { name: string; file: File }[] = [];
  for (const entry of written) {
    // Same bytes under the name the server expects (it is part of the upload fingerprint).
    files.push({ name: entry.name, file: new File([await entry.handle.getFile()], entry.name, { type: 'video/mp4' }) });
  }
  if (!playlists['master.m3u8'] || files.length === 0) {
    throw new PlaybackError('La version de lecture est incomplète.');
  }

  return {
    playlists,
    files,
    async dispose() {
      for (const entry of written) {
        await folder.removeEntry(prefix + entry.name).catch(() => {});
      }
    },
  };
}
