<script lang="ts">
  import { onMount } from 'svelte';
  import { formatBytes, formatLongDate } from '../lib/format';
  import { followLink, location } from '../lib/router.svelte';
  import { unread } from './badge.svelte';
  import UploadCard from './components/UploadCard.svelte';
  import ClientPage from './pages/ClientPage.svelte';
  import SettingsPage from './pages/SettingsPage.svelte';
  import TeamPage from './pages/TeamPage.svelte';
  import ClientsList from './pages/ClientsList.svelte';
  import CollectionPage from './pages/CollectionPage.svelte';
  import CollectionsList from './pages/CollectionsList.svelte';
  import DashboardPage from './pages/DashboardPage.svelte';
  import RevisionsPage from './pages/RevisionsPage.svelte';
  import Placeholder from './pages/Placeholder.svelte';
  import ProjectPage from './pages/ProjectPage.svelte';
  import ProjectsList from './pages/ProjectsList.svelte';
  import VideoPage from './pages/VideoPage.svelte';
  import VideosList from './pages/VideosList.svelte';
  import { ADMIN_BASE, SECTIONS, parseAdminRoute, type AdminRoute } from './sections';
  import { untilReset } from './demo';
  import { can, logout, ROLE_LABELS, type AdminSession } from './session';

  let {
    session,
    onLogout,
    onSession,
  }: { session: AdminSession; onLogout: (session: AdminSession) => void; onSession: (session: AdminSession) => void } = $props();

  const menu = $derived(SECTIONS.filter((item) => !item.adminOnly || can(session, 'team')));

  const route = $derived<AdminRoute>(parseAdminRoute(location.path) ?? { section: SECTIONS[0]!, view: 'index' });
  const section = $derived(route.section);
  const routeKey = $derived(location.path + location.search);
  const pageId = $derived(route.view === 'detail' ? route.id : null);
  let busy = $state(false);

  // Public demo: the time left before the reset (§5.16).
  let now = $state(Date.now());
  onMount(() => {
    if (!session.demo) return;
    const timer = setInterval(() => (now = Date.now()), 30_000);
    return () => clearInterval(timer);
  });

  // Badge: refreshed every minute and on each page change (a review marks comments as read).
  onMount(() => {
    const timer = setInterval(() => void unread.refresh(), 60_000);
    return () => clearInterval(timer);
  });
  $effect(() => {
    void location.path;
    const timer = setTimeout(() => void unread.refresh(), 800);
    return () => clearTimeout(timer);
  });

  async function signOut(): Promise<void> {
    busy = true;
    try {
      onLogout(await logout());
    } catch {
      busy = false;
    }
  }
</script>

