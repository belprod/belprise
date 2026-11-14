<script lang="ts">
  import { api, ApiError } from '../../lib/api';
  import { formatDate, normalize } from '../../lib/format';
  import { followLink } from '../../lib/router.svelte';
  import PageHead from '../components/PageHead.svelte';
  import type { Client } from '../types';

  let clients = $state<Client[] | null>(null);
  let error = $state('');
  let query = $state('');
  let showArchived = $state(false);

  const visible = $derived(
    (clients ?? []).filter((client) => normalize(client.name).includes(normalize(query.trim()))),
  );

  $effect(() => {
    const archived = showArchived;
    api<Client[]>('GET', `/api/admin/clients${archived ? '?archived=1' : ''}`)
      .then((list) => (clients = list))
      .catch((e) => (error = e instanceof ApiError ? e.message : 'Chargement impossible.'));
  });

  const LINK_STATUS = { active: 'Actif', disabled: 'Désactivé', expired: 'Expiré' } as const;
</script>

<PageHead eyebrow="Clients" title="Clients">
  {#snippet actions()}
    <a class="btn btn--primary btn--icon-plus can-edit" href="/admin/clients/new" onclick={followLink}>Nouveau client</a>
  {/snippet}
</PageHead>

<div class="toolbar">
  <div class="search">
    <label class="visually-hidden" for="clients-search">Rechercher un client</label>
    <input id="clients-search" type="search" placeholder="Rechercher un client…" bind:value={query} />
  </div>
  <label class="check"><input type="checkbox" bind:checked={showArchived} /> Afficher les archivés</label>
</div>

{#if error}
  <p class="form-error" role="alert">{error}</p>
{:else if clients === null}
  <p class="loading">Chargement…</p>
{:else if clients.length === 0}
  <div class="empty">
    <p class="empty__title">Aucun client</p>
    <p class="empty__text">Créez votre premier client pour lui envoyer des vidéos.</p>
  </div>
{:else}
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>Client</th><th>Projets</th><th>Vidéos</th><th>Espace client</th><th>Créé le</th></tr>
      </thead>
      <tbody>
        {#each visible as client (client.id)}
          <tr>
            <td>
              <span class="client-cell">
                {#if client.logoUrl}
                  <img class="client-cell__logo" src={client.logoUrl} alt="" />
                {:else}
                  <span class="client-cell__logo client-cell__initial" aria-hidden="true">{client.name.charAt(0)}</span>
                {/if}
                <a class="table__main" href="/admin/clients/{client.id}" onclick={followLink}>{client.name}</a>
                {#if client.archived}<span class="pill pill--off">Archivé</span>{/if}
              </span>
            </td>
            <td class="table__num">{client.projectCount}</td>
            <td class="table__num">{client.videoCount}</td>
            <td>
              {#if client.link}
                {LINK_STATUS[client.link.status]}{client.link.hasPassword ? ' · protégé' : ''}
              {/if}
            </td>
            <td class="muted">{formatDate(client.createdAt)}</td>
          </tr>
        {:else}
          <tr><td colspan="5" class="muted">Aucun client ne correspond à « {query} ».</td></tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}

<style>
  .client-cell {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .client-cell__logo {
    flex: none;
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    background: var(--surface-3);
    object-fit: contain;
  }

  .client-cell__initial {
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    color: var(--text-muted);
  }
</style>
