<script lang="ts">
  import { api, ApiError } from '../../lib/api';
  import { formatBytes, formatDate, formatSeconds } from '../../lib/format';
  import { formatRate, frameToSeconds } from '../../lib/timecode';
  import ConfirmDialog from '../components/ConfirmDialog.svelte';
  import { generateImages } from '../media/images';
  import { uploadImages, versionFileUrl } from '../media/versions';
  import { TIMECODE_SOURCE, VERSION_STATUS } from '../status';
  import type { Version, VersionStatus, Video } from '../types';
  import { fileSource } from '../media/source';
  import { serverEncoding, uploads } from '../uploads.svelte';
  import MetadataDialog from './MetadataDialog.svelte';
  import PosterDialog from './PosterDialog.svelte';
  import { current } from '../current.svelte';
  import { isDemo } from '../session';

  let {
    version,
    video,
    onChange,
    onDeleted,
  }: { version: Version; video: Video; onChange: (version: Version) => void; onDeleted: () => void } = $props();

  // Public demo: no image nor playback file can be sent (§5.16).
  const demo = $derived(isDemo(current.session));

  // Playback versions (§5.7 bis): encoded in this browser.
  const playbackBusy = $derived(
    uploads.jobs.some((j) => j.phase === 'playback' && j.version?.id === version.id && !['done', 'error', 'cancelled'].includes(j.state)),
  );
  let playbackError = $state('');

  async function generatePlayback(): Promise<void> {
    playbackError = '';
    if (serverEncoding()) {
      // Into the queue of the scheduled task: nothing to keep open.
      try {
        onChange(await api<Version>('POST', `/api/admin/versions/${version.id}/encode`));
      } catch (e) {
        playbackError = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
      }
      return;
    }
    // The master on the server, read through HTTP ranges.
    uploads.addPlayback(version, video, versionFileUrl(version.id));
  }

  async function playbackFromFile(event: Event & { currentTarget: HTMLInputElement }): Promise<void> {
    const file = event.currentTarget.files?.[0];
    event.currentTarget.value = '';
    if (!file) return;
    playbackError = '';
    try {
      const { analyzeMp4 } = await import('../media/analyze');
      const info = await analyzeMp4(fileSource(file));
      const sameRate = version.fpsNum !== null && version.fpsDen !== null && info.fpsNum * version.fpsDen === version.fpsNum * info.fpsDen;
      if (!info.playable || !sameRate || Math.abs(info.durationFrames - (version.durationFrames ?? 0)) > 1) {
        throw new Error(`Ce fichier ne correspond pas à la V${version.number} (${info.durationFrames} images, ${version.durationFrames ?? '?'} attendues, même cadence) : exportez-le depuis la même séquence, en H.264.`);
      }
      uploads.addPlayback(version, video, file);
    } catch (e) {
      playbackError = (e as Error).message;
    }
  }

  const uid = $props.id();
  let busy = $state(false);
  let error = $state('');
  let imagesProgress = $state<number | null>(null);
  let confirmDelete = $state(false);
  let editMeta = $state(false);
  let editPoster = $state(false);

  const rate = $derived(
    version.fpsNum && version.fpsDen ? { num: version.fpsNum, den: version.fpsDen, dropFrame: version.dropFrame } : null,
  );

  async function patch(changes: Record<string, unknown>): Promise<void> {
    busy = true;
    error = '';
    try {
      onChange(await api<Version>('PATCH', `/api/admin/versions/${version.id}`, changes));
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
    } finally {
      busy = false;
    }
  }

  async function regenerateImages(): Promise<void> {
    if (!rate || !version.durationFrames) return;
    imagesProgress = 0;
    error = '';
    try {
      const images = await generateImages(
        versionFileUrl(version.id),
        { durationSeconds: frameToSeconds(version.durationFrames, rate), fpsNum: rate.num, fpsDen: rate.den },
        (ratio) => (imagesProgress = ratio),
      );
      onChange(await uploadImages(version.id, images));
    } catch (e) {
      error = (e as Error).message;
    } finally {
      imagesProgress = null;
    }
  }

  async function remove(): Promise<void> {
    await api('DELETE', `/api/admin/versions/${version.id}`);
    onDeleted();
  }
</script>

