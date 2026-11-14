<script lang="ts">
  import { untrack } from 'svelte';
  import { ApiError, setCsrfToken, setExtraHeaders } from '../lib/api';
  import { formatBytes, formatDate } from '../lib/format';
  import { VERSION_STATUS } from '../lib/status';
  import { formatRate, framesToTimecode, nominalFps } from '../lib/timecode';
  import Player from '../player/Player.svelte';
  import VersionPicker from '../player/VersionPicker.svelte';
  import { clientReviewApi } from '../review/api';
  import { author, setAuthorName } from '../review/author';
  import CommentsPanel from '../review/CommentsPanel.svelte';
  import Dialog from '../review/Dialog.svelte';
  import { excerpt, markers as toMarkers, overlayAt, type Thread } from '../review/threads';
  import type { VersionState } from '../review/types';
  import type { ClientBrand, PublicVersion, VideoInfo } from './types';

  let {
    client,
    project,
    video,
    versions,
    csrf,
  }: { client: ClientBrand; project: { name: string }; video: VideoInfo; versions: PublicVersion[]; csrf: string } = $props();

  setCsrfToken(untrack(() => csrf));
  setExtraHeaders({ 'X-Author-Token': author().token });
  const ref = window.location.pathname.replace(/^\/v\//, '');
  const api = clientReviewApi(ref);

  // The most recent version is selected by default (§5.1).
  let selectedId = $state(untrack(() => versions[0]?.id ?? 0));
  const position = { frame: 0 };
  let frame = $state(0);
  let marks = $state<{ in: number | null; out: number | null }>({ in: null, out: null });
  let threads = $state<Thread[]>([]);
  let states = $state<Record<number, VersionState>>({});
  let player = $state<ReturnType<typeof Player> | null>(null);
  let panel = $state<ReturnType<typeof CommentsPanel> | null>(null);

  const selected = $derived(versions.find((v) => v.id === selectedId) ?? null);
  const rate = $derived(selected ? { num: selected.fpsNum, den: selected.fpsDen, dropFrame: selected.dropFrame } : null);
  const current = $derived(
    selected ? (states[selected.id] ?? { id: selected.id, status: selected.status ?? 'to_review', approvedBy: null, approvedAt: null }) : null,
  );
  const markerList = $derived(toMarkers(threads));
  const overlay = $derived.by(() => {
    if (!rate || !selected) return null;
    const thread = overlayAt(threads, frame, nominalFps(rate));
    if (!thread) return null;
    const c = thread.comment;
    const tc = (f: number): string => framesToTimecode(selected.startFrame + f, rate);
    return {
      timecode: c.frameOut === null ? tc(c.frameIn) : `${tc(c.frameIn)} → ${tc(c.frameOut)}`,
      text: `${c.author} — « ${excerpt(c.text, 110)} »`,
    };
  });

  function selectVersion(id: number): void {
    threads = [];
    marks = { in: null, out: null };
    selectedId = id;
  }

  // ---- Name (first comment) ---------------------------------------------------------

  let nameOpen = $state(false);
  let nameValue = $state('');
  let resolveName: ((name: string | null) => void) | null = null;

  function requestName(current: string): Promise<string | null> {
    nameValue = current;
    nameOpen = true;
    return new Promise((resolve) => (resolveName = resolve));
  }

  function confirmName(): void {
    const name = nameValue.trim();
    if (!name) return;
    setAuthorName(name);
    nameOpen = false;
    resolveName?.(name);
    resolveName = null;
  }

  $effect(() => {
    if (!nameOpen && resolveName) {
      resolveName(null);
      resolveName = null;
    }
  });

  // ---- Validation -------------------------------------------------------------------

  let approveOpen = $state(false);
  let approveName = $state('');
  let approveBusy = $state(false);
  let approveError = $state('');

  function openApprove(): void {
    approveName = author().name;
    approveError = '';
    approveOpen = true;
  }

  async function approve(): Promise<void> {
    if (!selected || !approveName.trim()) return;
    approveBusy = true;
    approveError = '';
    try {
      const result = await api.approve!(selected.id, approveName.trim());
      setAuthorName(approveName);
      states = { ...states, [selected.id]: result };
      approveOpen = false;
    } catch (e) {
      approveError = e instanceof ApiError ? e.message : 'Validation impossible.';
    } finally {
      approveBusy = false;
    }
  }
</script>

<div class="topbar">
  <nav class="breadcrumb" aria-label="Fil d’Ariane">
    <span>{client.name}</span><span aria-hidden="true">/</span><span>{project.name}</span><span aria-hidden="true">/</span><span
      aria-current="page">{video.title}</span
    >
  </nav>
  {#if selected && current}
    <div class="actions">
    {#if video.downloadAllowed}
      <a class="download" href="/api/v/{ref}/versions/{selected.id}/download" download>
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11"></path><path d="M7 10l5 5 5-5"></path><path d="M5 20h14"></path></svg>
        Télécharger le master
        <span class="visually-hidden">(V{selected.number}{selected.size ? `, ${formatBytes(selected.size)}` : ''})</span>
      </a>
    {/if}
    {#if current.status === 'approved'}
      <p class="approved">
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>
        V{selected.number} validée{current.approvedBy ? ` par ${current.approvedBy}` : ''}{current.approvedAt ? ` le ${formatDate(current.approvedAt)}` : ''}
      </p>
    {:else}
      <button class="approve" type="button" onclick={openApprove}>
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>
        Valider cette version
      </button>
    {/if}
    </div>
  {/if}
</div>

<div class="review">
  <div class="review__main">
    <div class="review__head">
      <div class="review__titles">
        <p class="client-eyebrow">// N° {video.number}</p>
        <h1 class="client-title">{video.title}</h1>
      </div>
      {#if versions.length > 1}
        <VersionPicker {versions} selected={selectedId} onSelect={selectVersion} />
      {/if}
    </div>

    {#if selected && rate && current}
      <div class="review__meta">
        <span class="status status--{current.status}">{VERSION_STATUS[current.status]}</span>
        <span>V{selected.number}{selected.publishedAt ? ` · envoyée le ${formatDate(selected.publishedAt)}` : ''}</span>
        <span aria-hidden="true">·</span>
        <span class="review__tech">
          {selected.width}×{selected.height} · {formatRate(rate)} · TC {framesToTimecode(selected.startFrame, rate)} · {formatBytes(selected.size ?? 0)}
        </span>
      </div>

      {#key selected.id}
        <Player
          bind:this={player}
          src={selected.videoUrl}
          hls={selected.hlsUrl ?? null}
          poster={selected.posterUrl}
          sprite={selected.sprite}
          {rate}
          startFrame={selected.startFrame}
          durationFrames={selected.durationFrames}
          initialFrame={position.frame}
          markers={markerList}
          {overlay}
          onFrame={(f) => ((position.frame = f), (frame = f))}
          onMarks={(markIn, markOut) => (marks = { in: markIn, out: markOut })}
          onComment={(f) => panel?.focusComposer(f)}
          onMarker={(m) => (player?.goTo(m.frameIn), panel?.show(Number(m.id)))}
        />
      {/key}
    {:else}
      <div class="client-placeholder">Aucune version n’est encore disponible.</div>
    {/if}
  </div>

  {#if selected && rate}
    <div class="review__side">
      {#key selected.id}
        <CommentsPanel
          bind:this={panel}
          {api}
          versionId={selected.id}
          versionLabel="V{selected.number}"
          {rate}
          startFrame={selected.startFrame}
          currentFrame={frame}
          {marks}
          onSeek={(f) => player?.goTo(f)}
          onPause={() => player?.stop()}
          onClearMarks={() => player?.clearMarks()}
          onLoaded={(list, version) => ((threads = list), (states = { ...states, [version.id]: version }))}
          {requestName}
        />
      {/key}
    </div>
  {/if}
</div>

<Dialog bind:open={nameOpen} title="Votre nom" confirmLabel="Continuer" canConfirm={nameValue.trim() !== ''} onConfirm={confirmName}>
  <label class="visually-hidden" for="review-name">Votre nom</label>
  <input id="review-name" autocomplete="name" maxlength="60" placeholder="Prénom Nom" bind:value={nameValue} />
  <p class="dialog__note">
    Votre nom accompagne vos révisions ; ce navigateur s’en souviendra. Seuls votre nom et vos révisions sont enregistrés.
  </p>
</Dialog>

<Dialog
  bind:open={approveOpen}
  title="Valider la V{selected?.number ?? ''} ?"
  confirmLabel="Valider"
  busy={approveBusy}
  canConfirm={approveName.trim() !== ''}
  error={approveError}
  onConfirm={approve}
>
  <p>Vous confirmez que cette version vous convient. Elle sera marquée « Validé », avec votre nom et la date.</p>
  <label class="visually-hidden" for="approve-name">Votre nom</label>
  <input id="approve-name" autocomplete="name" maxlength="60" placeholder="Prénom Nom" bind:value={approveName} />
</Dialog>

<style>
  .topbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }

  .breadcrumb {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 13px;
    color: var(--muted);
  }

  .breadcrumb [aria-current] {
    color: var(--client-text);
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
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

  .approve {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: var(--hit);
    padding: 0 20px;
    border: none;
    border-radius: var(--radius-pill);
    background: var(--client-accent);
    color: var(--client-on-accent);
    font-size: 13.5px;
    font-weight: 600;
  }

  .approved {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: var(--hit);
    padding: 0 16px;
    border-radius: var(--radius-pill);
    background: rgb(46 229 157 / 0.15);
    color: color-mix(in srgb, var(--status-approved) var(--status-ink, 100%), var(--client-text));
    font-size: 13.5px;
    font-weight: 500;
  }

  .review {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 28px;
  }

  .review__main {
    flex: 999 1 640px;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 18px;
  }

  .review__side {
    flex: 1 1 360px;
    min-width: 0;
    display: flex;
  }

  .review__side > :global(*) {
    flex: 1;
  }

  .review__head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
  }

  .review__titles {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
  }

  .review__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--muted);
  }

  .review__tech {
    font-family: var(--font-mono);
    font-size: 12px;
  }
</style>
