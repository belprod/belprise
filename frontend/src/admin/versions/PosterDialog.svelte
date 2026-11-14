<script lang="ts">
  import { framesToTimecode, frameToSeconds, secondsToFrame } from '../../lib/timecode';
  import ConfirmDialog from '../components/ConfirmDialog.svelte';
  import { capturePoster } from '../media/images';
  import { uploadImages, versionFileUrl } from '../media/versions';
  import type { Version } from '../types';

  let { open = $bindable(false), version, onSaved }: { open?: boolean; version: Version; onSaved: (v: Version) => void } = $props();

  let video = $state<HTMLVideoElement | null>(null);
  let time = $state(0);
  const rate = $derived({ num: version.fpsNum ?? 25, den: version.fpsDen ?? 1, dropFrame: version.dropFrame });
  const timecode = $derived(framesToTimecode(version.startFrame + secondsToFrame(time, rate), rate));

  function ready(): void {
    if (video && version.posterFrame !== null) {
      video.currentTime = frameToSeconds(version.posterFrame, rate);
    }
  }

  async function save(): Promise<void> {
    if (!video) return;
    video.pause();
    const poster = await capturePoster(video);
    const thumb = await capturePoster(video, 640, 0.8);
    onSaved(await uploadImages(version.id, { poster, thumb, posterFrame: secondsToFrame(video.currentTime, rate) }));
  }
</script>

<ConfirmDialog bind:open title="Image de couverture" confirmLabel="Utiliser cette image" onConfirm={save}>
  <p>Placez la vidéo sur l’image voulue, puis validez.</p>
  {#if open}
    <!-- svelte-ignore a11y_media_has_caption -->
    <video
      class="poster-video"
      src={versionFileUrl(version.id)}
      controls
      muted
      preload="metadata"
      bind:this={video}
      bind:currentTime={time}
      onloadedmetadata={ready}
    ></video>
    <p class="mono">{timecode}</p>
  {/if}
</ConfirmDialog>

<style>
  .poster-video {
    display: block;
    width: 100%;
    margin: 12px 0 8px;
    border-radius: var(--radius-md);
    background: #000;
    aspect-ratio: 16 / 9;
  }
</style>
