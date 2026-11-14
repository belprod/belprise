<script lang="ts">
  import Player from '../player/Player.svelte';
  import type { PublicVersion, VideoInfo } from './types';

  let { video, versions }: { video: VideoInfo; versions: PublicVersion[] } = $props();

  const shown = $derived(versions[0] ?? null);
</script>

<div class="view">
  <h1 class="client-title">{video.title}</h1>
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
    <div class="client-placeholder">Cette vidéo n’est pas encore disponible.</div>
  {/if}
</div>

<style>
  .view {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }
</style>
