<script lang="ts">
  import { api, ApiError } from '../../lib/api';
  import { formatDate, normalize } from '../../lib/format';
  import { followLink } from '../../lib/router.svelte';
  import Combobox from '../components/Combobox.svelte';
  import PageHead from '../components/PageHead.svelte';
  import type { Client, Project } from '../types';

  let projects = $state<Project[] | null>(null);
  let clients = $state<Client[]>([]);
  let error = $state('');
  let query = $state('');
  let clientId = $state('');
  let showArchived = $state(false);

  const visible = $derived(
    (projects ?? []).filter((p) => normalize(`${p.name} ${p.clientName}`).includes(normalize(query.trim()))),
  );

  $effect(() => {
    api<Client[]>('GET', '/api/admin/clients?archived=1').then((list) => (clients = list)).catch(() => {});
  });

  $effect(() => {
    const params = new URLSearchParams();
    if (clientId) params.set('client', clientId);
    if (showArchived) params.set('archived', '1');
    api<Project[]>('GET', `/api/admin/projects?${params}`)
      .then((list) => (projects = list))
      .catch((e) => (error = e instanceof ApiError ? e.message : 'Chargement impossible.'));
  });
</script>

<PageHead eyebrow="Projets" title="Projets">
  {#snippet actions()}
    <a class="can-edit btn btn--primary btn--icon-plus" href="/admin/projects/new{clientId ? `?client=${clientId}` : ''}" onclick={followLink}>
      Nouveau projet
    </a>
  {/snippet}
</PageHead>

<div class="toolbar">
  <div class="search">
    <label class="visually-hidden" for="projects-search">Rechercher un projet</label>
    <input id="projects-search" type="search" placeholder="Rechercher un projet ou un client…" bind:value={query} />
  </div>
  <label class="visually-hidden" for="projects-client">Client</label>
  <Combobox
    id="projects-client"
    bind:value={clientId}
    options={[{ value: '', label: 'Client : tous' }, ...clients.map((c) => ({ value: String(c.id), label: c.name }))]}
  />
  <label class="check"><input type="checkbox" bind:checked={showArchived} /> Afficher les archivés</label>
</div>

{#if error}
  <p class="form-error" role="alert">{error}</p>
{:else if projects === null}
  <p class="loading">Chargement…</p>
{:else if projects.length === 0}
  <div class="empty">
    <p class="empty__title">Aucun projet</p>
    <p class="empty__text">Un projet regroupe les vidéos d’un client.</p>
  </div>
{:else}
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>Projet</th><th>Client</th><th>Vidéos</th><th>Créé le</th></tr>
      </thead>
      <tbody>
        {#each visible as project (project.id)}
          <tr>
            <td>
              <a class="table__main" href="/admin/projects/{project.id}" onclick={followLink}>{project.name}</a>
              {#if project.archived || project.clientArchived}<span class="pill pill--off">Archivé</span>{/if}
            </td>
            <td><a class="table__main" href="/admin/clients/{project.clientId}" onclick={followLink}>{project.clientName}</a></td>
            <td class="table__num">{project.videoCount}</td>
            <td class="muted">{formatDate(project.createdAt)}</td>
          </tr>
        {:else}
          <tr><td colspan="4" class="muted">Aucun projet ne correspond à « {query} ».</td></tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}
