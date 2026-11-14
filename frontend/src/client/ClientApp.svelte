<script lang="ts">
  import { readBoot } from '../lib/boot';
  import CollectionPage from './CollectionPage.svelte';
  import LibraryPage from './LibraryPage.svelte';
  import ReviewPage from './ReviewPage.svelte';
  import SpacePage from './SpacePage.svelte';
  import type { ClientBoot } from './types';
  import ViewPage from './ViewPage.svelte';

  const boot = readBoot<ClientBoot>();
  const client = boot.client;
</script>

<div class="client-page">
  <header class="client-header">
    {#if client.logoUrl}
      <img class="client-header__logo" src={client.logoUrl} alt={client.name} width="180" height="40" />
    {:else}
      <span class="client-header__name">{client.name}</span>
    {/if}
  </header>

  <main class="client-main" class:client-main--wide={boot.page !== 'space'} id="contenu">
    {#if boot.page === 'space'}
      <SpacePage {client} projects={boot.projects} library={boot.library} />
    {:else if boot.page === 'review'}
      <ReviewPage {client} project={boot.project} video={boot.video} versions={boot.versions} csrf={boot.csrf} />
    {:else if boot.page === 'library'}
      <LibraryPage project={boot.project} video={boot.video} versions={boot.versions} archivedAt={boot.archivedAt} />
    {:else if boot.page === 'collection'}
      <CollectionPage collection={boot.collection} videos={boot.videos} />
    {:else}
      <ViewPage video={boot.video} versions={boot.versions} />
    {/if}
  </main>

  <footer class="client-footer signature">
    <span class="wordmark">belprise<span class="wordmark__dot">.</span></span> · propulsé par <a href="https://belprod.fr" target="_blank" rel="noopener noreferrer">belprod</a>
  </footer>
</div>

<style>
  .client-page {
    --muted: color-mix(in srgb, var(--client-text) 74%, var(--client-bg));
    --line: color-mix(in srgb, var(--client-text) 16%, var(--client-bg));
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    background: var(--client-bg);
    color: var(--client-text);
  }

  .client-header {
    display: flex;
    align-items: center;
    min-height: 72px;
    padding: 16px clamp(16px, 4vw, 40px);
    border-bottom: 1px solid var(--line);
  }

  .client-header__logo {
    max-width: 180px;
    max-height: 40px;
    object-fit: contain;
  }

  .client-header__name {
    font-weight: 600;
    font-size: 16px;
  }

  .client-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    padding: clamp(28px, 6vw, 56px) clamp(16px, 4vw, 40px);
  }

  .client-main--wide {
    max-width: 1440px;
    gap: 20px;
    padding-top: clamp(20px, 3vw, 32px);
  }

  .client-main :global(.client-eyebrow) {
    font-family: var(--font-mono);
    font-size: 12px;
    letter-spacing: 0.04em;
    color: var(--muted);
  }

  .client-main :global(.client-title) {
    font-family: var(--font-display);
    font-weight: 400;
    font-size: clamp(36px, 6vw, 56px);
    line-height: 1.05;
    overflow-wrap: anywhere;
  }

  .client-main :global(.client-placeholder) {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 240px;
    margin-top: 16px;
    padding: 24px;
    border: 1px dashed var(--line);
    border-radius: var(--radius-lg);
    color: var(--muted);
    text-align: center;
  }

  .client-footer {
    padding: 24px 16px;
    text-align: center;
    color: var(--muted);
  }

  .client-footer :global(.wordmark) {
    color: var(--client-text);
  }
</style>
