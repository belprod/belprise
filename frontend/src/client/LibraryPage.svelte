<script lang="ts">
  import { formatBytes, formatDate } from '../lib/format';
  import Player from '../player/Player.svelte';
  import type { PublicVersion, VideoInfo } from './types';

  /** An archived video (§5.3): the kept version, to watch and download. No revisions. */
  let {
    project,
    video,
    versions,
    archivedAt,
  }: { project: { name: string }; video: VideoInfo; versions: PublicVersion[]; archivedAt: string } = $props();

  const shown = $derived(versions[0] ?? null);
  const ref = window.location.pathname.replace(/^\/v\//, '');
</script>

<div class="library">
  <p class="client-eyebrow">// Bibliothèque · {project.name}</p>
  <div class="library__head">
    <h1 class="client-title">{video.title}</h1>
    {#if shown && video.downloadAllowed}
      <a class="download" href="/api/v/{ref}/versions/{shown.id}/download" download>
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11"></path><path d="M7 10l5 5 5-5"></path><path d="M5 20h14"></path></svg>
        Télécharger le master
        <span class="visually-hidden">(V{shown.number}{shown.size ? `, ${formatBytes(shown.size)}` : ''})</span>
      </a>
    {/if}
  </div>
  <p class="library__meta">N° {video.number}{shown ? ` · V${shown.number}` : ''} · archivée le {formatDate(archivedAt)}</p>
  {#if shown}
    <Player
      src={shown.videoUrl}
      hls={shown.hlsUrl ?? null}
      poster={shown.posterUrl}
      sprite={shown.sprite}
      rate={{ num: shown.fpsNum, den: shown.fpsDen, dropFrame: shown.dropFrame }}
      startFrame={shown.startFrame}
      durationFrames={shown.durationFrames}
    />
  {:else}
    <div class="client-placeholder">Cette vidéo n’est plus disponible.</div>
  {/if}
</div>

<style>
  .library {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .library__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
  }

  .library__meta {
    margin-bottom: 8px;
    color: var(--muted);
    font-family: var(--font-mono);
    font-size: 12.5px;
  }

  .download {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: var(--hit);
    padding: 0 18px;
    border: 1px solid var(--line);
    border-radius: var(--radius-pill);
    background: color-mix(in srgb, var(--client-text) 5%, var(--client-bg));
    color: var(--client-text);
    font-size: 13.5px;
    font-weight: 500;
    text-decoration: none;
  }

  .download:hover {
    border-color: var(--client-text);
  }
</style>
