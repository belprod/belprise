<script lang="ts">
  import { api, ApiError } from '../../lib/api';
  import { formatDate } from '../../lib/format';
  import { followLink, navigate, queryId } from '../../lib/router.svelte';
  import ConfirmDialog from '../components/ConfirmDialog.svelte';
  import Combobox from '../components/Combobox.svelte';
  import DropImport from '../components/DropImport.svelte';
  import PageHead from '../components/PageHead.svelte';
  import type { Client, Project, Video } from '../types';

  /** null: creation page */
  let { id }: { id: number | null } = $props();

  let project = $state<Project | null>(null);
  let videos = $state<Video[]>([]);
  let clients = $state<Client[]>([]);
  let loadError = $state('');

  let name = $state('');
  let clientId = $state(queryId('client') === null ? '' : String(queryId('client')));
  let saving = $state(false);
  let saved = $state(false);
  let formError = $state('');
  let confirmDelete = $state(false);

  $effect(() => {
    if (id === null) {
      api<Client[]>('GET', '/api/admin/clients').then((list) => (clients = list)).catch(() => (loadError = 'Chargement impossible.'));
      return;
    }
    const projectId = id;
    Promise.all([
      api<Project>('GET', `/api/admin/projects/${projectId}`),
      api<Video[]>('GET', `/api/admin/videos?project=${projectId}&archived=1`),
    ])
      .then(([loaded, list]) => {
        project = loaded;
        name = loaded.name;
        videos = list;
      })
      .catch((e) => (loadError = e instanceof ApiError && e.status === 404 ? 'Ce projet n’existe pas.' : 'Chargement impossible.'));
  });

  async function save(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    saving = true;
    saved = false;
    formError = '';
    try {
      if (project === null) {
        const created = await api<Project>('POST', '/api/admin/projects', { clientId: Number(clientId) || 0, name });
        navigate(`/admin/projects/${created.id}`, { replace: true });
      } else {
        project = await api<Project>('PATCH', `/api/admin/projects/${project.id}`, { name });
        saved = true;
      }
    } catch (e) {
      formError = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
    } finally {
      saving = false;
    }
  }

  async function setArchived(archived: boolean): Promise<void> {
    if (project === null) return;
    project = await api<Project>('PATCH', `/api/admin/projects/${project.id}`, { archived });
  }

  async function remove(): Promise<void> {
    if (project === null) return;
    await api('DELETE', `/api/admin/projects/${project.id}`);
    navigate(`/admin/clients/${project.clientId}`, { replace: true });
  }
</script>

{#if loadError}
  <PageHead eyebrow="Projet" title="Introuvable" back={{ href: '/admin/projects', label: 'Projets' }} />
  <p class="form-error" role="alert">{loadError}</p>
{:else if id !== null && project === null}
  <p class="loading">Chargement…</p>
{:else}
  <PageHead
    eyebrow={project ? `Projet · ${project.clientName}${project.archived ? ' · archivé' : ''}` : 'Projets'}
    title={project ? project.name : 'Nouveau projet'}
    back={project ? { href: `/admin/clients/${project.clientId}`, label: project.clientName } : { href: '/admin/projects', label: 'Projets' }}
  />

  <form class="panel narrow" onsubmit={save} novalidate>
    <div class="form-grid">
      {#if project === null}
        <div class="field">
          <label class="field__label" for="project-client">Client</label>
          <Combobox
            id="project-client"
            field
            required
            placeholder="Choisir un client"
            bind:value={clientId}
            options={clients.map((c) => ({ value: String(c.id), label: c.name }))}
          />
          {#if clients.length === 0}
            <p class="field__hint">Aucun client : <a href="/admin/clients/new" onclick={followLink}>créez-en un</a> d’abord.</p>
          {/if}
        </div>
      {/if}
      <div class="field">
        <label class="field__label" for="project-name">Nom du projet</label>
        <input class="input" id="project-name" required maxlength="160" bind:value={name} />
      </div>
    </div>
    {#if formError}<p class="form-error" role="alert">{formError}</p>{/if}
    <div class="form-actions can-edit">
      <button class="btn btn--primary" type="submit" disabled={saving}>{project ? 'Enregistrer' : 'Créer le projet'}</button>
      {#if saved}<span class="saved" role="status">Enregistré</span>{/if}
    </div>
  </form>

  {#if project}
    <section class="panel" aria-labelledby="videos-title">
      <div class="panel__head">
        <h2 class="panel__title" id="videos-title">Vidéos</h2>
        <a class="btn btn--small btn--icon-plus can-edit" href="/admin/videos/new?project={project.id}" onclick={followLink}>Nouvelle vidéo</a>
      </div>
      <DropImport
        projectId={project.id}
        onCreated={() => api<Video[]>('GET', `/api/admin/videos?project=${project!.id}&archived=1`).then((list) => (videos = list)).catch(() => {})}
      />
      {#if videos.length === 0}
        <p class="panel__text">Aucune vidéo pour l’instant.</p>
      {:else}
        <ul class="video-list">
          {#each videos as video (video.id)}
            <li>
              <span class="mono muted">N° {video.number}</span>
              <a class="table__main" href="/admin/videos/{video.id}" onclick={followLink}>{video.title}</a>
              <span class="muted">· {formatDate(video.createdAt)}</span>
              {#if video.archived}<span class="pill pill--off">Archivée</span>{/if}
            </li>
          {/each}
        </ul>
      {/if}
    </section>

    <section class="panel panel--danger" aria-labelledby="danger-title">
      <h2 class="panel__title" id="danger-title">Archivage et suppression</h2>
      <p class="panel__text">
        Un projet archivé disparaît des listes ; ses liens continuent de fonctionner. La suppression efface ses vidéos, leurs
        fichiers et leurs liens, définitivement.
      </p>
      <div class="form-actions">
        <button class="btn btn--small can-edit" type="button" onclick={() => setArchived(!project!.archived)}>
          {project.archived ? 'Désarchiver' : 'Archiver'}
        </button>
        <button class="btn btn--small can-delete" type="button" onclick={() => (confirmDelete = true)}>Supprimer le projet…</button>
      </div>
    </section>

    <ConfirmDialog bind:open={confirmDelete} title="Supprimer ce projet ?" confirmLabel="Supprimer définitivement" danger onConfirm={remove}>
      <p>
        « {project.name} » et ses {videos.length} vidéo(s) seront supprimés, avec leurs fichiers, leurs révisions et leurs liens.
        Cette action est irréversible.
      </p>
    </ConfirmDialog>
  {/if}
{/if}

<style>
  .narrow {
    max-width: 640px;
  }

  .video-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 13.5px;
  }

  .video-list li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }
</style>
