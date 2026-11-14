<script lang="ts">
  import { formatDate, normalize } from '../lib/format';
  import { VERSION_STATUS } from '../lib/status';
  import type { ClientBrand, LibraryVideo, SpaceProject, SpaceVideo } from './types';

  let { client, projects, library = [] }: { client: ClientBrand; projects: SpaceProject[]; library?: LibraryVideo[] } = $props();

  let query = $state('');
  const DEFAULT_WELCOME = 'Regardez les dernières versions, laissez vos retours directement sur l’image et validez quand tout est parfait.';
  const paragraphs = $derived((client.welcomeText?.trim() || DEFAULT_WELCOME).split(/\n+/).filter((p) => p.trim() !== ''));

  // Search in titles, numbers and project names (§5.3).
  const shown = $derived.by(() => {
    const needle = normalize(query.trim());
    if (!needle) return projects;
    return projects
      .map((p) => ({ ...p, videos: p.videos.filter((v) => normalize(`${v.title} ${v.number} ${p.name}`).includes(needle)) }))
      .filter((p) => p.videos.length > 0);
  });
  const shownLibrary = $derived.by(() => {
    const needle = normalize(query.trim());
    return needle ? library.filter((v) => normalize(`${v.title} ${v.number} ${v.projectName}`).includes(needle)) : library;
  });
  const total = $derived(shown.reduce((n, p) => n + p.videos.length, 0) + shownLibrary.length);
</script>

