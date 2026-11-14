<script lang="ts">
  import { api, ApiError } from '../../lib/api';
  import { formatRelative } from '../../lib/format';
  import { followLink } from '../../lib/router.svelte';
  import Combobox from '../components/Combobox.svelte';
  import DateFilter from '../components/DateFilter.svelte';
  import PageHead from '../components/PageHead.svelte';
  import { unread } from '../badge.svelte';
  import type { Client, Project } from '../types';

  interface Feedback {
    id: number;
    isReply: boolean;
    author: string;
    text: string;
    status: 'todo' | 'done' | 'refused' | null;
    read: boolean;
    timecode: string;
    createdAt: string;
    clientName: string;
    projectName: string;
    videoId: number;
    videoNumber: number;
    videoTitle: string;
    versionId: number;
    versionNumber: number;
  }

  let items = $state<Feedback[] | null>(null);
  let error = $state('');
  let clients = $state<Client[]>([]);
  let projects = $state<Project[]>([]);
  let tags = $state<string[]>([]);

  let q = $state('');
  let clientId = $state('');
  let projectId = $state('');
  let tag = $state('');
  let status = $state('unread');
  let from = $state('');
  let to = $state('');

  const clientProjects = $derived(clientId ? projects.filter((p) => String(p.clientId) === clientId) : projects);

  $effect(() => {
    void unread.refresh();
    api<Client[]>('GET', '/api/admin/clients').then((list) => (clients = list)).catch(() => {});
    api<Project[]>('GET', '/api/admin/projects').then((list) => (projects = list)).catch(() => {});
    api<string[]>('GET', '/api/admin/tags').then((list) => (tags = list)).catch(() => {});
  });

  let latest = 0;

  // Filters → server search (the text after a short pause).
  $effect(() => {
    const params = new URLSearchParams();
    if (q.trim()) params.set('q', q.trim());
    if (clientId) params.set('client', clientId);
    if (projectId) params.set('project', projectId);
    if (tag) params.set('tag', tag);
    params.set('status', status);
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    const ticket = ++latest;
    const timer = setTimeout(() => {
      api<Feedback[]>('GET', `/api/admin/feedback?${params}`)
        // Filters changed quickly: only the answer to the latest request counts.
        .then((list) => {
          if (ticket === latest) items = list;
        })
        .catch((e) => (error = e instanceof ApiError ? e.message : 'Chargement impossible.'));
    }, q ? 250 : 0);
    return () => clearTimeout(timer);
  });

  const reviewUrl = (f: Feedback): string => `/admin/videos/${f.videoId}?version=${f.versionId}&comment=${f.id}`;
  const filtered = $derived(q.trim() !== '' || clientId !== '' || projectId !== '' || tag !== '' || status !== 'unread' || from !== '' || to !== '');
</script>

<PageHead eyebrow="Révisions" title="Révisions des clients" />

<div class="toolbar">
  <div class="search">
    <label class="visually-hidden" for="feedback-search">Rechercher</label>
    <input id="feedback-search" type="search" placeholder="Rechercher un client, une vidéo, un tag…" bind:value={q} />
  </div>
  <label class="visually-hidden" for="feedback-client">Client</label>
  <Combobox
    id="feedback-client"
    bind:value={clientId}
    onchange={() => (projectId = '')}
    options={[{ value: '', label: 'Client : tous' }, ...clients.map((c) => ({ value: String(c.id), label: c.name }))]}
  />
  <label class="visually-hidden" for="feedback-project">Projet</label>
  <Combobox
    id="feedback-project"
    bind:value={projectId}
    options={[{ value: '', label: 'Projet : tous' }, ...clientProjects.map((p) => ({ value: String(p.id), label: p.name, group: p.clientName }))]}
  />
  <label class="visually-hidden" for="feedback-tag">Tag</label>
  <Combobox id="feedback-tag" bind:value={tag} options={[{ value: '', label: 'Tag : tous' }, ...tags.map((name) => ({ value: name, label: name }))]} />
  <label class="visually-hidden" for="feedback-status">Statut</label>
  <select class="select" id="feedback-status" bind:value={status}>
    <option value="unread">Statut : non lus</option>
    <option value="todo">À faire</option>
    <option value="done">Fait</option>
    <option value="refused">Refusé</option>
    <option value="all">Tous</option>
  </select>
  <DateFilter id="feedback-date" bind:from bind:to />
</div>

{#if error}
  <p class="form-error" role="alert">{error}</p>
{:else if items === null}
  <p class="loading">Chargement…</p>
{:else if items.length === 0}
  <div class="empty">
    <p class="empty__title">{filtered ? 'Aucun résultat' : 'Tout est lu'}</p>
    <p class="empty__text">
      {filtered ? 'Aucune révision ne correspond à ces critères.' : 'Les nouvelles révisions et réponses de vos clients apparaîtront ici.'}
    </p>
  </div>
{:else}
  <p class="visually-hidden" role="status">{items.length} révision{items.length > 1 ? 's' : ''}</p>
  <div class="table-wrap">
    <table class="table feedback">
      <thead>
        <tr><th>Client</th><th>Vidéo</th><th>Timecode</th><th>Révision</th><th>Reçu</th><th><span class="visually-hidden">Action</span></th></tr>
      </thead>
      <tbody>
        {#each items as item (item.id)}
          <tr>
            <td class="feedback__client">{item.clientName}</td>
            <td>
              {item.videoTitle}
              <span class="table__sub mono">N° {item.videoNumber} · V{item.versionNumber}</span>
            </td>
            <td class="mono feedback__tc">{item.timecode}</td>
            <td class="feedback__text">
              <span class="feedback__excerpt">{item.isReply ? 'Réponse : ' : ''}{item.text}</span>
              <span class="table__sub">
                {item.author}{#if !item.read} · <span class="feedback__new">non lu</span>{/if}{#if item.status} · {{ todo: 'À faire', done: 'Fait', refused: 'Refusé' }[item.status]}{/if}
              </span>
            </td>
            <td class="muted">{formatRelative(item.createdAt)}</td>
            <td><a class="btn btn--small" href={reviewUrl(item)} onclick={followLink}>Répondre</a></td>
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}

<style>
  .feedback__client {
    font-weight: 500;
  }

  .feedback__tc {
    color: var(--accent-text);
    white-space: nowrap;
  }

  .feedback__text {
    max-width: 420px;
  }

  .feedback__excerpt {
    display: -webkit-box;
    overflow: hidden;
    color: var(--text-soft);
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
  }

  .feedback__new {
    color: var(--accent-text);
  }
</style>
