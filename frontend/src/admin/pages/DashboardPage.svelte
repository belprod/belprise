<script lang="ts">
  import { api, ApiError } from '../../lib/api';
  import { formatBytes, formatRelative } from '../../lib/format';
  import { followLink } from '../../lib/router.svelte';
  import PageHead from '../components/PageHead.svelte';
  import { unread } from '../badge.svelte';
  import { current } from '../current.svelte';
  import type { StorageUsage } from '../session';

  interface RecentVideo {
    id: number;
    number: number;
    title: string;
    context: string;
    summary: string;
    at: string;
    versionId: number | null;
  }

  interface RecentItem {
    id: number;
    name: string;
    context: string | null;
    summary: string;
    at: string;
  }

  interface Dashboard {
    clients: number;
    projects: number;
    videos: number;
    todo: number;
    unread: number;
    storage: StorageUsage;
    recent: { videos: RecentVideo[]; projects: RecentItem[]; clients: RecentItem[] };
  }

  let data = $state<Dashboard | null>(null);
  let error = $state('');

  $effect(() => {
    api<Dashboard>('GET', '/api/admin/dashboard')
      .then((d) => {
        data = d;
        unread.count = d.unread;
      })
      .catch((e) => (error = e instanceof ApiError ? e.message : 'Chargement impossible.'));
  });

  const storageShare = $derived(data ? Math.round(Math.min(1, data.storage.ratio) * 100) : 0);
</script>

<PageHead eyebrow={current.session?.organization?.name ?? 'Organisation'} title="Tableau de bord" />

{#if error}<p class="form-error" role="alert">{error}</p>{/if}

<div class="tiles">
  <a class="tile" href="/admin/clients" onclick={followLink}>
    <span class="tile__label">Clients</span><span class="tile__value">{data?.clients ?? '–'}</span>
  </a>
  <a class="tile" href="/admin/projects" onclick={followLink}>
    <span class="tile__label">Projets</span><span class="tile__value">{data?.projects ?? '–'}</span>
  </a>
  <a class="tile" href="/admin/videos" onclick={followLink}>
    <span class="tile__label">Vidéos</span><span class="tile__value">{data?.videos ?? '–'}</span>
  </a>
  <a class="tile" href="/admin/revisions" onclick={followLink}>
    <span class="tile__label">Révisions à faire</span><span class="tile__value">{data?.todo ?? '–'}</span>
    {#if data && data.unread > 0}<span class="tile__sub tile__sub--accent">{data.unread} non lue{data.unread > 1 ? 's' : ''}</span>{/if}
  </a>
  <a class="tile" href="/admin/settings" onclick={followLink}>
    <span class="tile__label">Stockage</span>
    <span class="tile__value">{data ? (storageShare === 0 && data.storage.used + data.storage.reserved > 0 ? '< 1 %' : `${storageShare} %`) : '–'}</span>
    {#if data}<span class="tile__sub">{formatBytes(data.storage.used + data.storage.reserved)}{data.storage.quota === null ? " · sans limite" : ` sur ${data.storage.quotaLabel}`}</span>{/if}
  </a>
</div>

<h2 class="section-title">Dernière activité</h2>
{#if data && data.recent.videos.length === 0}
  <div class="empty">
    <p class="empty__title">Rien de neuf pour l’instant</p>
    <p class="empty__text">Les nouvelles versions, révisions et validations apparaîtront ici.</p>
  </div>
{:else}
  <div class="recent">
    <section class="panel recent__videos" aria-labelledby="recent-videos">
      <h3 class="panel__title" id="recent-videos">Vidéos</h3>
      <ul class="feed">
        {#each data?.recent.videos ?? [] as video (video.id)}
          <li>
            <a class="feed__item" href="/admin/videos/{video.id}{video.versionId ? `?version=${video.versionId}` : ''}" onclick={followLink}>
              <span class="feed__head">
                <span class="mono feed__num">{video.number}</span>
                <span class="feed__name">{video.title}</span>
                <span class="feed__when">{formatRelative(video.at)}</span>
              </span>
              <span class="feed__context">{video.context}</span>
              <span class="feed__summary">{video.summary}</span>
            </a>
          </li>
        {/each}
      </ul>
    </section>
    {#each [['Projets', 'projects', data?.recent.projects ?? []], ['Clients', 'clients', data?.recent.clients ?? []]] as const as [title, path, list] (path)}
      <section class="panel" aria-labelledby="recent-{path}">
        <h3 class="panel__title" id="recent-{path}">{title}</h3>
        <ul class="feed">
          {#each list as item (item.id)}
            <li>
              <a class="feed__item" href="/admin/{path}/{item.id}" onclick={followLink}>
                <span class="feed__head">
                  <span class="feed__name">{item.name}</span>
                  <span class="feed__when">{formatRelative(item.at)}</span>
                </span>
                {#if item.context}<span class="feed__context">{item.context}</span>{/if}
                <span class="feed__summary">{item.summary}</span>
              </a>
            </li>
          {/each}
        </ul>
      </section>
    {/each}
  </div>
{/if}

<style>
  .tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 12px;
  }

  .tile {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 18px 20px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface-1);
    color: inherit;
    text-decoration: none;
    transition: border-color 150ms ease;
  }

  .tile:hover {
    border-color: var(--border-strong);
  }

  .tile__label {
    font-size: 12.5px;
    color: var(--text-muted);
  }

  .tile__value {
    font-family: var(--font-display);
    font-size: 40px;
    line-height: 1;
  }

  .tile__sub {
    font-size: 12px;
    color: var(--text-muted);
  }

  .tile__sub--accent {
    color: var(--accent-text);
  }

  .section-title {
    margin: 32px 0 14px;
    font-family: var(--font-display);
    font-size: 26px;
    font-weight: 400;
  }

  .recent {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1fr);
    gap: 16px;
    align-items: start;
  }

  @media (max-width: 1100px) {
    .recent {
      grid-template-columns: minmax(0, 1fr);
    }
  }

  .feed {
    display: flex;
    flex-direction: column;
    margin: 8px -10px 0;
    padding: 0;
    list-style: none;
  }

  .feed__item {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding: 10px;
    border-radius: var(--radius-sm);
    color: inherit;
    text-decoration: none;
  }

  .feed__item:hover {
    background: var(--surface-3);
  }

  .feed__head {
    display: flex;
    align-items: baseline;
    gap: 8px;
  }

  .feed__num {
    color: var(--text-muted);
    font-size: 12px;
  }

  .feed__name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .feed__when {
    flex: none;
    color: var(--text-muted);
    font-size: 12px;
  }

  .feed__context {
    color: var(--text-muted);
    font-size: 12px;
  }

  .feed__summary {
    font-size: 13px;
  }
</style>
