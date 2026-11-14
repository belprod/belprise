<script lang="ts">
  import { onMount, tick, untrack } from 'svelte';
  import { ApiError } from '../lib/api';
  import { formatRelative } from '../lib/format';
  import { framesToTimecode, type FrameRate } from '../lib/timecode';
  import { author } from './author';
  import { counts, filterThreads, threads as buildThreads, type Filter, type Thread } from './threads';
  import type { ReviewApi, ReviewComment, VersionState } from './types';

  let {
    api,
    versionId,
    versionLabel,
    rate,
    startFrame,
    currentFrame,
    marks,
    onSeek,
    onPause,
    onClearMarks,
    onLoaded,
    requestName,
    initialHighlight = null,
    signature = '',
  }: {
    api: ReviewApi;
    versionId: number;
    versionLabel: string;
    rate: FrameRate;
    startFrame: number;
    /** Player position (frames from the start) */
    currentFrame: number;
    /** I / O points set in the player */
    marks: { in: number | null; out: number | null };
    onSeek: (frame: number) => void;
    onPause: () => void;
    onClearMarks: () => void;
    onLoaded: (threads: Thread[], version: VersionState) => void;
    /** Client pages: asks the viewer's name (first comment) */
    requestName?: (current: string) => Promise<string | null>;
    initialHighlight?: number | null;
    /** Admin: name of the organization, which signs its replies */
    signature?: string;
  } = $props();

  const uid = $props.id();
  const REFRESH_MS = 30_000;
  const admin = $derived(api.mode === 'admin');

  let comments = $state<ReviewComment[] | null>(null);
  let filter = $state<Filter>('all');
  let error = $state('');
  let highlighted = $state<number | null>(untrack(() => initialHighlight));
  let unreadAtOpening = $state(new Set<number>());

  // Composer
  let mode = $state<'point' | 'range'>('point');
  let lockedIn = $state<number | null>(null);
  let frameOut = $state<number | null>(null);
  let text = $state('');
  let sending = $state(false);
  let name = $state(author().name);
  let textarea = $state<HTMLTextAreaElement | null>(null);

  // Replies, edits, deletions
  let replyTo = $state<number | null>(null);
  let replyText = $state('');
  let editId = $state<number | null>(null);
  let editText = $state('');
  let confirmDelete = $state<number | null>(null);
  let refuseId = $state<number | null>(null);
  let refuseText = $state('');

  const all = $derived(buildThreads(comments ?? []));
  const shown = $derived(filterThreads(all, filter));
  const totals = $derived(counts(all));
  const frameIn = $derived(lockedIn ?? currentFrame);
  const tc = (frame: number): string => framesToTimecode(startFrame + frame, rate);
  const rangeLabel = (c: ReviewComment): string =>
    c.frameOut === null ? tc(c.frameIn) : `${tc(c.frameIn)} → ${tc(c.frameOut).slice(6)}`;

  // I and O pressed in the player fill the composer.
  $effect(() => {
    const markIn = marks.in;
    const markOut = marks.out;
    untrack(() => {
      if (markIn !== null) lockedIn = markIn;
      if (markOut !== null) {
        frameOut = markOut;
        mode = 'range';
      }
    });
  });

  async function refresh(): Promise<void> {
    try {
      const payload = await api.list(versionId);
      const first = comments === null;
      comments = payload.comments;
      error = '';
      onLoaded(buildThreads(payload.comments), payload.version);
      if (first && admin) {
        unreadAtOpening = new Set(payload.comments.filter((c) => c.read === false).map((c) => c.id));
        if (unreadAtOpening.size > 0) await api.markRead?.(versionId);
      }
      if (first && highlighted !== null) {
        await tick();
        reveal(highlighted);
      }
    } catch (e) {
      if (comments === null) error = e instanceof ApiError ? e.message : 'Chargement des révisions impossible.';
    }
  }

  onMount(() => {
    void refresh();
    // Light polling to see new comments and replies (§5.1).
    const timer = setInterval(() => {
      if (!document.hidden) void refresh();
    }, REFRESH_MS);
    const onVisible = (): void => {
      if (!document.hidden) void refresh();
    };
    document.addEventListener('visibilitychange', onVisible);
    return () => {
      clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisible);
    };
  });

  function reveal(id: number): void {
    highlighted = id;
    document.getElementById(`${uid}-c${id}`)?.scrollIntoView({ block: 'nearest', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  }

  /** From the player: marker click. */
  export function show(id: number): void {
    filter = 'all';
    void tick().then(() => reveal(id));
  }

  /** "C": write a comment at the current frame. */
  export function focusComposer(frame: number): void {
    // A range set with I / O is kept; otherwise the comment starts here.
    if (mode !== 'range' || frameOut === null) lockedIn = frame;
    textarea?.focus();
  }

  function startTyping(): void {
    lockedIn ??= currentFrame;
    onPause();
  }

  function setOut(): void {
    if (currentFrame <= frameIn) {
      error = 'Le point de sortie doit être après le point d’entrée.';
      return;
    }
    lockedIn ??= frameIn;
    frameOut = currentFrame;
    mode = 'range';
    error = '';
  }

  async function ensureName(): Promise<string | null> {
    if (admin) return signature;
    if (name.trim()) return name;
    const chosen = await requestName?.(name);
    if (chosen) name = chosen;
    return chosen ?? null;
  }

  async function changeName(): Promise<void> {
    const chosen = await requestName?.(name);
    if (chosen) name = chosen;
  }

  async function send(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (sending || !text.trim()) return;
    if (mode === 'range' && (frameOut === null || frameOut <= frameIn)) {
      error = 'Définissez le point de sortie : touche O, ou bouton « Sortie ici ».';
      return;
    }
    const who = await ensureName();
    if (!who) return;
    sending = true;
    error = '';
    try {
      const created = await api.create(versionId, {
        text,
        frameIn,
        frameOut: mode === 'range' ? frameOut : null,
        ...(admin ? {} : { author: who }),
      });
      text = '';
      lockedIn = null;
      frameOut = null;
      mode = 'point';
      onClearMarks();
      await refresh();
      reveal(created.id);
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Envoi impossible.';
    } finally {
      sending = false;
    }
  }

  async function sendReply(parent: ReviewComment): Promise<void> {
    if (!replyText.trim()) return;
    const who = await ensureName();
    if (!who) return;
    try {
      await api.create(versionId, { text: replyText, parentId: parent.id, ...(admin ? {} : { author: who }) });
      replyText = '';
      replyTo = null;
      await refresh();
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Envoi impossible.';
    }
  }

  async function saveEdit(comment: ReviewComment): Promise<void> {
    try {
      await api.update(comment.id, editText);
      editId = null;
      await refresh();
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Modification impossible.';
    }
  }

  async function remove(comment: ReviewComment): Promise<void> {
    try {
      await api.remove(comment.id);
      confirmDelete = null;
      await refresh();
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Suppression impossible.';
    }
  }

  /** À faire ↔ Fait; a refused revision goes back to "À faire". */
  async function toggleStatus(comment: ReviewComment): Promise<void> {
    try {
      await api.setStatus?.(comment.id, comment.status === 'todo' ? 'done' : 'todo');
      await refresh();
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Changement impossible.';
    }
  }

  /** Refusal: the reason is posted as a reply, in the same request. */
  async function refuse(comment: ReviewComment): Promise<void> {
    if (!refuseText.trim()) return;
    try {
      await api.setStatus?.(comment.id, 'refused', refuseText);
      refuseId = null;
      refuseText = '';
      await refresh();
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Refus impossible.';
    }
  }

  /** belprod keeps its wordmark; other organizations sign with their name. */
  const isBelprod = (name: string): boolean => name === 'belprod' || name === 'belprod.';

  const STATUS_LABELS: Record<string, string> = { todo: 'À faire', done: 'Fait', refused: 'Refusé' };
  const statusLabel = (c: ReviewComment): string => STATUS_LABELS[c.status ?? 'todo'] ?? 'À faire';

  function canEdit(c: ReviewComment): boolean {
    return admin ? c.isAdmin : c.mine;
  }

  function canDelete(c: ReviewComment): boolean {
    return admin || c.mine;
  }

  const FILTERS: [Filter, string][] = [
    ['all', 'Tous'],
    ['todo', 'À faire'],
    ['done', 'Fait'],
    ['refused', 'Refusé'],
  ];
  const EMPTY: Record<Filter, string> = {
    all: 'Aucune révision pour l’instant.',
    todo: 'Rien à faire.',
    done: 'Aucune révision traitée.',
    refused: 'Aucune révision refusée.',
  };
</script>

{#snippet authorLine(c: ReviewComment, isReply: boolean)}
  {#if c.isAdmin}
    <span class="who who--admin">{#if isBelprod(c.author)}<span class="wordmark">belprod<span class="wordmark__dot">.</span></span>{:else}{c.author}{/if}</span>
    <span class="muted-text">{isReply ? 'réponse' : 'note'}</span>
  {:else}
    {#if !isReply}<span class="avatar" aria-hidden="true">{c.author.charAt(0).toUpperCase()}</span>{/if}
    <span class="who">{c.author}</span>
  {/if}
{/snippet}

{#snippet actions(c: ReviewComment, isReply: boolean, thread: Thread | null)}
  {#if confirmDelete === c.id}
    <span class="actions">
      <span class="muted-text">Supprimer ?</span>
      <button class="link link--danger" type="button" onclick={() => remove(c)}>Oui, supprimer</button>
      <button class="link" type="button" onclick={() => (confirmDelete = null)}>Non</button>
    </span>
  {:else}
    <span class="actions can-edit">
      {#if !isReply && thread}
        <button class="link" type="button" onclick={() => ((replyTo = replyTo === c.id ? null : c.id), (replyText = ''), (refuseId = null))}>Répondre</button>
        {#if admin && c.status !== 'refused'}
          <button class="link" type="button" onclick={() => ((refuseId = refuseId === c.id ? null : c.id), (refuseText = ''), (replyTo = null))}>Refuser…</button>
        {/if}
      {/if}
      {#if canEdit(c)}
        <button class="link" type="button" onclick={() => ((editId = c.id), (editText = c.text))}>Modifier</button>
      {/if}
      {#if canDelete(c)}
        <button class="link" type="button" onclick={() => (confirmDelete = c.id)}>Supprimer</button>
      {/if}
    </span>
  {/if}
{/snippet}

{#snippet body(c: ReviewComment)}
  {#if editId === c.id}
    <form class="inline-form" onsubmit={(e) => (e.preventDefault(), saveEdit(c))}>
      <label class="visually-hidden" for="{uid}-edit-{c.id}">Modifier la révision</label>
      <textarea id="{uid}-edit-{c.id}" rows="3" bind:value={editText} onfocus={onPause}></textarea>
      <span class="inline-form__buttons">
        <button class="send send--small" type="submit">Enregistrer</button>
        <button class="link" type="button" onclick={() => (editId = null)}>Annuler</button>
      </span>
    </form>
  {:else}
    <p class="text">{c.text}</p>
  {/if}
{/snippet}

<aside class="comments" aria-labelledby="{uid}-title">
  <div class="comments__head">
    <div class="comments__title-row">
      <h2 class="comments__title" id="{uid}-title">Révisions</h2>
      <span class="comments__count">{versionLabel} · {totals.all} révision{totals.all > 1 ? 's' : ''}</span>
    </div>
    <div class="filters" role="group" aria-label="Filtrer les révisions">
      {#each FILTERS.filter(([key]) => key !== 'refused' || totals.refused > 0 || filter === 'refused') as [key, label] (key)}
        <button class="filter" type="button" aria-pressed={filter === key} onclick={() => (filter = key)}>
          {label} <span class="filter__count">{totals[key]}</span>
        </button>
      {/each}
    </div>
  </div>

  <div class="list">
    {#if comments === null && !error}
      <p class="empty-text">Chargement…</p>
    {:else if shown.length === 0}
      <p class="empty-text">
        {EMPTY[filter]}
      </p>
    {/if}
    {#each shown as thread (thread.comment.id)}
      {@const c = thread.comment}
      <article
        class="comment"
        class:comment--highlighted={highlighted === c.id}
        class:comment--unread={unreadAtOpening.has(c.id)}
        id="{uid}-c{c.id}"
      >
        {#if c.deleted}
          <p class="text text--deleted">Révision supprimée.</p>
        {:else}
          <div class="comment__head">
            {@render authorLine(c, false)}
            <button class="tc" type="button" aria-label="Aller à {rangeLabel(c)}" onclick={() => ((highlighted = c.id), onSeek(c.frameIn))}>
              {rangeLabel(c)}
            </button>
            <span class="grow"></span>
            {#if unreadAtOpening.has(c.id)}<span class="new">nouveau</span>{/if}
            {#if admin}
              <span class="state state--{c.status} viewer-only">{statusLabel(c)}</span>
              <button
                class="state state--{c.status} can-edit"
                type="button"
                aria-label="Statut : {statusLabel(c)}. Changer"
                onclick={() => toggleStatus(c)}
              >
                {statusLabel(c)}
              </button>
            {:else}
              <span class="state state--{c.status}">{statusLabel(c)}</span>
            {/if}
          </div>
          <div class="comment__body">
            {@render body(c)}
            <span class="meta">{formatRelative(c.createdAt)}</span>
            {@render actions(c, false, thread)}
          </div>
        {/if}

        {#each thread.replies as reply (reply.id)}
          <div class="reply" class:reply--admin={reply.isAdmin} class:comment--unread={unreadAtOpening.has(reply.id)} id="{uid}-c{reply.id}">
            <div class="reply__head">
              {@render authorLine(reply, true)}
              {#if unreadAtOpening.has(reply.id)}<span class="new">nouveau</span>{/if}
            </div>
            {@render body(reply)}
            <span class="meta">{formatRelative(reply.createdAt)}</span>
            {@render actions(reply, true, null)}
          </div>
        {/each}

        {#if refuseId === c.id}
          <form class="inline-form reply-form refuse-form" onsubmit={(e) => (e.preventDefault(), refuse(c))}>
            <label class="refuse-form__label" for="{uid}-refuse-{c.id}">Pourquoi cette modification n’est-elle pas possible ?</label>
            <textarea
              id="{uid}-refuse-{c.id}"
              rows="3"
              placeholder="Votre explication, envoyée au client en réponse…"
              bind:value={refuseText}
              onfocus={onPause}
            ></textarea>
            <span class="inline-form__buttons">
              <button class="send send--small send--danger" type="submit" disabled={!refuseText.trim()}>Refuser la révision</button>
              <button class="link" type="button" onclick={() => (refuseId = null)}>Annuler</button>
            </span>
          </form>
        {/if}

        {#if replyTo === c.id}
          <form class="inline-form reply-form" onsubmit={(e) => (e.preventDefault(), sendReply(c))}>
            <label class="visually-hidden" for="{uid}-reply-{c.id}">Votre réponse</label>
            <textarea id="{uid}-reply-{c.id}" rows="2" placeholder="Votre réponse…" bind:value={replyText} onfocus={onPause}></textarea>
            <span class="inline-form__buttons">
              <button class="send send--small" type="submit" disabled={!replyText.trim()}>Répondre</button>
              <button class="link" type="button" onclick={() => (replyTo = null)}>Annuler</button>
            </span>
          </form>
        {/if}
      </article>
    {/each}
  </div>

  {#if error}
    <p class="error" role="alert">{error}</p>
  {/if}

  <form class="composer" class:can-edit={admin} onsubmit={send}>
    <div class="composer__row">
      <div class="modes" role="group" aria-label="Type de révision">
        <button class="mode" type="button" aria-pressed={mode === 'point'} onclick={() => ((mode = 'point'), (frameOut = null))}>Instant</button>
        <button class="mode" type="button" aria-pressed={mode === 'range'} onclick={() => (mode = 'range')}>Plage</button>
      </div>
      <div class="points">
        <span class="point point--in">IN {tc(frameIn)}</span>
        {#if mode === 'range'}
          {#if frameOut !== null}
            <span class="point">OUT {tc(frameOut)}</span>
          {/if}
          <button class="point point--button" type="button" onclick={setOut}>{frameOut === null ? 'Sortie ici' : 'Changer la sortie'}</button>
        {/if}
      </div>
    </div>
    <label class="visually-hidden" for="{uid}-text">Votre révision</label>
    <textarea
      id="{uid}-text"
      rows="3"
      placeholder="Votre révision à {tc(frameIn)}…"
      bind:value={text}
      bind:this={textarea}
      onfocus={startTyping}
      oninput={startTyping}
    ></textarea>
    <div class="composer__row">
      {#if admin}
        <span class="as">Signé {#if isBelprod(signature)}<span class="wordmark">belprod<span class="wordmark__dot">.</span></span>{:else}<strong>{signature}</strong>{/if}</span>
      {:else}
        <span class="as">
          {#if name}
            En tant que <button class="link link--strong" type="button" onclick={changeName} aria-label="En tant que {name}, modifier le nom">{name}</button>
          {:else}
            Votre nom vous sera demandé à l’envoi.
          {/if}
        </span>
      {/if}
      <button class="send" type="submit" disabled={sending || !text.trim()}>{sending ? 'Envoi…' : 'Envoyer'}</button>
    </div>
    <ul class="keys" aria-label="Raccourcis clavier">
      <li><kbd>C</kbd> réviser ici</li>
      <li><kbd>I</kbd><kbd>O</kbd> plage</li>
      <li><kbd class="kbd--wide">espace</kbd> lire</li>
      <li><kbd>J</kbd><kbd>K</kbd><kbd>L</kbd> défiler</li>
      <li><kbd>←</kbd><kbd>→</kbd> image par image</li>
    </ul>
  </form>
</aside>

<style>
  .comments {
    --fg: var(--client-text);
    --muted: color-mix(in srgb, var(--client-text) 74%, var(--client-bg));
    --line: color-mix(in srgb, var(--client-text) 14%, var(--client-bg));
    --surface: color-mix(in srgb, var(--client-text) 4%, var(--client-bg));
    --raised: color-mix(in srgb, var(--client-text) 8%, var(--client-bg));
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    background: var(--surface);
    color: var(--fg);
  }

  .comments__head {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 18px 20px 14px;
    border-bottom: 1px solid var(--line);
  }

  .comments__title-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
  }

  .comments__title {
    font-family: var(--font-display);
    font-weight: 400;
    font-size: 26px;
  }

  .comments__count {
    font-family: var(--font-mono);
    font-size: 12px;
    color: var(--muted);
  }

  .filters {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }

  .filter {
    height: 32px;
    padding: 0 12px;
    border: 1px solid var(--line);
    border-radius: var(--radius-pill);
    background: transparent;
    color: var(--fg);
    font-size: 12.5px;
    font-weight: 500;
  }

  .filter[aria-pressed='true'] {
    border-color: var(--fg);
    background: var(--fg);
    color: var(--client-bg);
  }

  .filter__count {
    font-family: var(--font-mono);
    opacity: 0.8;
  }

  .list {
    display: flex;
    flex-direction: column;
    flex: 1;
    max-height: min(70vh, 720px);
    overflow-y: auto;
    padding: 6px 0;
  }

  .empty-text {
    padding: 20px;
    color: var(--muted);
    font-size: 13.5px;
  }

  .comment {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--line);
    border-left: 3px solid transparent;
    transition: background-color 400ms ease;
  }

  .comment--highlighted {
    border-left-color: var(--client-accent);
    background: var(--raised);
  }

  .comment__head,
  .reply__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 10px;
  }

  .avatar {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: 1px solid var(--line);
    border-radius: 50%;
    background: var(--raised);
    font-size: 12px;
    font-weight: 600;
  }

  .who {
    font-size: 13.5px;
    font-weight: 600;
  }

  .who--admin :global(.wordmark) {
    font-size: 13.5px;
    font-weight: 400;
  }

  .tc {
    height: 26px;
    padding: 0 9px;
    border: none;
    border-radius: var(--radius-sm);
    background: var(--raised);
    color: var(--client-accent-text);
    font-family: var(--font-mono);
    font-size: 11.5px;
  }

  .grow {
    flex: 1;
  }

  .new {
    padding: 2px 7px;
    border-radius: var(--radius-pill);
    background: var(--client-accent);
    color: var(--client-on-accent);
    font-size: 11px;
    font-weight: 600;
  }

  .state {
    display: inline-flex;
    align-items: center;
    height: 24px;
    padding: 0 9px;
    border: none;
    border-radius: var(--radius-pill);
    font-size: 11.5px;
    font-weight: 500;
  }

  button.state {
    min-height: 32px;
  }

  .state--todo {
    background: rgb(255 214 10 / 0.16);
    color: color-mix(in srgb, var(--status-changes) var(--status-ink, 100%), var(--fg));
  }

  .state--done {
    background: rgb(46 229 157 / 0.15);
    color: color-mix(in srgb, var(--status-approved) var(--status-ink, 100%), var(--fg));
  }

  .state--refused {
    background: rgb(255 107 107 / 0.16);
    color: color-mix(in srgb, var(--status-refused) var(--status-ink, 100%), var(--fg));
  }

  .refuse-form__label {
    font-size: 12.5px;
    font-weight: 600;
  }

  .keys {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 16px;
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 12px;
    color: var(--muted);
  }

  .keys li {
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }

  kbd {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 22px;
    padding: 0 5px;
    border: 1px solid var(--line);
    border-bottom-width: 2px;
    border-radius: 5px;
    background: var(--raised);
    color: var(--fg);
    font-family: var(--font-mono);
    font-size: 11px;
    line-height: 1;
  }

  .kbd--wide {
    min-width: 48px;
  }

  /* Touch screens: no keyboard, no shortcuts. */
  @media (hover: none) {
    .keys {
      display: none;
    }
  }

  .comment__body {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding-left: 38px;
  }

  .text {
    font-size: 13.5px;
    white-space: pre-line;
    overflow-wrap: anywhere;
  }

  .text--deleted {
    color: var(--muted);
    font-style: italic;
  }

  .meta {
    font-size: 11.5px;
    color: var(--muted);
  }

  .muted-text {
    font-size: 12.5px;
    color: var(--muted);
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 2px 12px;
  }

  .link {
    min-height: 32px;
    padding: 0;
    border: none;
    background: none;
    color: var(--muted);
    font-size: 12.5px;
    text-decoration: underline;
    text-underline-offset: 3px;
  }

  .link:hover {
    color: var(--fg);
  }

  .link--danger {
    color: color-mix(in srgb, #e5484d var(--status-ink, 100%), var(--fg));
  }

  .link--strong {
    min-height: 0;
    color: var(--fg);
    font-weight: 600;
  }

  .reply {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-left: 38px;
    padding: 10px 12px;
    border-radius: 8px;
    background: var(--raised);
  }

  .reply-form {
    margin-left: 38px;
  }

  .inline-form {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .inline-form__buttons {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--raised);
    color: var(--fg);
    font-size: 13.5px;
    line-height: 1.5;
    resize: vertical;
  }

  textarea:focus-visible {
    border-color: var(--client-accent);
  }

  .error {
    margin: 0 20px 10px;
    padding: 8px 12px;
    border-radius: 8px;
    background: rgb(229 72 77 / 0.14);
    color: color-mix(in srgb, #e5484d 75%, var(--fg));
    font-size: 13px;
  }

  .composer {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 16px 20px 20px;
    border-top: 1px solid var(--line);
  }

  .composer__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
  }

  .modes {
    display: flex;
    gap: 2px;
    padding: 3px;
    border: 1px solid var(--line);
    border-radius: var(--radius-pill);
    background: var(--raised);
  }

  .mode {
    height: 30px;
    padding: 0 12px;
    border: none;
    border-radius: var(--radius-pill);
    background: transparent;
    color: var(--muted);
    font-size: 12.5px;
    font-weight: 500;
  }

  .mode[aria-pressed='true'] {
    background: var(--fg);
    color: var(--client-bg);
  }

  .points {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    font-family: var(--font-mono);
    font-size: 11.5px;
  }

  .point {
    padding: 5px 8px;
    border-radius: 6px;
    background: var(--raised);
    color: var(--muted);
  }

  .point--in {
    color: var(--client-accent-text);
  }

  .point--button {
    border: 1px dashed var(--line);
    font-family: inherit;
    font-size: inherit;
    color: var(--fg);
  }

  .as {
    font-size: 12.5px;
    color: var(--muted);
  }

  .as :global(.wordmark) {
    color: var(--fg);
  }

  .send {
    height: 40px;
    padding: 0 18px;
    border: none;
    border-radius: var(--radius-pill);
    background: var(--client-accent);
    color: var(--client-on-accent);
    font-size: 13px;
    font-weight: 600;
  }

  .send--small {
    height: 34px;
    padding: 0 14px;
    font-size: 12.5px;
  }

  .send:disabled {
    opacity: 0.5;
  }

  .send--danger {
    background: #c9373c;
    color: #fff;
  }
</style>
