<script lang="ts" module>
  export interface PlayerMarker {
    id: number | string;
    /** Position in frames from the start of the video */
    frameIn: number;
    frameOut: number | null;
    color: string;
    label: string;
  }
</script>

<script lang="ts">
  import { onMount } from 'svelte';
  import { framesToTimecode, secondsToFrame, type FrameRate } from '../lib/timecode';
  import {
    actionForKey,
    clamp,
    frameTime,
    isTypingTarget,
    nextShuttle,
    nextSpeed,
    spriteTile,
    type PlayerAction,
    type SpriteInfo,
  } from './logic';

  let {
    src,
    hls = null,
    poster = null,
    sprite = null,
    rate,
    startFrame,
    durationFrames,
    markers = null,
    overlay = null,
    shortcuts = true,
    initialFrame = 0,
    onFrame,
    onMarks,
    onComment,
    onMarker,
  }: {
    src: string;
    /** HLS playback versions (§5.7 bis); src (the master) when absent or not playable here */
    hls?: string | null;
    poster?: string | null;
    sprite?: SpriteInfo | null;
    rate: FrameRate;
    startFrame: number;
    durationFrames: number;
    /** null: no marker row (view link); an array keeps the row's place while comments load */
    markers?: PlayerMarker[] | null;
    /** Comment shown over the picture (§5.1) */
    overlay?: { timecode: string; text: string } | null;
    /** Page-wide §5.1 keyboard shortcuts */
    shortcuts?: boolean;
    initialFrame?: number;
    /** Current position (frames from the start), on every displayed frame */
    onFrame?: (frame: number) => void;
    /** I / O points changed */
    onMarks?: (markIn: number | null, markOut: number | null) => void;
    /** C pressed */
    onComment?: (frame: number) => void;
    onMarker?: (marker: PlayerMarker) => void;
  } = $props();

  const uid = $props.id();
  const VOLUME_KEY = 'belprise.volume';
  const PREVIEW_SCALE = 0.625; // 256×144 tiles shown at 160×90

  let root: HTMLDivElement;
  let video: HTMLVideoElement;

  // HLS: native on Safari / iPhone; elsewhere hls.js, loaded only when needed.
  // Without HLS (or if neither works) the master itself is played.
  let nativeHls = $state(false);
  let hlsFailed = $state(false);
  $effect(() => {
    const url = hls;
    if (!url || !video || hlsFailed) return;
    if (video.canPlayType('application/vnd.apple.mpegurl') !== '') {
      nativeHls = true;
      return;
    }
    nativeHls = false;
    let destroyed = false;
    let instance: { destroy(): void } | null = null;
    void import('hls.js')
      .then(({ default: Hls }) => {
        if (destroyed) return;
        if (!Hls.isSupported()) {
          hlsFailed = true;
          return;
        }
        const player = new Hls({ capLevelToPlayerSize: true, startLevel: -1 });
        player.on(Hls.Events.ERROR, (_event, data) => {
          if (data.fatal) {
            player.destroy();
            hlsFailed = true; // the master, as before
          }
        });
        player.loadSource(url);
        player.attachMedia(video);
        instance = player;
      })
      .catch(() => (hlsFailed = true));
    return () => {
      destroyed = true;
      instance?.destroy();
    };
  });
  /** What the video element loads itself: HLS natively, nothing when hls.js drives it, else the master. */
  const directSrc = $derived(hls && !hlsFailed ? (nativeHls ? hls : undefined) : src);
  let track: HTMLDivElement;

  let time = $state(0);
  let paused = $state(true);
  let waiting = $state(false);
  let started = $state(false);
  let error = $state('');
  let buffered = $state(0);
  let speed = $state(1);
  let shuttle = $state(0);
  let volume = $state(1);
  let muted = $state(false);
  let fullscreen = $state(false);
  let markIn = $state<number | null>(null);
  let markOut = $state<number | null>(null);
  let hover = $state<{ x: number; frame: number } | null>(null);
  let hoveredMarker = $state<PlayerMarker | null>(null);
  let scrubbing = false;
  /** Playing (forward) when the scrub started: playback resumes on release. */
  let resumeAfterScrub = false;
  let reverseTimer: ReturnType<typeof setInterval> | undefined;

  const lastFrame = $derived(Math.max(0, durationFrames - 1));
  const frame = $derived(clamp(secondsToFrame(time, rate), 0, lastFrame));
  const progress = $derived(durationFrames > 0 ? (frame / durationFrames) * 100 : 0);
  const timecode = $derived(framesToTimecode(startFrame + frame, rate));
  const endTimecode = $derived(framesToTimecode(startFrame + durationFrames, rate));
  const shuttleLabel = $derived(shuttle < 0 ? `◀ ${-shuttle}×` : shuttle > 1 ? `${shuttle}×` : `${speed}×`);
  const percent = (f: number): number => (durationFrames > 0 ? (f / durationFrames) * 100 : 0);

  $effect(() => onFrame?.(frame));

  onMount(() => {
    try {
      const saved = localStorage.getItem(VOLUME_KEY);
      if (saved !== null) volume = clamp(Number(saved), 0, 1);
    } catch {
      // storage unavailable: default volume
    }
    video.volume = volume;
    if (initialFrame > 0) seekFrame(initialFrame);
    startFrameLoop();

    const onFullscreen = (): void => {
      fullscreen = document.fullscreenElement === root;
    };
    document.addEventListener('fullscreenchange', onFullscreen);
    return () => {
      document.removeEventListener('fullscreenchange', onFullscreen);
      stopReverse();
    };
  });

  /** Follows the frame actually displayed (requestVideoFrameCallback when available). */
  function startFrameLoop(): void {
    if ('requestVideoFrameCallback' in HTMLVideoElement.prototype) {
      const onVideoFrame = (_now: number, meta: VideoFrameCallbackMetadata): void => {
        time = meta.mediaTime;
        video.requestVideoFrameCallback(onVideoFrame);
      };
      video.requestVideoFrameCallback(onVideoFrame);
    } else {
      const tick = (): void => {
        if (!video.paused) time = video.currentTime;
        requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    }
  }

  function seekFrame(target: number): void {
    const f = clamp(Math.round(target), 0, lastFrame);
    time = frameTime(f, rate);
    video.currentTime = time;
  }

  function play(): void {
    stopReverse();
    started = true;
    video.playbackRate = shuttle > 1 ? shuttle : speed;
    void video.play().catch(() => {});
  }

  function pause(): void {
    stopReverse();
    shuttle = 0;
    video.pause();
    // Settle on a whole frame so the image matches the timecode.
    seekFrame(secondsToFrame(video.currentTime, rate));
  }

  function toggle(): void {
    if (video.paused && shuttle >= 0) {
      shuttle = 0;
      play();
    } else {
      pause();
    }
  }

  function step(frames: number): void {
    if (!video.paused || shuttle !== 0) pause();
    seekFrame(frame + frames);
  }

  /** Backwards playback: HTML video cannot play in reverse, so it steps back. */
  function startReverse(rateFactor: number): void {
    stopReverse();
    video.pause();
    started = true;
    const interval = 100;
    reverseTimer = setInterval(() => {
      if (video.seeking) return;
      const next = video.currentTime - (interval / 1000) * rateFactor;
      if (next <= 0) {
        stopReverse();
        shuttle = 0;
        seekFrame(0);
        return;
      }
      video.currentTime = next;
    }, interval);
  }

  function stopReverse(): void {
    clearInterval(reverseTimer);
    reverseTimer = undefined;
  }

  function setSpeed(next: number): void {
    speed = next;
    shuttle = 0;
    video.playbackRate = next;
  }

  function setVolume(value: number): void {
    volume = value;
    video.volume = value;
    muted = value === 0;
    video.muted = muted;
    try {
      localStorage.setItem(VOLUME_KEY, String(value));
    } catch {
      // storage unavailable
    }
  }

  function toggleMute(): void {
    muted = !muted;
    video.muted = muted;
  }

  async function toggleFullscreen(): Promise<void> {
    if (document.fullscreenElement) {
      await document.exitFullscreen();
    } else if (root.requestFullscreen) {
      await root.requestFullscreen().catch(() => {});
    } else {
      // iPhone: only the video element itself can go full screen.
      (video as HTMLVideoElement & { webkitEnterFullscreen?: () => void }).webkitEnterFullscreen?.();
    }
  }

  function setMark(which: 'in' | 'out'): void {
    if (which === 'in') {
      markIn = frame;
      if (markOut !== null && markOut < frame) markOut = null;
    } else {
      markOut = frame;
      if (markIn === null || markIn > frame) markIn = frame;
    }
    onMarks?.(markIn, markOut);
  }

  export function run(action: PlayerAction): void {
    switch (action) {
      case 'toggle':
        toggle();
        break;
      case 'stop':
        pause();
        break;
      case 'forward':
        shuttle = nextShuttle(shuttle, 'forward');
        play();
        break;
      case 'reverse':
        shuttle = nextShuttle(shuttle, 'reverse');
        startReverse(-shuttle);
        break;
      case 'previousFrame':
        step(-1);
        break;
      case 'nextFrame':
        step(1);
        break;
      case 'backSecond':
        step(-Math.round(rate.num / rate.den));
        break;
      case 'forwardSecond':
        step(Math.round(rate.num / rate.den));
        break;
      case 'markIn':
        setMark('in');
        break;
      case 'markOut':
        setMark('out');
        break;
      case 'comment':
        if (!video.paused) pause();
        onComment?.(frame);
        break;
      case 'fullscreen':
        void toggleFullscreen();
        break;
    }
  }

  /** Pauses on a whole frame (when the viewer starts typing a comment). */
  export function stop(): void {
    if (!video.paused || shuttle !== 0) pause();
  }

  /** Clears the I / O points (after a comment was sent, for instance). */
  export function clearMarks(): void {
    markIn = null;
    markOut = null;
    onMarks?.(null, null);
  }

  export function goTo(target: number): void {
    if (!video.paused || shuttle !== 0) pause();
    seekFrame(target);
  }

  function onKeydown(event: KeyboardEvent): void {
    if (!shortcuts || event.defaultPrevented || isTypingTarget(event.target)) return;
    // Buttons and links elsewhere on the page keep Space and Enter for themselves.
    const target = event.target as HTMLElement | null;
    const outside = target && !root.contains(target) && target !== document.body && target.closest('button, a, summary, dialog');
    if (outside) return;
    if (document.querySelector('dialog[open]')) return;
    const action = actionForKey(event);
    if (!action) return;
    event.preventDefault();
    run(action);
  }

  // ---- Timeline --------------------------------------------------------------

  function frameAt(clientX: number): number {
    const box = track.getBoundingClientRect();
    return clamp(Math.floor(((clientX - box.left) / box.width) * durationFrames), 0, lastFrame);
  }

  function onPointerDown(event: PointerEvent): void {
    if (event.button !== 0) return;
    scrubbing = true;
    track.setPointerCapture(event.pointerId);
    resumeAfterScrub = !video.paused && shuttle >= 0;
    if (!video.paused || shuttle !== 0) pause();
    seekFrame(frameAt(event.clientX));
    hover = { x: event.clientX - track.getBoundingClientRect().left, frame: frameAt(event.clientX) };
  }

  function onPointerMove(event: PointerEvent): void {
    const f = frameAt(event.clientX);
    hover = { x: clamp(event.clientX - track.getBoundingClientRect().left, 0, track.clientWidth), frame: f };
    if (scrubbing) seekFrame(f);
  }

  function onPointerUp(event: PointerEvent): void {
    const wasScrubbing = scrubbing;
    scrubbing = false;
    if (track.hasPointerCapture(event.pointerId)) track.releasePointerCapture(event.pointerId);
    if (event.pointerType !== 'mouse') hover = null;
    // Playback goes on from where the viewer let go.
    if (wasScrubbing && resumeAfterScrub) play();
    resumeAfterScrub = false;
  }

  const preview = $derived.by(() => {
    if (!hover || !sprite) return null;
    const tile = spriteTile(frameTime(hover.frame, rate), sprite);
    return {
      x: -tile.x * PREVIEW_SCALE,
      y: -tile.y * PREVIEW_SCALE,
      size: sprite.columns * sprite.width * PREVIEW_SCALE,
    };
  });

  function onVideoEvent(): void {
    paused = video.paused;
    if (video.paused && shuttle <= 0 && !scrubbing) time = video.currentTime;
  }

  function onProgress(): void {
    const ranges = video.buffered;
    for (let i = 0; i < ranges.length; i++) {
      if (ranges.start(i) <= video.currentTime && ranges.end(i) >= video.currentTime) {
        buffered = (ranges.end(i) / (video.duration || 1)) * 100;
      }
    }
  }
</script>

<svelte:window onkeydown={onKeydown} />

<div class="player" class:player--fullscreen={fullscreen} bind:this={root}>
  <div class="screen">
    <!-- svelte-ignore a11y_media_has_caption -->
    <video
      bind:this={video}
      src={directSrc}
      poster={poster ?? undefined}
      preload="metadata"
      playsinline
      onclick={toggle}
      onplay={onVideoEvent}
      onpause={onVideoEvent}
      onseeked={onVideoEvent}
      onwaiting={() => (waiting = true)}
      onplaying={() => (waiting = false)}
      oncanplay={() => (waiting = false)}
      onprogress={onProgress}
      onended={() => {
        shuttle = 0;
        paused = true;
      }}
      onerror={() => (error = 'Lecture impossible. Le lien de lecture a peut-être expiré : rechargez la page.')}
    ></video>

    {#if paused && !waiting && shuttle >= 0}
      <button class="screen__play" type="button" aria-label="Lecture" onclick={play}>
        <svg aria-hidden="true" width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13l10.5-6.5z"></path></svg>
      </button>
    {/if}
    {#if waiting && started}
      <span class="screen__spinner" role="status" aria-label="Chargement"></span>
    {/if}
    {#if overlay && !error}
      <div class="screen__overlay" aria-hidden="true">
        <span class="screen__overlay-tc">{overlay.timecode}</span>
        <span>{overlay.text}</span>
      </div>
    {/if}
    {#if error}
      <p class="screen__error" role="alert">{error}</p>
    {/if}
  </div>

  <div class="controls">
    <div class="timeline">
      {#if markers !== null}
        <div class="markers">
          {#each markers as marker (marker.id)}
            {#if marker.frameOut !== null && marker.frameOut > marker.frameIn}
              <button
                class="marker marker--range"
                type="button"
                aria-label={marker.label}
                onpointerenter={() => (hoveredMarker = marker)}
                onpointerleave={() => (hoveredMarker = null)}
                onfocus={() => (hoveredMarker = marker)}
                onblur={() => (hoveredMarker = null)}
                style:left="{percent(marker.frameIn)}%"
                style:width="{percent(marker.frameOut - marker.frameIn)}%"
                style:background={marker.color}
                onclick={() => onMarker?.(marker)}
              ></button>
            {:else}
              <button
                class="marker marker--point"
                type="button"
                aria-label={marker.label}
                onpointerenter={() => (hoveredMarker = marker)}
                onpointerleave={() => (hoveredMarker = null)}
                onfocus={() => (hoveredMarker = marker)}
                onblur={() => (hoveredMarker = null)}
                style:left="{percent(marker.frameIn)}%"
                style:background={marker.color}
                onclick={() => onMarker?.(marker)}
              ></button>
            {/if}
          {/each}
          {#if hoveredMarker}
            <span class="marker-bubble" role="tooltip" style:left="{percent(hoveredMarker.frameIn)}%">{hoveredMarker.label}</span>
          {/if}
        </div>
      {/if}

      <div
        class="track"
        bind:this={track}
        role="slider"
        tabindex="0"
        aria-label="Position dans la vidéo"
        aria-valuemin={0}
        aria-valuemax={lastFrame}
        aria-valuenow={frame}
        aria-valuetext={timecode}
        onpointerdown={onPointerDown}
        onpointermove={onPointerMove}
        onpointerup={onPointerUp}
        onpointercancel={onPointerUp}
        onpointerleave={(e) => {
          if (!scrubbing && e.pointerType === 'mouse') hover = null;
        }}
      >
        <div class="track__bar">
          <div class="track__buffered" style:width="{buffered}%"></div>
          {#if markIn !== null}
            <div
              class="track__range"
              style:left="{percent(markIn)}%"
              style:width="{percent((markOut ?? markIn) - markIn + 1)}%"
            ></div>
          {/if}
          <div class="track__played" style:width="{progress}%"></div>
          <div class="track__knob" style:left="{progress}%"></div>
        </div>

        {#if hover}
          <div class="preview" style:left="{hover.x}px" aria-hidden="true">
            {#if preview && sprite}
              <div
                class="preview__image"
                style:background-image="url('{sprite.url}')"
                style:background-size="{preview.size}px auto"
                style:background-position="{preview.x}px {preview.y}px"
              ></div>
            {/if}
            <span class="preview__tc">{framesToTimecode(startFrame + hover.frame, rate)}</span>
          </div>
        {/if}
      </div>
    </div>

    <div class="buttons">
      <button class="button button--main" type="button" aria-label={paused ? 'Lecture' : 'Pause'} onclick={toggle}>
        {#if paused && shuttle >= 0}
          <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13l10.5-6.5z"></path></svg>
        {:else}
          <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><rect x="6.5" y="5" width="3.6" height="14" rx="1"></rect><rect x="13.9" y="5" width="3.6" height="14" rx="1"></rect></svg>
        {/if}
      </button>
      <button class="button button--outline" type="button" aria-label="Image précédente" onclick={() => step(-1)}>
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"></path></svg>
      </button>
      <button class="button button--outline" type="button" aria-label="Image suivante" onclick={() => step(1)}>
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"></path></svg>
      </button>

      <p class="timecode" aria-live="off">
        <span class="timecode__current" data-testid="timecode">{timecode}</span>
        <span class="timecode__total">/ {endTimecode}</span>
      </p>

      {#if markIn !== null}
        <p class="marks">
          IN {framesToTimecode(startFrame + markIn, rate)}{#if markOut !== null}&nbsp;→ OUT {framesToTimecode(startFrame + markOut, rate)}{/if}
          <button class="marks__clear" type="button" aria-label="Effacer le point d’entrée et de sortie" onclick={clearMarks}>×</button>
        </p>
      {/if}

      <span class="spacer"></span>

      <button class="button button--speed" type="button" aria-label="Vitesse de lecture : {shuttleLabel}" onclick={() => setSpeed(nextSpeed(speed))}>
        {shuttleLabel}
      </button>
      <div class="volume">
        <button class="button" type="button" aria-label={muted ? 'Activer le son' : 'Couper le son'} onclick={toggleMute}>
          {#if muted || volume === 0}
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"></path><path d="M16 9.5l5 5"></path><path d="M21 9.5l-5 5"></path></svg>
          {:else}
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"></path><path d="M16 9a4 4 0 0 1 0 6"></path><path d="M18.5 6.5a7.5 7.5 0 0 1 0 11"></path></svg>
          {/if}
        </button>
        <label class="visually-hidden" for="{uid}-volume">Volume</label>
        <input
          class="volume__slider"
          id="{uid}-volume"
          type="range"
          min="0"
          max="1"
          step="0.05"
          value={muted ? 0 : volume}
          oninput={(e) => setVolume(Number(e.currentTarget.value))}
        />
      </div>
      <button class="button" type="button" aria-label={fullscreen ? 'Quitter le plein écran' : 'Plein écran'} onclick={toggleFullscreen}>
        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5"></path><path d="M20 9V4h-5"></path><path d="M4 15v5h5"></path><path d="M20 15v5h-5"></path></svg>
      </button>
    </div>
  </div>
</div>

<style>
  /* Colours follow the page: client colours on client pages, admin palette otherwise. */
  .player {
    --fg: var(--client-text);
    --bg: var(--client-bg);
    --muted: color-mix(in srgb, var(--client-text) 74%, var(--client-bg));
    --line: color-mix(in srgb, var(--client-text) 16%, var(--client-bg));
    --surface: color-mix(in srgb, var(--client-text) 7%, var(--client-bg));
    display: flex;
    flex-direction: column;
    gap: 10px;
    color: var(--fg);
  }

  .player--fullscreen {
    justify-content: center;
    padding: 16px;
    background: var(--bg);
  }

  .screen {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    aspect-ratio: 16 / 9;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    background: #000;
  }

  .player--fullscreen .screen {
    flex: 1;
    aspect-ratio: auto;
    min-height: 0;
  }

  video {
    width: 100%;
    height: 100%;
    object-fit: contain;
    cursor: pointer;
  }

  .screen__play {
    position: absolute;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 76px;
    height: 76px;
    border: 1px solid rgb(250 250 250 / 0.25);
    border-radius: 50%;
    background: rgb(10 10 11 / 0.6);
    color: #fafafa;
  }

  .screen__spinner {
    position: absolute;
    width: 40px;
    height: 40px;
    border: 3px solid rgb(250 250 250 / 0.25);
    border-top-color: #fafafa;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
  }

  @keyframes spin {
    to {
      transform: rotate(360deg);
    }
  }

  /* Discreet comment over the picture during playback (mock-up). */
  .screen__overlay {
    position: absolute;
    left: 16px;
    bottom: 14px;
    max-width: min(60%, 520px);
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 10px 14px;
    border: 1px solid #303035;
    border-radius: 8px;
    background: rgb(10 10 11 / 0.82);
    color: #fafafa;
    font-size: 13px;
    pointer-events: none;
  }

  .screen__overlay-tc {
    font-family: var(--font-mono);
    font-size: 11.5px;
    color: var(--client-accent);
  }

  @media (max-width: 520px) {
    .screen__overlay {
      left: 8px;
      bottom: 8px;
      max-width: calc(100% - 16px);
      padding: 6px 10px;
      font-size: 12px;
    }
  }

  .marker-bubble {
    position: absolute;
    bottom: 26px;
    max-width: 280px;
    padding: 6px 10px;
    border: 1px solid #303035;
    border-radius: var(--radius-sm);
    background: rgb(10 10 11 / 0.92);
    color: #fafafa;
    font-size: 12px;
    transform: translateX(-50%);
    pointer-events: none;
    white-space: normal;
    z-index: 3;
  }

  .screen__error {
    position: absolute;
    inset: auto 16px 16px;
    padding: 10px 14px;
    border-radius: var(--radius-sm);
    background: rgb(10 10 11 / 0.85);
    color: #fafafa;
    font-size: 13px;
  }

  .controls {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  /* ---- Timeline ---- */

  .timeline {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .markers {
    position: relative;
    height: 22px;
  }

  .marker {
    position: absolute;
    padding: 0;
    border: none;
    cursor: pointer;
  }

  .marker--point {
    top: 5px;
    width: 12px;
    height: 12px;
    margin-left: -6px;
    border-radius: 50%;
    box-shadow: 0 0 0 3px var(--bg);
  }

  .marker--range {
    top: 7px;
    min-width: 6px;
    height: 8px;
    border-radius: 4px;
    opacity: 0.85;
  }

  .track {
    position: relative;
    display: flex;
    align-items: center;
    height: 28px;
    cursor: pointer;
    touch-action: none;
    border-radius: var(--radius-sm);
  }

  .track__bar {
    position: relative;
    flex: 1;
    height: 6px;
    border-radius: 3px;
    background: var(--line);
  }

  .track__buffered {
    position: absolute;
    inset: 0 auto 0 0;
    border-radius: 3px;
    background: color-mix(in srgb, var(--client-text) 28%, var(--client-bg));
  }

  .track__range {
    position: absolute;
    top: -2px;
    bottom: -2px;
    border-radius: 3px;
    background: color-mix(in srgb, var(--client-accent) 45%, transparent);
  }

  .track__played {
    position: absolute;
    inset: 0 auto 0 0;
    border-radius: 3px;
    background: var(--fg);
  }

  .track__knob {
    position: absolute;
    top: -5px;
    width: 16px;
    height: 16px;
    margin-left: -8px;
    border-radius: 50%;
    background: var(--fg);
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--client-text) 15%, transparent);
  }

  .preview {
    position: absolute;
    bottom: 34px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    transform: translateX(-50%);
    pointer-events: none;
    z-index: 2;
  }

  .preview__image {
    width: 160px;
    height: 90px;
    border: 1px solid rgb(250 250 250 / 0.25);
    border-radius: var(--radius-sm);
    background-color: #000;
    background-repeat: no-repeat;
    box-shadow: 0 8px 24px rgb(0 0 0 / 0.45);
  }

  .preview__tc {
    padding: 3px 8px;
    border-radius: var(--radius-sm);
    background: rgb(10 10 11 / 0.88);
    color: #fafafa;
    font-family: var(--font-mono);
    font-size: 11.5px;
    white-space: nowrap;
  }

  /* ---- Buttons ---- */

  .buttons {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
  }

  .button {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--hit);
    height: var(--hit);
    padding: 0;
    border: none;
    border-radius: 50%;
    background: transparent;
    color: var(--fg);
  }

  .button:hover {
    background: var(--surface);
  }

  .button--main {
    background: var(--fg);
    color: var(--bg);
  }

  .button--main:hover {
    background: var(--fg);
    opacity: 0.88;
  }

  .button--outline {
    border: 1px solid var(--line);
  }

  .button--speed {
    width: auto;
    min-width: 52px;
    height: 36px;
    padding: 0 12px;
    border: 1px solid var(--line);
    border-radius: var(--radius-pill);
    font-family: var(--font-mono);
    font-size: 12.5px;
  }

  .timecode {
    margin-left: 8px;
    font-family: var(--font-mono);
    font-size: 14px;
    letter-spacing: 0.02em;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }

  .timecode__total {
    color: var(--muted);
  }

  .marks {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 4px 2px 10px;
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--client-accent-text);
    font-family: var(--font-mono);
    font-size: 11.5px;
    white-space: nowrap;
  }

  .marks__clear {
    width: 24px;
    height: 24px;
    padding: 0;
    border: none;
    background: none;
    color: var(--muted);
    font-size: 15px;
  }

  .spacer {
    flex: 1;
  }

  .volume {
    display: flex;
    align-items: center;
  }

  .volume__slider {
    width: 0;
    opacity: 0;
    accent-color: var(--fg);
    transition: width 150ms ease, opacity 150ms ease;
  }

  /* The slider opens on hover or keyboard focus; touch screens use the device volume. */
  @media (hover: hover) {
    .volume:hover .volume__slider,
    .volume:focus-within .volume__slider {
      width: 80px;
      opacity: 1;
    }
  }

  .volume__slider:focus-visible {
    width: 80px;
    opacity: 1;
  }

  @media (max-width: 520px) {
    .timecode {
      order: 10;
      width: 100%;
      margin: 4px 0 0;
      text-align: center;
    }
  }
</style>