<article class="version" aria-labelledby="{uid}-title">
  <div class="version__poster">
    {#if version.posterUrl}
      <img src={version.posterUrl} alt="" />
    {:else}
      <span>Sans image</span>
    {/if}
  </div>

  <div class="version__body">
    <div class="version__head">
      <h3 class="version__title" id="{uid}-title">V{version.number}</h3>
      <span class="status status--{version.status}">{VERSION_STATUS[version.status]}</span>
      <span class="muted version__date">
        {version.isPublished && version.publishedAt ? `Publiée le ${formatDate(version.publishedAt)}` : `Importée le ${formatDate(version.createdAt)} · non publiée`}
      </span>
    </div>

    <p class="version__meta">
      {#if version.width}{version.width}×{version.height} · {/if}
      {#if rate}{formatRate(rate)} · {/if}
      {#if version.startTimecode}<span class="mono" title="Timecode de départ ({TIMECODE_SOURCE[version.timecodeSource ?? 'default']})">TC {version.startTimecode}</span> · {/if}
      {#if rate && version.durationFrames}{formatSeconds(frameToSeconds(version.durationFrames, rate))} · {/if}
      {#if version.size}{formatBytes(version.size)}{/if}
    </p>
    {#if version.originalName}<p class="mono muted version__file">{version.originalName}</p>{/if}
    <p class="version__playback">
      {#if version.encoding?.status === 'queued'}
        <span class="pill">Optimisation sur le serveur : en attente</span>
      {:else if version.encoding?.status === 'running'}
        <span class="pill">Optimisation sur le serveur… {Math.floor(version.encoding.progress * 100)} %</span>
      {:else if version.encoding?.status === 'failed'}
        <span class="pill pill--warn">Optimisation impossible</span>
        <span class="muted">{version.encoding.error}</span>
        <span class="can-edit"><button class="btn btn--small" type="button" onclick={generatePlayback}>Réessayer</button></span>
      {:else if version.hlsUrl}
        <span class="pill pill--ok">Optimisée pour la lecture (720p)</span>
      {:else if playbackBusy}
        <span class="pill">Optimisation en cours…</span>
      {:else}
        <span class="pill pill--warn">Sans version de lecture</span>
        {#if !demo}<span class="can-edit">
          {#if version.playable || serverEncoding()}
            <button class="btn btn--small" type="button" onclick={generatePlayback}>Générer la version de lecture</button>
          {:else}
            <label class="btn btn--small file-button">
              Joindre la version de lecture (H.264)
              <input class="visually-hidden" type="file" accept=".mp4,.mov,video/mp4,video/quicktime" onchange={playbackFromFile} />
            </label>
          {/if}
        </span>{/if}
      {/if}
    </p>
    {#if playbackError}<p class="form-error" role="alert">{playbackError}</p>{/if}

    {#if imagesProgress !== null}
      <p class="muted" role="status">Génération des miniatures… {Math.round(imagesProgress * 100)} %</p>
    {/if}
    {#if error}<p class="form-error" role="alert">{error}</p>{/if}

    {#if version.videoUrl}
      <div class="version__actions">
        <a class="btn btn--small" href="/api/admin/versions/{version.id}/download" download>
          Télécharger le master<span class="visually-hidden"> de la V{version.number}</span>{version.size ? ` (${formatBytes(version.size)})` : ''}
        </a>
      </div>
    {/if}
    <div class="version__actions can-edit">
      <button class="btn btn--small {version.isPublished ? '' : 'btn--primary'}" type="button" disabled={busy} onclick={() => patch({ published: !version.isPublished })}>
        {version.isPublished ? 'Dépublier' : 'Publier'}
      </button>
      <label class="visually-hidden" for="{uid}-status">Statut de la V{version.number}</label>
      <select
        class="select version__select"
        id="{uid}-status"
        value={version.status}
        disabled={busy}
        onchange={(e) => patch({ status: e.currentTarget.value as VersionStatus })}
      >
        {#each Object.entries(VERSION_STATUS) as [value, label] (value)}<option {value}>{label}</option>{/each}
      </select>
      <button class="btn btn--small" type="button" onclick={() => (editMeta = true)}>Métadonnées…</button>
      <!-- Drawn from the master in this browser: not for ProRes / MXF, whose images come from the server or the joined file. -->
      {#if version.playable && !demo}
        <button class="btn btn--small" type="button" onclick={() => (editPoster = true)}>Couverture…</button>
        <button class="btn btn--small" type="button" disabled={imagesProgress !== null} onclick={regenerateImages}>
          {version.spriteUrl ? 'Régénérer les miniatures' : 'Générer les miniatures'}
        </button>
      {/if}
      <button class="btn btn--small can-delete" type="button" onclick={() => (confirmDelete = true)}>Supprimer…</button>
    </div>
  </div>
</article>

<MetadataDialog bind:open={editMeta} {version} onSaved={onChange} />
<PosterDialog bind:open={editPoster} {version} onSaved={onChange} />
<ConfirmDialog bind:open={confirmDelete} title="Supprimer la V{version.number} ?" confirmLabel="Supprimer définitivement" danger onConfirm={remove}>
  <p>Le fichier master, ses images et toutes ses révisions seront supprimés. Cette action est irréversible.</p>
</ConfirmDialog>

<style>
  .version__playback {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-top: 6px;
  }

  .file-button {
    cursor: pointer;
  }

  .version {
    display: flex;
    flex-wrap: wrap;
    gap: 16px 20px;
    padding: 16px 0;
    border-top: 1px solid var(--border);
  }

  .version__poster {
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 176px;
    aspect-ratio: 16 / 9;
    overflow: hidden;
    border-radius: var(--radius-sm);
    background: var(--surface-3);
    font-size: 12px;
    color: var(--text-muted);
  }

  .version__poster img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: #000;
  }

  .version__body {
    flex: 1 1 320px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
  }

  .version__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 12px;
  }

  .version__title {
    font-family: var(--font-display);
    font-weight: 400;
    font-size: 26px;
    line-height: 1;
  }

  .version__date {
    font-size: 12.5px;
  }

  .version__meta {
    font-size: 13px;
    color: var(--text-soft);
  }

  .version__file {
    overflow-wrap: anywhere;
    font-size: 12px;
  }

  .version__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 4px;
  }

  .version__select {
    min-height: 36px;
    font-size: 12.5px;
  }
</style>