{#snippet vcard(video: SpaceVideo & { projectName?: string }, eager: boolean, first: boolean, archivedAt: string | null)}
  <li>
    <a class="vcard" href={video.href}>
      <span class="vcard__thumb">
        {#if video.posterUrl}
          <img
            src={video.posterUrl}
            alt=""
            width="640"
            height="360"
            decoding="async"
            loading={eager ? 'eager' : 'lazy'}
            fetchpriority={first ? 'high' : 'auto'}
          />
        {/if}
        <span class="vcard__badge vcard__badge--left">V{video.version}</span>
        <span class="vcard__badge vcard__badge--right">{video.duration}</span>
      </span>
      <span class="vcard__line">
        <span class="vcard__title">{video.title}</span>
        <span class="vcard__number">N° {video.number}</span>
      </span>
      <span class="vcard__line vcard__line--meta">
        {#if archivedAt}
          <span class="archived">{video.projectName}</span>
          <span>archivée le {formatDate(archivedAt)}</span>
        {:else}
          <span class="status status--{video.status}">{VERSION_STATUS[video.status]}</span>
          {#if video.publishedAt}<span>{formatDate(video.publishedAt)}</span>{/if}
        {/if}
      </span>
    </a>
  </li>
{/snippet}

<div class="space">
  <div class="search">
    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"></circle><path d="M16 16l4 4"></path></svg>
    <label class="visually-hidden" for="space-search">Rechercher une vidéo</label>
    <input id="space-search" type="search" placeholder="Rechercher une vidéo" autocomplete="off" bind:value={query} />
  </div>

  <section class="hero" aria-labelledby="space-title">
    <p class="client-eyebrow">// Espace {client.name}</p>
    <h1 class="client-title hero__title" id="space-title">Vos films, <em>en avant-première.</em></h1>
    {#each paragraphs as paragraph, i (i)}
      <p class="hero__text">{paragraph}</p>
    {/each}
  </section>

  <p class="visually-hidden" role="status">{query ? `${total} vidéo${total > 1 ? 's' : ''} trouvée${total > 1 ? 's' : ''}` : ''}</p>

  {#if projects.length === 0 && library.length === 0}
    <div class="client-placeholder">Vos vidéos apparaîtront ici dès qu’une première version sera prête.</div>
  {:else if shown.length === 0 && shownLibrary.length === 0 && query}
    <div class="client-placeholder">Aucune vidéo ne correspond à « {query} ».</div>
  {/if}

  {#each shown as project, projectIndex (project.name)}
    <section class="project" aria-label={project.name}>
      <div class="project__head">
        <h2 class="project__name">{project.name}</h2>
        <span class="project__meta">
          {project.videos.length} vidéo{project.videos.length > 1 ? 's' : ''}{project.updatedAt ? ` · mis à jour le ${formatDate(project.updatedAt)}` : ''}
        </span>
      </div>
      <ul class="cards">
        {#each project.videos as video, index (video.number)}
          {@render vcard(video, projectIndex === 0 && index < 3, projectIndex === 0 && index === 0, null)}
        {/each}
      </ul>
    </section>
  {/each}

  {#if shownLibrary.length > 0}
    <section class="project library" aria-labelledby="library-title">
      <div class="project__head">
        <h2 class="project__name" id="library-title">Bibliothèque</h2>
        <span class="project__meta">Vos vidéos terminées, à revoir et à télécharger</span>
      </div>
      <ul class="cards">
        {#each shownLibrary as video (video.number)}
          {@render vcard(video, false, false, video.archivedAt)}
        {/each}
      </ul>
    </section>
  {/if}
</div>

<style>
  .library {
    padding-top: 28px;
    border-top: 1px solid var(--line);
  }

  .archived {
    font-weight: 500;
  }

  .space {
    display: flex;
    flex-direction: column;
    gap: 40px;
  }

  .search {
    display: flex;
    align-items: center;
    gap: 10px;
    align-self: flex-end;
    width: min(100%, 320px);
    height: var(--hit);
    margin-top: -8px;
    padding: 0 16px;
    border: 1px solid var(--line);
    border-radius: var(--radius-pill);
    background: color-mix(in srgb, var(--client-text) 5%, var(--client-bg));
    color: var(--muted);
  }

  .search:focus-within {
    border-color: var(--client-accent);
  }

  .search input {
    flex: 1;
    min-width: 0;
    border: none;
    background: transparent;
    color: var(--client-text);
    font-size: 13.5px;
    outline: none;
  }

  .search input:focus-visible {
    box-shadow: none;
  }

  .hero {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .hero__title {
    font-size: clamp(40px, 7vw, 64px) !important;
    line-height: 1 !important;
  }

  .hero__title em {
    color: var(--client-accent-text);
  }

  .hero__text {
    max-width: 560px;
    color: var(--muted);
    font-size: 15px;
  }

  .project {
    display: flex;
    flex-direction: column;
    gap: 18px;
  }

  .project__head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--line);
  }

  .project__name {
    font-family: var(--font-display);
    font-weight: 400;
    font-size: 30px;
    line-height: 1.1;
  }

  .project__meta {
    font-family: var(--font-mono);
    font-size: 12px;
    color: var(--muted);
  }

  .cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr));
    gap: 24px;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .vcard {
    display: flex;
    flex-direction: column;
    gap: 12px;
    color: inherit;
    text-decoration: none;
    border-radius: var(--radius-md);
  }

  .vcard__thumb {
    position: relative;
    display: block;
    overflow: hidden;
    aspect-ratio: 16 / 9;
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    background: #000;
  }

  .vcard__thumb img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    transition: transform 300ms ease;
  }

  .vcard:hover .vcard__thumb img {
    transform: scale(1.03);
  }

  .vcard__badge {
    position: absolute;
    bottom: 10px;
    padding: 3px 7px;
    border-radius: 5px;
    background: rgb(10 10 11 / 0.8);
    color: #fafafa;
    font-family: var(--font-mono);
    font-size: 11px;
  }

  .vcard__badge--left {
    left: 10px;
  }

  .vcard__badge--right {
    right: 10px;
  }

  .vcard__line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
  }

  .vcard__line--meta {
    justify-content: flex-start;
    font-size: 12.5px;
    color: var(--muted);
  }

  .vcard__title {
    font-weight: 600;
    font-size: 15px;
  }

  .vcard:hover .vcard__title {
    text-decoration: underline;
    text-underline-offset: 3px;
  }

  .vcard__number {
    flex: none;
    font-family: var(--font-mono);
    font-size: 11.5px;
    color: var(--muted);
  }
</style>
