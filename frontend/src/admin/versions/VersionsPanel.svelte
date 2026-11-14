<script lang="ts">
  import { api } from '../../lib/api';
  import { formatBytes } from '../../lib/format';
  import type { MediaInfo } from '../media/analyze';
  import { MASTER_ACCEPT } from '../media/formats';
  import { fileSource } from '../media/source';
  import { listVersions } from '../media/versions';
  import type { PendingUpload, Version, Video } from '../types';
  import { serverEncoding, uploads } from '../uploads.svelte';
  import JobProgress from './JobProgress.svelte';
  import MediaSummary from './MediaSummary.svelte';
  import VersionRow from './VersionRow.svelte';

  let { video }: { video: Video } = $props();

  const uid = $props.id();
  let versions = $state<Version[] | null>(null);
  let pending = $state<PendingUpload[]>([]);

  // Local file chosen, being analysed or awaiting confirmation
  let chosen = $state<File | null>(null);
  let analysing = $state(false);
  let info = $state<MediaInfo | null>(null);
  let analysisError = $state('');
  // ProRes / MXF master: the H.264 file joined to it for playback (§5.7)
  let playbackFile = $state<File | null>(null);
  let playbackInfo = $state<MediaInfo | null>(null);
  let merged = $state<MediaInfo | null>(null);

  const nextNumber = $derived((versions?.[0]?.number ?? 0) + 1);
  const jobs = $derived(uploads.jobs.filter((job) => job.videoId === video.id));
  const interrupted = $derived(pending.filter((p) => !jobs.some((job) => job.uploadId === p.id && job.state !== 'error')));

  async function load(): Promise<void> {
    const [list, waiting] = await Promise.all([listVersions(video.id), api<PendingUpload[]>('GET', `/api/admin/uploads?video=${video.id}`)]);
    versions = list;
    pending = waiting;
  }

  $effect(() => {
    void uploads.completed; // reload when an upload creates a version
    load().catch(() => (analysisError = 'Chargement des versions impossible.'));
  });

  // Encoding on the server (§5.7 bis): the list follows its progress while it runs.
  const encodingRuns = $derived(versions?.some((v) => v.encoding && v.encoding.status !== 'failed') ?? false);
  $effect(() => {
    if (!encodingRuns) return;
    const timer = setInterval(() => void load().catch(() => {}), 10_000);
    return () => clearInterval(timer);
  });

  async function choose(event: Event & { currentTarget: HTMLInputElement }): Promise<void> {
    const file = event.currentTarget.files?.[0];
    event.currentTarget.value = '';
    if (file) await analyse(file);
  }

  /** A master dropped on the panel becomes the next version, like one chosen with the button. */
  let dragging = $state(false);
  function onDrop(event: DragEvent): void {
    event.preventDefault();
    dragging = false;
    const file = event.dataTransfer?.files?.[0];
    if (file) void analyse(file);
  }

  async function analyse(file: File): Promise<void> {
    chosen = file;
    info = null;
    playbackFile = playbackInfo = merged = null;
    analysisError = '';
    analysing = true;
    try {
      // mp4box.js is only loaded when a file is analysed.
      const { analyzeMaster } = await import('../media/analyze');
      info = await analyzeMaster(fileSource(file));
    } catch (e) {
      analysisError = (e as Error).name === 'AnalysisError' ? (e as Error).message : `Analyse impossible : ${(e as Error).message}`;
    } finally {
      analysing = false;
    }
  }

  async function choosePlayback(event: Event & { currentTarget: HTMLInputElement }): Promise<void> {
    const file = event.currentTarget.files?.[0];
    event.currentTarget.value = '';
    if (!file || !info) return;
    analysisError = '';
    analysing = true;
    try {
      const { analyzeMp4, withPlaybackFile } = await import('../media/analyze');
      const analysed = await analyzeMp4(fileSource(file));
      merged = withPlaybackFile(info, analysed);
      playbackFile = file;
      playbackInfo = analysed;
    } catch (e) {
      analysisError = (e as Error).name === 'AnalysisError' ? (e as Error).message : `Analyse impossible : ${(e as Error).message}`;
    } finally {
      analysing = false;
    }
  }

  // ProRes / MXF: the joined H.264 file is needed, except when the server encodes (§5.7 bis).
  const needsPlayback = $derived(info !== null && !info.playable && !serverEncoding());

  function send(): void {
    if (!chosen || !info) return;
    if (needsPlayback && (!merged || !playbackFile)) return;
    uploads.add(chosen, merged ?? info, video, playbackFile);
    chosen = null;
    playbackFile = playbackInfo = merged = null;
    info = null;
  }

  async function abandon(upload: PendingUpload): Promise<void> {
    await api('DELETE', `/api/admin/uploads/${upload.id}`);
    pending = pending.filter((p) => p.id !== upload.id);
  }
</script>

