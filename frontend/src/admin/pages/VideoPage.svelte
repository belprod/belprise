<script lang="ts">
  import { api, ApiError } from '../../lib/api';
  import { formatBytes } from '../../lib/format';
  import { followLink, navigate, queryId } from '../../lib/router.svelte';
  import ConfirmDialog from '../components/ConfirmDialog.svelte';
  import LinkPanel from '../components/LinkPanel.svelte';
  import Combobox from '../components/Combobox.svelte';
  import PageHead from '../components/PageHead.svelte';
  import TagInput from '../components/TagInput.svelte';
  import ReviewPlayer from '../versions/ReviewPlayer.svelte';
  import VersionsPanel from '../versions/VersionsPanel.svelte';
  import type { Project, Video } from '../types';

  /** null: creation page */
  let { id }: { id: number | null } = $props();

  let video = $state<Video | null>(null);
  let projects = $state<Project[]>([]);
  let suggestions = $state<string[]>([]);
  let loadError = $state('');

  let title = $state('');
  let projectId = $state(queryId('project') === null ? '' : String(queryId('project')));
  let tags = $state<string[]>([]);
  let downloadAllowed = $state(true);
  let saving = $state(false);
  let saved = $state(false);
  let formError = $state('');
  let invalidField = $state('');
  let confirmDelete = $state(false);
  let confirmArchive = $state(false);
  // Bumped after archiving: the versions and the review reload.
  let versionsKey = $state(0);
  let archivePlan = $state<{ possible: boolean; keep: { number: number; status: string } | null; delete: number[]; revisions: number; freedBytes: number } | null>(null);

  // Projects grouped by client for the project list.
  const groups = $derived.by(() => {
    const map = new Map<string, Project[]>();
    for (const project of projects) {
      map.set(project.clientName, [...(map.get(project.clientName) ?? []), project]);
    }
    return [...map.entries()].sort(([a], [b]) => a.localeCompare(b, 'fr'));
  });

  $effect(() => {
    api<string[]>('GET', '/api/admin/tags').then((list) => (suggestions = list)).catch(() => {});
    if (id === null) {
      api<Project[]>('GET', '/api/admin/projects').then((list) => (projects = list)).catch(() => (loadError = 'Chargement impossible.'));
      return;
    }
    api<Video>('GET', `/api/admin/videos/${id}`)
      .then(fill)
      .catch((e) => (loadError = e instanceof ApiError && e.status === 404 ? 'Cette vidéo n’existe pas.' : 'Chargement impossible.'));
  });

  function fill(loaded: Video): void {
    video = loaded;
    title = loaded.title;
    tags = loaded.tags;
    downloadAllowed = loaded.downloadAllowed;
  }

  async function save(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    saving = true;
    saved = false;
    formError = '';
    invalidField = '';
    try {
      if (video === null) {
        const created = await api<Video>('POST', '/api/admin/videos', {
          projectId: Number(projectId) || 0,
          title,
          tags,
          downloadAllowed,
        });
        navigate(`/admin/videos/${created.id}`, { replace: true });
      } else {
        fill(await api<Video>('PATCH', `/api/admin/videos/${video.id}`, { title, tags, downloadAllowed }));
        saved = true;
      }
    } catch (e) {
      formError = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
      invalidField = e instanceof ApiError && typeof e.data.field === 'string' ? e.data.field : '';
    } finally {
      saving = false;
    }
  }

  async function setArchived(archived: boolean): Promise<void> {
    if (video === null) return;
    fill(await api<Video>('PATCH', `/api/admin/videos/${video.id}`, { archived }));
  }

  /** Archiving deletes for good: what it keeps and deletes is shown first. */
  async function askArchive(): Promise<void> {
    if (video === null) return;
    archivePlan = await api('GET', `/api/admin/videos/${video.id}/archive`);
    confirmArchive = true;
  }

  async function archive(): Promise<void> {
    if (video === null) return;
    fill(await api<Video>('POST', `/api/admin/videos/${video.id}/archive`));
    versionsKey++;
  }

  async function remove(): Promise<void> {
    if (video === null) return;
    await api('DELETE', `/api/admin/videos/${video.id}`);
    navigate(`/admin/projects/${video.projectId}`, { replace: true });
  }
</script>

