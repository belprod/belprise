<script lang="ts">
  import { api, ApiError } from '../../lib/api';
  import { formatDate, normalize, plural } from '../../lib/format';
  import { followLink } from '../../lib/router.svelte';
  import PageHead from '../components/PageHead.svelte';
  import type { CollectionSummary } from '../types';

  let collections = $state<CollectionSummary[] | null>(null);
  let error = $state('');
  let query = $state('');

  const visible = $derived(
    (collections ?? []).filter((c) => normalize(c.title).includes(normalize(query.trim()))),
  );

  $effect(() => {
    api<CollectionSummary[]>('GET', '/api/admin/collections')
      .then((list) => (collections = list))
      .catch((e) => (error = e instanceof ApiError ? e.message : 'Chargement impossible.'));
  });

  const LINK_STATUS = { active: 'Actif', disabled: 'Désactivé', expired: 'Expiré' } as const;
</script>

<PageHead eyebrow="Prospects" title="Collections">
  {#snippet actions()}
    <a class="btn btn--primary btn--icon-plus can-edit" href="/admin/collections/new" onclick={followLink}>Nouvelle collection</a>
  {/snippet}
</PageHead>

{#if error}
  <p class="form-error" role="alert">{error}</p>
{:else if collections === null}
  <p class="loading">Chargement…</p>
{:else if collections.length === 0}
  <div class="empty">
    <p class="empty__title">Aucune collection</p>
    <p class="empty__text">Rassemblez une sélection de vidéos à montrer à un prospect, derrière un lien unique en lecture seule.</p>
  </div>
{:else}
  <div class="toolbar">
    <div class="search">
      <label class="visually-hidden" for="collections-search">Rechercher une collection</label>
      <input id="collections-search" type="search" placeholder="Rechercher une collection…" bind:value={query} />
    </div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>Collection</th><th>Vidéos</th><th>Lien</th><th>Modifiée le</th></tr>
      </thead>
      <tbody>
        {#each visible as collection (collection.id)}
          <tr>
            <td><a class="table__main" href="/admin/collections/{collection.id}" onclick={followLink}>{collection.title}</a></td>
            <td class="table__num">{collection.videoCount}</td>
            <td>
              {#if collection.link}
                {LINK_STATUS[collection.link.status]}{collection.link.hasPassword ? ' · protégé' : ''}
              {/if}
            </td>
            <td class="muted">{formatDate(collection.updatedAt)}</td>
          </tr>
        {:else}
          <tr><td colspan="4" class="muted">Aucune collection ne correspond à « {query} ».</td></tr>
        {/each}
      </tbody>
    </table>
  </div>
  <p class="muted count">{plural(collections.length, 'collection')}</p>
{/if}

<style>
  .count {
    margin-top: 12px;
    font-size: 13px;
  }
</style>