<div class="admin" data-role={session.user?.role}>
  <nav class="nav" aria-label="Administration">
    {#if session.organization?.logoUrl}
      <a class="nav__brand" href={ADMIN_BASE} onclick={followLink}>
        <img src={session.organization.logoUrl} alt="{session.organization.name} — tableau de bord" />
      </a>
    {:else}
      <a class="wordmark nav__logo" href={ADMIN_BASE} onclick={followLink}>
        belprise<span class="wordmark__dot">.</span>
      </a>
    {/if}
    {#if !session.organization?.logoUrl}
      <!-- With a logo, the logo alone (its alt text names the organization). -->
      <p class="nav__org">{session.organization?.name}</p>
    {/if}
    <ul class="nav__menu">
      {#each menu as item (item.id)}
        <li>
          <a
            class="nav__link"
            href={item.path}
            aria-current={item.id === section.id ? (route.view === 'index' ? 'page' : 'true') : undefined}
            onclick={followLink}
          >
            {item.label}
            {#if item.id === 'revisions' && unread.count > 0}
              <span class="nav__badge" aria-label="{unread.count} non lu{unread.count > 1 ? 's' : ''}">{unread.count}</span>
            {/if}
          </a>
        </li>
      {/each}
    </ul>
    <div class="nav__bottom">
      {#if !session.demo}<UploadCard />{/if}
      <div class="nav__account">
        <p class="eyebrow">// Connecté</p>
        <p class="nav__user">{session.user?.name}</p>
        {#if session.user}<p class="nav__role">{ROLE_LABELS[session.user.role]}</p>{/if}
        <button class="btn nav__logout" type="button" onclick={signOut} disabled={busy}>Se déconnecter</button>
      </div>
      <p class="nav__signature">
        <span class="wordmark">belprise<span class="wordmark__dot">.</span></span> · propulsé par
        <a href="https://belprod.fr" target="_blank" rel="noopener noreferrer">belprod</a>
      </p>
    </div>
  </nav>

  <main class="main">
    <div class="main__inner">
    {#if session.demo}
      <p class="demo-banner" role="status">
        <strong>Démo — remise à zéro dans {untilReset(session.demo.resetAt, now)}.</strong>
        Essayez tout : révisions, statuts, liens, collections. Seul l’envoi de fichiers est désactivé.
        <span class="demo-banner__credit">{session.demo.credit}</span>
      </p>
    {/if}
    {#if session.lifecycle?.deletionAt}
      <p class="quota-banner quota-banner--full" role="status">
        <strong>Suppression de l’organisation programmée le {formatLongDate(session.lifecycle.deletionAt)}.</strong>
        Toutes les données seront effacées ce jour-là. D’ici là, récupérez vos masters (« Télécharger le master » sur chaque version) ;
        les liens partagés restent actifs, les nouveaux envois sont bloqués.
        {session.lifecycle.deletionBy === 'operator' ? 'Seul l’exploitant de belprise peut annuler cette demande.' : 'Un administrateur peut l’annuler dans la page Équipe.'}
      </p>
    {:else if session.lifecycle?.purgeAt && session.storage}
      <p class="quota-banner quota-banner--full" role="status">
        <strong>Quota abaissé{session.lifecycle.downgradedFrom ? ` (${session.lifecycle.downgradedFrom} → ${session.storage.quotaLabel})` : ''} :
          libérez {formatBytes(Math.max(0, session.storage.used + session.storage.reserved - (session.storage.quota ?? 0)))} avant le {formatLongDate(session.lifecycle.purgeAt)}.</strong>
        Les nouveaux envois sont bloqués. À cette date, si le stockage dépasse encore {session.storage.quotaLabel}, des vidéos entières seront supprimées :
        d’abord les archivées, puis les autres, de la dernière activité la plus ancienne à la plus récente. Téléchargez les masters à garder.
      </p>
    {:else if session.storage && session.storage.ratio >= 0.8}
      <p class="quota-banner" class:quota-banner--full={session.storage.ratio >= 1} role="status">
        {#if session.storage.ratio >= 1}
          Stockage plein (quota de {session.storage.quotaLabel}) : les nouveaux envois sont bloqués.
          Archivez ou supprimez des vidéos, ou demandez un quota plus grand à l’exploitant.
        {:else}
          Stockage utilisé à {Math.round(session.storage.ratio * 100)} % de votre quota ({session.storage.quotaLabel}).
        {/if}
      </p>
    {/if}
    {#key routeKey}
      {#if section.id === 'clients'}
        {#if route.view === 'index'}<ClientsList />{:else}<ClientPage id={pageId} />{/if}
      {:else if section.id === 'projects'}
        {#if route.view === 'index'}<ProjectsList />{:else}<ProjectPage id={pageId} />{/if}
      {:else if section.id === 'videos'}
        {#if route.view === 'index'}<VideosList />{:else}<VideoPage id={pageId} />{/if}
      {:else if section.id === 'collections'}
        {#if route.view === 'index'}<CollectionsList />{:else}<CollectionPage id={pageId} />{/if}
      {:else if section.id === 'dashboard'}
        <DashboardPage />
      {:else if section.id === 'revisions'}
        <RevisionsPage />
      {:else if section.id === 'settings'}
        <SettingsPage {session} {onSession} />
      {:else if section.id === 'team' && can(session, 'team')}
        <TeamPage />
      {:else}
        <Placeholder {section} />
      {/if}
    {/key}
    </div>
  </main>
</div>

<style>
  .admin {
    display: flex;
    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
  }

  /* Fixed-width menu that stays in view; the page scrolls beside it. */
  .nav {
    flex: 0 0 248px;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 28px;
    padding: 24px 16px;
    border-right: 1px solid var(--border);
    background: var(--surface-1);
  }

  .nav__logo {
    align-self: flex-start;
    padding: 0 10px;
    border-radius: var(--radius-sm);
    font-size: 24px;
    text-decoration: none;
  }

  .nav__brand {
    display: flex;
    align-items: center;
    align-self: stretch;
    height: 48px;
    padding: 0 10px;
    border-radius: var(--radius-sm);
  }

  .nav__brand img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    object-position: left center;
  }

  .nav__menu {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .nav__link {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: var(--hit);
    padding: 0 12px;
    border-radius: 8px;
    color: var(--text-muted);
    text-decoration: none;
    transition: background-color 120ms ease, color 120ms ease;
  }

  .nav__badge {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 22px;
    margin-left: auto;
    padding: 0 6px;
    border-radius: var(--radius-pill);
    background: var(--accent);
    color: var(--on-accent);
    font-size: 11.5px;
    font-weight: 600;
  }

  .nav__link:hover {
    color: var(--text);
  }

  .nav__link[aria-current] {
    background: var(--surface-3);
    color: var(--text);
    font-weight: 500;
  }

  .nav__bottom {
    margin-top: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .nav__account {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface-2);
  }

  .nav__user {
    font-weight: 500;
    overflow-wrap: anywhere;
  }

  .nav__role {
    color: var(--text-muted);
    font-size: 12px;
  }

  .nav__org {
    margin-top: -18px;
    color: var(--text-muted);
    font-size: 13px;
    overflow-wrap: anywhere;
  }

  .nav__signature {
    color: var(--text-muted);
    font-size: 11.5px;
  }

  .nav__signature .wordmark {
    font-size: 12px;
  }

  .nav__signature a {
    color: var(--text-soft);
  }

  .nav__logout {
    margin-top: 6px;
    font-size: 13px;
  }

  .quota-banner {
    margin-bottom: 20px;
    padding: 12px 16px;
    border: 1px solid color-mix(in srgb, var(--status-changes) 45%, transparent);
    border-radius: var(--radius-md);
    background: color-mix(in srgb, var(--status-changes) 10%, transparent);
    font-size: 13.5px;
  }

  .demo-banner {
    margin-bottom: 20px;
    padding: 12px 16px;
    border: 1px solid color-mix(in srgb, var(--accent) 45%, transparent);
    border-radius: var(--radius-md);
    background: color-mix(in srgb, var(--accent) 10%, transparent);
    font-size: 13.5px;
    line-height: 1.5;
  }

  .demo-banner__credit {
    display: block;
    margin-top: 4px;
    color: var(--text-muted);
    font-size: 12px;
  }

  .quota-banner--full {
    border-color: color-mix(in srgb, var(--status-refused) 55%, transparent);
    background: color-mix(in srgb, var(--status-refused) 12%, transparent);
  }

  .main {
    flex: 1 1 auto;
    min-width: 0;
    padding: 32px clamp(20px, 3vw, 48px) 48px;
  }

  /* Readable line length on very wide screens (4K): content centred. */
  .main__inner {
    display: flex;
    flex-direction: column;
    gap: 28px;
    max-width: 1440px;
    margin: 0 auto;
  }

  /* Tablet: the menu becomes a top bar (logo and account, then the links). */
  @media (max-width: 900px) {
    .admin {
      flex-direction: column;
      flex-wrap: nowrap;
    }

    .nav {
      flex: none;
      position: static;
      height: auto;
      overflow: visible;
      flex-direction: row;
      flex-wrap: wrap;
      align-items: center;
      gap: 12px 16px;
      padding: 16px;
      border-right: none;
      border-bottom: 1px solid var(--border);
    }

    .nav__menu {
      order: 3;
      flex-basis: 100%;
      flex-direction: row;
      flex-wrap: wrap;
    }

    .nav__bottom {
      order: 4;
      flex-basis: 100%;
      margin: 0;
    }

    .nav__account {
      margin: 0 0 0 auto;
      flex-direction: row;
      align-items: center;
      gap: 12px;
      padding: 0;
      border: none;
      background: none;
    }

    .main {
      flex: 1;
    }

    .nav__account .eyebrow,
    .nav__role,
    .nav__signature {
      display: none;
    }

    .nav__logout {
      margin-top: 0;
    }

    .main {
      padding: 24px 20px 40px;
    }
  }
</style>
