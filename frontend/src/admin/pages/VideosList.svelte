<script lang="ts">
  import { api, ApiError } from '../../lib/api';
  import { formatDate } from '../../lib/format';
  import { followLink } from '../../lib/router.svelte';
  import Combobox from '../components/Combobox.svelte';
  import DropImport from '../components/DropImport.svelte';
  import DateFilter from '../components/DateFilter.svelte';
  import PageHead from '../components/PageHead.svelte';
  import { VERSION_STATUS } from '../status';
  import type { Client, Project, Video } from '../types';

  let videos = $state<Video[] | null>(null);
  let clients = $state<Client[]>([]);
  let projects = $state<Project[]>([]);
  let tags = $state<string[]>([]);
  let error = $state('');
  let query = $state('');
  let clientId = $state('');
  let projectId = $state('');
  let tag = $state('');
  let status = $state('');
  let from = $state('');
  let to = $state('');
  let showArchived = $state(false);

  const clientProjects = $derived(clientId ? projects.filter((p) => String(p.clientId) === clientId) : projects);
  const visible = $derived(videos ?? []);

  $effect(() => {
    api<Client[]>('GET', '/api/admin/clients?archived=1').then((list) => (clients = list)).catch(() => {});
    api<Project[]>('GET', '/api/admin/projects?archived=1').then((list) => (projects = list)).catch(() => {});
    api<string[]>('GET', '/api/admin/tags').then((list) => (tags = list)).catch(() => {});
  });

  let latest = 0;
  let refresh = $state(0);

  // Search (§5.7): free text (title, n°, client, project, tag) and filters, done by the server.
  $effect(() => {
    const params = new URLSearchParams();
    if (query.trim()) params.set('q', query.trim());
    if (clientId) params.set('client', clientId);
    if (projectId) params.set('project', projectId);
    if (tag) params.set('tag', tag);
    if (status) params.set('status', status);
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    if (showArchived) params.set('archived', '1');
    void refresh; // a master dropped below created a video
    const ticket = ++latest;
    const timer = setTimeout(() => {
      api<Video[]>('GET', `/api/admin/videos?${params}`)
        // Filters changed quickly: only the answer to the latest request counts.
        .then((list) => {
          if (ticket === latest) videos = list;
        })
        .catch((e) => (error = e instanceof ApiError ? e.message : 'Chargement impossible.'));
    }, query ? 250 : 0);
    return () => clearTimeout(timer);
  });
</script>

<PageHead eyebrow="Vidéos" title="Vidéos">
  {#snippet actions()}
    <a class="btn btn--primary btn--icon-plus can-edit" href="/admin/videos/new" onclick={followLink}>Nouvelle vidéo</a>
  {/snippet}
</PageHead>

<DropImport onCreated={() => refresh++} />

<div class="toolbar">
  <div class="search">
    <label class="visually-hidden" for="videos-search">Rechercher une vidéo</label>
    <input id="videos-search" type="search" placeholder="Titre, n°, client, projet, tag…" bind:value={query} />
  </div>
  <label class="visually-hidden" for="videos-client">Client</label>
  <Combobox
    id="videos-client"
    bind:value={clientId}
    onchange={() => (projectId = '')}
    options={[{ value: '', label: 'Client : tous' }, ...clients.map((c) => ({ value: String(c.id), label: c.name }))]}
  />
  <label class="visually-hidden" for="videos-project">Projet</label>
  <Combobox
    id="videos-project"
    bind:value={projectId}
    options={[{ value: '', label: 'Projet : tous' }, ...clientProjects.map((p) => ({ value: String(p.id), label: p.name, group: p.clientName }))]}
  />
  <label class="visually-hidden" for="videos-tag">Tag</label>
  <Combobox id="videos-tag" bind:value={tag} options={[{ value: '', label: 'Tag : tous' }, ...tags.map((name) => ({ value: name, label: name }))]} />
  <label class="visually-hidden" for="videos-status">Statut</label>
  <select class="select" id="videos-status" bind:value={status}>
    <option value="">Statut : tous</option>
    {#each Object.entries(VERSION_STATUS) as [value, label] (value)}
      {#if value !== 'in_progress'}<option {value}>{label}</option>{/if}
    {/each}
    <option value="unpublished">Non publiée</option>
  </select>
  <DateFilter id="videos-date" bind:from bind:to />
  <label class="check"><input type="checkbox" bind:checked={showArchived} /> Archivées</label>
</div>

{#if error}
  <p class="form-error" role="alert">{error}</p>
{:else if videos === null}
  <p class="loading">Chargement…</p>
{:else if videos.length === 0 && !query && !clientId && !tag && !status && !from && !to && !projectId}
  <div class="empty">
    <p class="empty__title">Aucune vidéo</p>
    <p class="empty__text">Chaque vidéo reçoit un numéro unique, à partir de 1001.</p>
  </div>
{:else}
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>N°</th><th>Titre</th><th>Client / projet</th><th>Version</th><th>Tags</th><th>Activité</th></tr>
      </thead>
      <tbody>
        {#each visible as video (video.id)}
          <tr>
            <td class="mono">{video.number}</td>
            <td>
              <a class="table__main" href="/admin/videos/{video.id}" onclick={followLink}>{video.title}</a>
              {#if video.archived || video.parentArchived}<span class="pill pill--off">Archivée</span>{/if}
            </td>
            <td>
              {video.clientName}
              <span class="table__sub">{video.projectName}</span>
            </td>
            <td>
              {#if video.lastVersion}
                <span class="version-cell">
                  <span class="mono">V{video.lastVersion}</span>
                  {#if video.status}<span class="status status--{video.status}">{VERSION_STATUS[video.status]}</span>{:else}<span class="muted">non publiée</span>{/if}
                </span>
              {:else}
                <span class="muted">—</span>
              {/if}
            </td>
            <td>
              <span class="tags">{#each video.tags as tag (tag)}<span class="tag">{tag}</span>{/each}</span>
            </td>
            <td class="muted">{formatDate(video.lastActivityAt)}</td>
          </tr>
        {:else}
          <tr><td colspan="6" class="muted">Aucune vidéo ne correspond à ces critères.</td></tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}

<style>
  .version-cell {
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }
</style>