<section
  class="panel"
  class:panel--drop={dragging}
  aria-labelledby="{uid}-title"
  ondragover={(e) => {
    if (e.dataTransfer?.types.includes('Files')) {
      e.preventDefault();
      dragging = true;
    }
  }}
  ondragleave={(e) => {
    if (e.currentTarget === e.target) dragging = false;
  }}
  ondrop={onDrop}
>
  <div class="panel__head">
    <h2 class="panel__title" id="{uid}-title">Versions</h2>
    <span class="muted">{versions ? `${versions.length} version${versions.length > 1 ? 's' : ''}` : ''}</span>
  </div>

  <div class="new can-edit">
    <label class="btn btn--primary btn--icon-plus file-button" class:file-button--busy={analysing}>
      Envoyer un master
      <input class="visually-hidden" type="file" accept={MASTER_ACCEPT} disabled={analysing} onchange={choose} />
    </label>
  </div>
  <p class="panel__text">
    MP4 ou MOV (H.264, HEVC, ProRes) ou MXF, 4K au maximum — ou glissez le fichier ici. {serverEncoding()
      ? 'Après l’envoi, le serveur optimise la vidéo pour la lecture : vous pouvez fermer la page.'
      : 'Après l’envoi, la vidéo est optimisée pour la lecture dans ce navigateur : laissez la page ouverte jusqu’à la fin.'} La nouvelle version sera la V{nextNumber}, « En cours » (invisible pour le client) jusqu’à
    sa publication.
  </p>

  {#if analysing}
    <p class="muted" role="status">Analyse de {chosen?.name}…</p>
  {/if}
  {#if analysisError}
    <p class="form-error" role="alert">{analysisError}</p>
  {/if}
  {#if info}
    <div class="box">
      <MediaSummary info={merged ?? info} />
      {#if !info.playable}
        <div class="playback">
          {#if needsPlayback}
            <p>
              <strong>{info.codecLabel} : joignez une version de lecture.</strong> Les navigateurs ne lisent pas ce format : exportez
              la même séquence en H.264 (.mp4 ou .mov, mêmes images). Elle sert à la revue et aux miniatures ; le master reste
              téléchargeable{info.container === 'mxf' ? ', et ses informations (cadence, timecode) sont reprises de ce fichier' : ''}.
            </p>
          {:else}
            <p>
              <strong>{info.codecLabel} : le serveur préparera la version de lecture et les miniatures{info.container === 'mxf'
                ? ', et lira la cadence, la durée et le timecode du fichier'
                : ''}.</strong> Facultatif : joignez la même séquence en H.264 pour avoir tout de suite l’image de couverture.
            </p>
          {/if}
          <label class="btn btn--small file-button" class:file-button--busy={analysing}>
            {playbackFile ? `Version de lecture : ${playbackFile.name} — changer` : 'Choisir la version de lecture'}
            <input class="visually-hidden" type="file" accept=".mp4,.mov,video/mp4,video/quicktime" disabled={analysing} onchange={choosePlayback} />
          </label>
        </div>
      {/if}
      <div class="form-actions">
        <button class="btn btn--primary" type="button" disabled={needsPlayback && !merged} onclick={send}>Envoyer comme V{nextNumber}</button>
        <button class="btn" type="button" onclick={() => (info = null)}>Annuler</button>
      </div>
    </div>
  {/if}


  {#each interrupted as upload (upload.id)}
    <div class="box box--warn">
      <p>
        <strong>Envoi interrompu :</strong> <span class="mono">{upload.name}</span> — {formatBytes(upload.bytesReceived)} / {formatBytes(upload.size)}
        ({Math.floor((upload.bytesReceived / upload.size) * 100)} %).
      </p>
      <p class="muted">Sélectionnez à nouveau ce fichier avec « Envoyer un master » : l’envoi reprendra où il s’est arrêté.</p>
      <div><button class="btn btn--small" type="button" onclick={() => abandon(upload)}>Abandonner cet envoi</button></div>
    </div>
  {/each}

  {#each jobs as job (job.key)}
    <div class="box"><JobProgress {job} /></div>
  {/each}

  {#if versions === null}
    <p class="loading">Chargement…</p>
  {:else if versions.length === 0}
    <p class="panel__text">Aucune version pour l’instant.</p>
  {:else}
    <div>
      {#each versions as version, i (version.id)}
        <VersionRow {version} {video} onChange={(v) => (versions![i] = v)} onDeleted={load} />
      {/each}
    </div>
  {/if}
</section>

<style>
  .new {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
  }

  .panel--drop {
    border-color: var(--accent);
    box-shadow: 0 0 0 1px var(--accent);
  }

  .file-button {
    cursor: pointer;
  }

  .file-button:focus-within {
    box-shadow: var(--focus-ring);
  }

  .file-button--busy {
    opacity: 0.55;
  }

  .box {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 16px 18px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface-2);
    font-size: 13.5px;
  }

  .playback {
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: flex-start;
  }

  .box--warn {
    gap: 6px;
    border-color: rgb(255 214 10 / 0.35);
  }
</style>
