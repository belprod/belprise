<script lang="ts">
  import { tick } from 'svelte';
  import Player from '../player/Player.svelte';
  import type { CollectionItem } from './types';

  /** A selection for prospects (§5.9): watch only, no revisions, no download. */
  let { collection, videos }: { collection: { title: string; introduction: string }; videos: CollectionItem[] } = $props();

  let current = $state<number | null>(null);
  let heading = $state<HTMLHeadingElement | null>(null);
  const playing = $derived(current === null ? null : (videos[current] ?? null));

  async function open(index: number): Promise<void> {
    current = index;
    await tick();
    heading?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    heading?.focus({ preventScroll: true });
  }
</script>

<div class="collection">
  <header class="hero">
    <p class="client-eyebrow">// {videos.length} vidéo{videos.length > 1 ? 's' : ''}</p>
    <h1 class="client-title hero__title">{collection.title}</h1>
    {#if collection.introduction}
      <p class="hero__text">{collection.introduction}</p>
    {/if}
  </header>

  {#if playing}
    <section class="stage" aria-labelledby="stage-title">
      <h2 class="stage__title" id="stage-title" tabindex="-1" bind:this={heading}>{playing.title}</h2>
      {#key current}
        <Player
          src={playing.version.videoUrl}
          hls={playing.version.hlsUrl ?? null}
          poster={playing.version.posterUrl}
          sprite={playing.version.sprite}
          rate={{ num: playing.version.fpsNum, den: playing.version.fpsDen, dropFrame: playing.version.dropFrame }}
          startFrame={playing.version.startFrame}
          durationFrames={playing.version.durationFrames}
        />
      {/key}
    </section>
  {/if}

  {#if videos.length === 0}
    <div class="client-placeholder">Aucune vidéo pour le moment.</div>
  {:else}
    <ul class="cards">
      {#each videos as video, index (index)}
        <li>
          <button class="vcard" class:vcard--current={index === current} type="button" aria-current={index === current ? 'true' : undefined} onclick={() => open(index)}>
            <span class="vcard__thumb">
              {#if video.thumbUrl}
                <img
                  src={video.thumbUrl}
                  alt=""
                  width="640"
                  height="360"
                  decoding="async"
                  loading={index < 3 ? 'eager' : 'lazy'}
                  fetchpriority={index === 0 ? 'high' : 'auto'}
                />
              {/if}
              <span class="vcard__play" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13l10.5-6.5z"></path></svg>
              </span>
              <span class="vcard__badge">{video.duration}</span>
            </span>
            <span class="vcard__title">{video.title}<span class="visually-hidden">, {video.duration}</span></span>
          </button>
        </li>
      {/each}
    </ul>
  {/if}
</div>

<style>
  .collection {
    display: flex;
    flex-direction: column;
    gap: 40px;
  }

  .hero {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .hero__title {
    font-size: clamp(40px, 7vw, 64px) !important;
    line-height: 1 !important;
  }

  .hero__text {
    max-width: 640px;
    color: var(--muted);
    font-size: 15px;
    white-space: pre-line;
  }

  .stage {
    display: flex;
    flex-direction: column;
    gap: 14px;
    scroll-margin-top: 16px;
  }

  /* The whole player, controls included, fits in the window. */
  .stage :global(.player) {
    width: min(100%, calc((100vh - 200px) * 16 / 9));
    align-self: center;
  }

  .stage__title {
    font-family: var(--font-display);
    font-weight: 400;
    font-size: 30px;
    line-height: 1.1;
    scroll-margin-top: 16px;
  }

  .stage__title:focus {
    outline: none;
  }

  .cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr));
    gap: 24px;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .vcard {
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: 100%;
    padding: 0;
    border: none;
    border-radius: var(--radius-md);
    background: none;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
  }

  .vcard__thumb {
    position: relative;
    display: block;
    overflow: hidden;
    aspect-ratio: 16 / 9;
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    background: #000;
  }

  .vcard--current .vcard__thumb {
    border-color: var(--client-accent);
    box-shadow: 0 0 0 1px var(--client-accent);
  }

  .vcard__thumb img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    transition: transform 300ms ease;
  }

  .vcard:hover .vcard__thumb img {
    transform: scale(1.03);
  }

  .vcard__play {
    position: absolute;
    top: 50%;
    left: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: rgb(10 10 11 / 0.72);
    color: #fafafa;
    opacity: 0;
    transform: translate(-50%, -50%) scale(0.9);
    transition: opacity 200ms ease, transform 200ms ease;
  }

  .vcard:hover .vcard__play,
  .vcard:focus-visible .vcard__play {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1);
  }

  .vcard__badge {
    position: absolute;
    right: 10px;
    bottom: 10px;
    padding: 3px 7px;
    border-radius: 5px;
    background: rgb(10 10 11 / 0.8);
    color: #fafafa;
    font-family: var(--font-mono);
    font-size: 11px;
  }

  .vcard__title {
    font-weight: 600;
    font-size: 15px;
  }

  .vcard:hover .vcard__title {
    text-decoration: underline;
    text-underline-offset: 3px;
  }

  @media (prefers-reduced-motion: reduce) {
    .vcard__thumb img,
    .vcard__play {
      transition: none;
    }
  }
</style>
