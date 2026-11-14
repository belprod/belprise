<script lang="ts">
  import { api, ApiError, upload } from '../../lib/api';
  import { AA_TEXT, contrastRatio, formatRatio } from '../../lib/contrast';
  import { plural } from '../../lib/format';
  import { navigate } from '../../lib/router.svelte';
  import ColorField from '../components/ColorField.svelte';
  import Combobox from '../components/Combobox.svelte';
  import ConfirmDialog from '../components/ConfirmDialog.svelte';
  import LinkPanel from '../components/LinkPanel.svelte';
  import PageHead from '../components/PageHead.svelte';
  import { current } from '../current.svelte';
  import type { Collection, CollectionVideo, Video } from '../types';

  /** null: creation page */
  let { id }: { id: number | null } = $props();

  // A new collection starts with the organization's identity (§5.9).
  const organization = current.session?.organization;
  const DEFAULTS =
    organization?.theme === 'light'
      ? { bgColor: '#f4f4f5', textColor: '#141416', accentColor: organization.accentColor }
      : { bgColor: '#0a0a0b', textColor: '#fafafa', accentColor: organization?.accentColor ?? '#e8515f' };
  const MAX_VIDEOS = 60;

  let collection = $state<Collection | null>(null);
  let loadError = $state('');

  let title = $state('');
  let introduction = $state('');
  let bgColor = $state(DEFAULTS.bgColor);
  let textColor = $state(DEFAULTS.textColor);
  let accentColor = $state(DEFAULTS.accentColor);
  let saving = $state(false);
  let saved = $state(false);
  let formError = $state('');
  let invalidField = $state('');

  let logoBusy = $state(false);
  let logoError = $state('');
  let confirmDelete = $state(false);

  // Selection
  let catalog = $state<Video[]>([]);
  let tags = $state<string[]>([]);
  let tag = $state('');
  let pick = $state('');
  let videos = $state<CollectionVideo[]>([]);
  let orderBusy = $state(false);
  let orderError = $state('');
  let orderStatus = $state('');
  let dragFrom = $state<number | null>(null);
  let dragOver = $state<number | null>(null);

  const ratio = $derived(contrastRatio(textColor, bgColor));
  const chosen = $derived(new Set(videos.map((v) => v.id)));
  // Only videos with a published version can be shown to a prospect.
  const candidates = $derived(
    catalog.filter(
      (v) => v.status !== null && !chosen.has(v.id) && (tag === '' || v.tags.some((t) => t.localeCompare(tag, 'fr', { sensitivity: 'base' }) === 0)),
    ),
  );

  $effect(() => {
    if (id === null) return;
    const collectionId = id;
    Promise.all([
      api<Collection>('GET', `/api/admin/collections/${collectionId}`),
      api<Video[]>('GET', '/api/admin/videos?archived=1'),
      api<string[]>('GET', '/api/admin/tags').catch(() => [] as string[]),
    ])
      .then(([loaded, list, tagList]) => {
        fill(loaded);
        catalog = list;
        tags = tagList;
      })
      .catch((e) => (loadError = e instanceof ApiError && e.status === 404 ? 'Cette collection n’existe pas.' : 'Chargement impossible.'));
  });

  function fill(loaded: Collection): void {
    collection = loaded;
    title = loaded.title;
    introduction = loaded.introduction;
    bgColor = loaded.bgColor;
    textColor = loaded.textColor;
    accentColor = loaded.accentColor;
    videos = loaded.videos;
  }

  async function save(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    saving = true;
    saved = false;
    formError = '';
    invalidField = '';
    const body = { title, introduction, bgColor, textColor, accentColor };
    try {
      if (collection === null) {
        const created = await api<Collection>('POST', '/api/admin/collections', body);
        navigate(`/admin/collections/${created.id}`, { replace: true });
      } else {
        fill(await api<Collection>('PATCH', `/api/admin/collections/${collection.id}`, body));
        saved = true;
      }
    } catch (e) {
      formError = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
      invalidField = e instanceof ApiError && typeof e.data.field === 'string' ? e.data.field : '';
    } finally {
      saving = false;
    }
  }

  /** Saves the selection in this order; the server answers with what prospects will see. */
  async function saveVideos(next: CollectionVideo[], message: string): Promise<void> {
    if (collection === null) return;
    const previous = videos;
    videos = next;
    orderBusy = true;
    orderError = '';
    orderStatus = '';
    try {
      const updated = await api<Collection>('PATCH', `/api/admin/collections/${collection.id}`, { videoIds: next.map((v) => v.id) });
      collection = updated;
      videos = updated.videos;
      orderStatus = message;
    } catch (e) {
      videos = previous;
      orderError = e instanceof ApiError ? e.message : 'Enregistrement impossible.';
    } finally {
      orderBusy = false;
    }
  }

  function add(value: string): void {
    const video = catalog.find((v) => String(v.id) === value);
    pick = '';
    if (!video || chosen.has(video.id)) return;
    if (videos.length >= MAX_VIDEOS) {
      orderError = `Une collection compte au plus ${MAX_VIDEOS} vidéos.`;
      return;
    }
    const entry: CollectionVideo = {
      id: video.id,
      number: video.number,
      title: video.title,
      clientName: video.clientName,
      projectName: video.projectName,
      version: video.lastVersion,
      approved: false,
      thumbUrl: null,
    };
    void saveVideos([...videos, entry], `« ${video.title} » ajoutée.`);
  }

  function move(from: number, to: number): void {
    if (from === to || to < 0 || to >= videos.length) return;
    const next = [...videos];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item!);
    void saveVideos(next, `« ${item!.title} » déplacée en position ${to + 1}.`);
  }

  function removeVideo(index: number): void {
    const item = videos[index]!;
    void saveVideos(videos.filter((_, i) => i !== index), `« ${item.title} » retirée.`);
  }

  function onDragStart(event: DragEvent, index: number): void {
    dragFrom = index;
    event.dataTransfer?.setData('text/plain', String(index));
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
  }

  function onDragOver(event: DragEvent, index: number): void {
    if (dragFrom === null) return;
    event.preventDefault();
    dragOver = index;
  }

  function onDrop(event: DragEvent, index: number): void {
    event.preventDefault();
    const from = dragFrom;
    dragFrom = null;
    dragOver = null;
    if (from !== null) move(from, index);
  }

  function onDragEnd(): void {
    dragFrom = null;
    dragOver = null;
  }

  async function uploadLogo(event: Event & { currentTarget: HTMLInputElement }): Promise<void> {
    const file = event.currentTarget.files?.[0];
    event.currentTarget.value = '';
    if (!file || collection === null) return;
    logoBusy = true;
    logoError = '';
    const form = new FormData();
    form.append('logo', file);
    try {
      fill(await upload<Collection>(`/api/admin/collections/${collection.id}/logo`, form));
    } catch (e) {
      logoError = e instanceof ApiError ? e.message : 'Envoi impossible.';
    } finally {
      logoBusy = false;
    }
  }

  async function removeLogo(): Promise<void> {
    if (collection === null) return;
    logoBusy = true;
    try {
      fill(await api<Collection>('DELETE', `/api/admin/collections/${collection.id}/logo`));
    } finally {
      logoBusy = false;
    }
  }

  async function remove(): Promise<void> {
    if (collection === null) return;
    await api('DELETE', `/api/admin/collections/${collection.id}`);
    navigate('/admin/collections', { replace: true });
  }