{#if loadError}
  <PageHead eyebrow="Vidéo" title="Introuvable" back={{ href: '/admin/videos', label: 'Vidéos' }} />
  <p class="form-error" role="alert">{loadError}</p>
{:else if id !== null && video === null}
  <p class="loading">Chargement…</p>
{:else}
  <PageHead
    eyebrow={video ? `N° ${video.number} · ${video.clientName}${video.archived ? ' · archivée' : ''}` : 'Vidéos'}
    title={video ? video.title : 'Nouvelle vidéo'}
    back={video ? { href: `/admin/projects/${video.projectId}`, label: video.projectName } : { href: '/admin/videos', label: 'Vidéos' }}
  />

  {#if video}
    {#key versionsKey}<ReviewPlayer {video} />{/key}
  {/if}

  <div class="panels">
    <form class="panel" onsubmit={save} novalidate>
      <h2 class="panel__title">{video ? 'Fiche' : 'Informations'}</h2>
      <div class="form-grid">
        {#if video === null}
          <div class="field">
            <label class="field__label" for="video-project">Projet</label>
            <Combobox
              id="video-project"
              field
              required
              invalid={invalidField === 'projectId'}
              placeholder="Choisir un projet"
              bind:value={projectId}
              options={groups.flatMap(([clientName, list]) => list.map((p) => ({ value: String(p.id), label: p.name, group: clientName })))}
            />
            {#if projects.length === 0}
              <p class="field__hint">Aucun projet : <a href="/admin/projects/new" onclick={followLink}>créez-en un</a> d’abord.</p>
            {/if}
          </div>
        {/if}
        <div class="field">
          <label class="field__label" for="video-title">Titre</label>
          <input class="input" id="video-title" required maxlength="160" aria-invalid={invalidField === 'title'} bind:value={title} />
        </div>
        <div class="field">
          <label class="field__label" for="video-tags">Tags</label>
          <TagInput id="video-tags" bind:tags {suggestions} />
          <p class="field__hint">Entrée ou virgule pour ajouter un tag.</p>
        </div>
        <label class="check"><input type="checkbox" bind:checked={downloadAllowed} /> Téléchargement du master autorisé</label>
      </div>
      {#if formError}<p class="form-error" role="alert">{formError}</p>{/if}
      <div class="form-actions can-edit">
        <button class="btn btn--primary" type="submit" disabled={saving}>{video ? 'Enregistrer' : 'Créer la vidéo'}</button>
        {#if saved}<span class="saved" role="status">Enregistré</span>{/if}
      </div>
    </form>

    {#if video?.links}
      <div class="stack">
        <LinkPanel
          bind:link={video.links.client}
          title="Lien Client"
          description="Versions, révisions, validation et téléchargement du master."
        />
        <LinkPanel
          bind:link={video.links.view}
          title="Lien Visualisation"
          description="La dernière version validée, ou à défaut la dernière publiée. Rien d’autre."
        />
      </div>
    {/if}
  </div>

  {#if video}
    {#key versionsKey}<VersionsPanel {video} />{/key}

    <section class="panel panel--danger" aria-labelledby="danger-title">
      <h2 class="panel__title" id="danger-title">Archivage et suppression</h2>
      <p class="panel__text">
        Archiver garde une seule version, la dernière validée (ou à défaut la dernière publiée), et supprime les autres versions
        et toutes les révisions. La vidéo passe dans la bibliothèque du client. La suppression efface tout, liens compris.
      </p>
      <div class="form-actions">
        {#if video.archived}
          <button class="btn btn--small can-edit" type="button" onclick={() => setArchived(false)}>Désarchiver</button>
        {:else}
          <button class="btn btn--small can-edit" type="button" onclick={askArchive}>Archiver…</button>
        {/if}
        <button class="btn btn--small can-delete" type="button" onclick={() => (confirmDelete = true)}>Supprimer la vidéo…</button>
      </div>
    </section>

    <ConfirmDialog
      bind:open={confirmArchive}
      title="Archiver cette vidéo ?"
      confirmLabel={archivePlan?.possible ? 'Archiver définitivement' : 'Fermer'}
      danger={archivePlan?.possible ?? false}
      onConfirm={() => (archivePlan?.possible ? archive() : Promise.resolve())}
    >
      {#if archivePlan?.possible && archivePlan.keep}
        <p>
          La <strong>V{archivePlan.keep.number}</strong>{archivePlan.keep.status === 'approved' ? ' (validée)' : ''} reste dans la
          bibliothèque du client, à revoir et à télécharger.
        </p>
        <p>
          Supprimé définitivement :
          {archivePlan.delete.length > 0 ? archivePlan.delete.map((n) => `V${n}`).join(', ') : 'aucune autre version'},
          {archivePlan.revisions} révision{archivePlan.revisions > 1 ? 's' : ''}.
          {#if archivePlan.freedBytes > 0}<strong>{formatBytes(archivePlan.freedBytes)}</strong> libérés.{/if}
        </p>
        <p>Cette action est irréversible.</p>
      {:else}
        <p>Cette vidéo n’a aucune version publiée : il n’y a rien à garder dans la bibliothèque du client. Publiez d’abord une version.</p>
      {/if}
    </ConfirmDialog>

    <ConfirmDialog bind:open={confirmDelete} title="Supprimer cette vidéo ?" confirmLabel="Supprimer définitivement" danger onConfirm={remove}>
      <p>
        « {video.title} » (n° {video.number}), ses versions, leurs fichiers et ses révisions seront supprimés. Ses liens cesseront
        de fonctionner. Cette action est irréversible.
      </p>
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
</style>