</script>

{#if loadError}
  <PageHead eyebrow="Collection" title="Introuvable" back={{ href: '/admin/collections', label: 'Collections' }} />
  <p class="form-error" role="alert">{loadError}</p>
{:else if id !== null && collection === null}
  <p class="loading">Chargement…</p>
{:else}
  <PageHead
    eyebrow="Collection"
    title={collection ? collection.title : 'Nouvelle collection'}
    back={{ href: '/admin/collections', label: 'Collections' }}
  />

  <div class="panels">
    <form class="panel" onsubmit={save} novalidate>
      <h2 class="panel__title">Présentation</h2>
      <div class="form-grid">
        <div class="field">
          <label class="field__label" for="collection-title">Titre</label>
          <input class="input" id="collection-title" required maxlength="160" aria-invalid={invalidField === 'title'} bind:value={title} />
        </div>
        <div class="field">
          <label class="field__label" for="collection-intro">Introduction</label>
          <textarea class="input textarea" id="collection-intro" maxlength="2000" bind:value={introduction}></textarea>
        </div>
        <div class="colors">
          <ColorField label="Fond" bind:value={bgColor} />
          <ColorField label="Texte" bind:value={textColor} />
          <ColorField label="Accent" bind:value={accentColor} />
        </div>

        <div class="preview" style:background={bgColor} style:color={textColor} aria-hidden="true">
          <span class="preview__eyebrow">// {plural(videos.length, 'vidéo')}</span>
          <span class="preview__title">{title || 'Titre de la collection'}</span>
          <span class="preview__bar" style:background={accentColor}></span>
        </div>
        <p class="contrast" class:contrast--low={ratio < AA_TEXT}>
          {#if ratio < AA_TEXT}
            Contraste insuffisant entre le texte et le fond : {formatRatio(ratio)}, le minimum WCAG AA est 4,5:1.
          {:else}
            Contraste texte / fond : {formatRatio(ratio)} (WCAG AA respecté).
          {/if}
        </p>
      </div>

      {#if formError}
        <p class="form-error" role="alert">{formError}</p>
      {/if}
      <div class="form-actions can-edit">
        <button class="btn btn--primary" type="submit" disabled={saving}>{collection ? 'Enregistrer' : 'Créer la collection'}</button>
        {#if saved}<span class="saved" role="status">Enregistré</span>{/if}
      </div>
    </form>

    {#if collection}
      <div class="stack">
        {#if collection.link}
          <LinkPanel
            bind:link={collection.link}
            title="Lien de la collection"
            description="Lecture seule : les vidéos choisies, sans révision ni téléchargement."
          />
        {/if}

        <section class="panel" aria-labelledby="logo-title">
          <h2 class="panel__title" id="logo-title">Logo</h2>
          <div class="logo-box" style:background={collection.bgColor}>
            {#if collection.logoUrl}
              <img src={collection.logoUrl} alt="Logo de la collection {collection.title}" />
            {:else}
              <span style:color={collection.textColor}>Aucun logo</span>
            {/if}
          </div>
          <p class="panel__text">SVG, PNG ou WebP, 2 Mo au plus. Il remplace la marque Belprise sur la page de la collection.</p>
          {#if logoError}<p class="form-error" role="alert">{logoError}</p>{/if}
          <div class="form-actions can-edit">
            <label class="btn btn--small file-button" class:file-button--busy={logoBusy}>
              {collection.logoUrl ? 'Remplacer' : 'Choisir un fichier'}
              <input
                class="visually-hidden"
                type="file"
                accept=".svg,.png,.webp,image/svg+xml,image/png,image/webp"
                disabled={logoBusy}
                onchange={uploadLogo}
              />
            </label>
            {#if collection.logoUrl}
              <button class="btn btn--small" type="button" disabled={logoBusy} onclick={removeLogo}>Retirer</button>
            {/if}
          </div>
        </section>
      </div>
    {/if}
  </div>

  {#if collection}
    <section class="panel selection" aria-labelledby="selection-title">
      <h2 class="panel__title" id="selection-title">Vidéos <span class="muted">· {videos.length} / {MAX_VIDEOS}</span></h2>
      <p class="panel__text">
        Chaque vidéo montre sa dernière version validée, à défaut sa dernière version publiée.
        Une vidéo sans version publiée n’apparaît pas sur la page.
      </p>

      <div class="picker can-edit">
        <label class="visually-hidden" for="collection-tag">Filtrer par tag</label>
        <Combobox id="collection-tag" bind:value={tag} options={[{ value: '', label: 'Tag : tous' }, ...tags.map((name) => ({ value: name, label: name }))]} />
        <label class="visually-hidden" for="collection-pick">Ajouter une vidéo</label>
        <div class="picker__video">
          <Combobox
            id="collection-pick"
            field
            bind:value={pick}
            placeholder={candidates.length ? `Ajouter une vidéo (${candidates.length} disponibles)…` : 'Aucune autre vidéo publiée'}
            disabled={orderBusy || candidates.length === 0}
            onchange={add}
            options={candidates.map((v) => ({ value: String(v.id), label: `N° ${v.number} · ${v.title}`, group: v.clientName }))}
          />
        </div>
      </div>

      {#if orderError}<p class="form-error" role="alert">{orderError}</p>{/if}
      <p class="visually-hidden" role="status">{orderStatus}</p>

      {#if videos.length === 0}
        <div class="empty">
          <p class="empty__title">Aucune vidéo</p>
          <p class="empty__text">Ajoutez des vidéos publiées, puis glissez-les pour choisir leur ordre.</p>
        </div>
      {:else}
        <ol class="order" aria-label="Ordre des vidéos">
          {#each videos as video, index (video.id)}
            <li
              class="order__item"
              class:order__item--dragging={dragFrom === index}
              class:order__item--over={dragOver === index && dragFrom !== index}
              draggable={!orderBusy}
              ondragstart={(e) => onDragStart(e, index)}
              ondragover={(e) => onDragOver(e, index)}
              ondrop={(e) => onDrop(e, index)}
              ondragend={onDragEnd}
            >
              <span class="order__handle can-edit" aria-hidden="true">⋮⋮</span>
              <span class="order__pos">{index + 1}</span>
              {#if video.thumbUrl}
                <img class="order__thumb" src={video.thumbUrl} alt="" loading="lazy" />
              {:else}
                <span class="order__thumb" aria-hidden="true"></span>
              {/if}
              <span class="order__body">
                <span class="order__title">N° {video.number} · {video.title}</span>
                <span class="muted">{video.clientName} · {video.projectName}</span>
              </span>
              <span class="order__version">
                {#if video.version === null}
                  <span class="pill pill--warn">Non affichée</span>
                {:else}
                  <span class="pill" class:pill--ok={video.approved}>V{video.version}{video.approved ? ' validée' : ''}</span>
                {/if}
              </span>
              <span class="order__actions can-edit">
                <button class="btn btn--small" type="button" disabled={orderBusy || index === 0} onclick={() => move(index, index - 1)} aria-label="Monter « {video.title} »">↑</button>
                <button class="btn btn--small" type="button" disabled={orderBusy || index === videos.length - 1} onclick={() => move(index, index + 1)} aria-label="Descendre « {video.title} »">↓</button>
                <button class="btn btn--small" type="button" disabled={orderBusy} onclick={() => removeVideo(index)} aria-label="Retirer « {video.title} »">Retirer</button>
              </span>
            </li>
          {/each}
        </ol>
      {/if}
    </section>

    <section class="panel danger can-delete" aria-labelledby="danger-title">
      <h2 class="panel__title" id="danger-title">Supprimer</h2>
      <p class="panel__text">Le lien cesse de fonctionner. Les vidéos elles-mêmes ne sont pas touchées.</p>
      <div class="form-actions">
        <button class="btn btn--small" type="button" onclick={() => (confirmDelete = true)}>Supprimer la collection…</button>
      </div>
    </section>

    <ConfirmDialog bind:open={confirmDelete} title="Supprimer « {collection.title} » ?" confirmLabel="Supprimer" danger onConfirm={remove}>
      <p>Le lien de la collection cessera de fonctionner. Les vidéos restent en place.</p>
    </ConfirmDialog>
  {/if}
{/if}

<style>
  .stack {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
  }

  .colors {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
  }

  .preview {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
  }

  .preview__eyebrow {
    font-family: var(--font-mono);
    font-size: 11.5px;
    opacity: 0.7;
  }

  .preview__title {
    font-family: var(--font-display);
    font-size: 28px;
    line-height: 1.1;
  }

  .preview__bar {
    width: 48px;
    height: 3px;
    margin-top: 8px;
    border-radius: 2px;
  }

  .contrast {
    font-size: 13px;
    color: var(--text-muted);
  }

  .contrast--low {
    padding: 10px 14px;
    border-radius: var(--radius-md);
    background: rgb(255 214 10 / 0.12);
    color: color-mix(in srgb, var(--status-changes) var(--status-ink, 100%), var(--text));
  }

  .logo-box {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 120px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-size: 13px;
  }

  .logo-box img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
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

  .selection,
  .danger {
    margin-top: 16px;
  }

  .picker {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
  }

  .picker__video {
    flex: 1 1 320px;
    min-width: 0;
  }

  .order {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .order__item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface-2);
    font-size: 13.5px;
    transition: border-color 0.15s, opacity 0.15s;
  }

  .order__item[draggable='true'] {
    cursor: grab;
  }

  .order__item--dragging {
    opacity: 0.45;
  }

  .order__item--over {
    border-color: var(--accent);
  }

  .order__handle {
    color: var(--text-muted);
    letter-spacing: -2px;
  }

  .order__pos {
    width: 22px;
    font-family: var(--font-mono);
    font-size: 12px;
    color: var(--text-muted);
    text-align: right;
  }

  .order__thumb {
    flex: none;
    width: 72px;
    aspect-ratio: 16 / 9;
    border-radius: var(--radius-sm);
    background: var(--surface-3);
    object-fit: cover;
  }

  .order__body {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .order__title {
    overflow: hidden;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .order__actions {
    display: flex;
    gap: 4px;
  }

  @media (max-width: 640px) {
    .order__item {
      flex-wrap: wrap;
    }

    .order__thumb,
    .order__handle {
      display: none;
    }
  }
</style>
