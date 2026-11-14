<script lang="ts">
  import { api, ApiError } from '../../lib/api';
  import { formatDay } from '../../lib/format';
  import type { LinkInfo } from '../types';
  import ConfirmDialog from './ConfirmDialog.svelte';

  let {
    link = $bindable(),
    title,
    description,
  }: { link: LinkInfo; title: string; description: string } = $props();

  const uid = $props.id();
  const STATUS = {
    active: { label: 'Actif', className: 'pill--ok' },
    disabled: { label: 'Désactivé', className: 'pill--off' },
    expired: { label: 'Expiré', className: 'pill--warn' },
  } as const;

  const url = $derived(window.location.origin + link.path);
  let busy = $state(false);
  let error = $state('');
  let copied = $state(false);
  let editingPassword = $state(false);
  let password = $state('');
  let confirmRegenerate = $state(false);
  let urlInput: HTMLInputElement;
  let copiedTimer: ReturnType<typeof setTimeout> | undefined;

  async function patch(changes: Record<string, unknown>): Promise<boolean> {
    busy = true;
    error = '';
    try {
      link = await api<LinkInfo>('PATCH', `/api/admin/links/${link.id}`, changes);
      return true;
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
      return false;
    } finally {
      busy = false;
    }
  }

  async function copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(url);
      copied = true;
      clearTimeout(copiedTimer);
      copiedTimer = setTimeout(() => (copied = false), 2000);
    } catch {
      // Clipboard unavailable (e.g. plain HTTP on another host): select the text instead.
      urlInput.select();
    }
  }

  async function savePassword(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (await patch({ password })) {
      editingPassword = false;
      password = '';
    }
  }

  async function regenerate(): Promise<void> {
    link = await api<LinkInfo>('POST', `/api/admin/links/${link.id}/regenerate`);
  }
</script>

<section class="panel" aria-labelledby="{uid}-title">
  <div class="panel__head">
    <h2 class="panel__title" id="{uid}-title">{title}</h2>
    <span class="pill {STATUS[link.status].className}">{STATUS[link.status].label}</span>
  </div>
  <p class="panel__text">{description}</p>

  <div class="link-url">
    <label class="visually-hidden" for="{uid}-url">Adresse du lien</label>
    <input
      class="input input--mono link-url__input"
      id="{uid}-url"
      readonly
      value={url}
      bind:this={urlInput}
      onfocus={(e) => e.currentTarget.select()}
    />
    <button class="btn btn--primary" type="button" onclick={copy}>{copied ? 'Copié' : 'Copier'}</button>
    <a class="btn" href={url} target="_blank" rel="noopener noreferrer">Ouvrir</a>
  </div>
  <p class="visually-hidden" role="status">{copied ? 'Lien copié dans le presse-papiers.' : ''}</p>

  <div class="link-options can-edit">
    <button
      class="switch"
      type="button"
      role="switch"
      aria-checked={link.isActive}
      disabled={busy}
      onclick={() => patch({ isActive: !link.isActive })}
    >
      <span class="switch__track" aria-hidden="true"></span>
      Lien actif
    </button>

    <div class="link-option">
      <span class="link-option__label">Mot de passe</span>
      {#if editingPassword}
        <form class="link-option__form" onsubmit={savePassword}>
          <label class="visually-hidden" for="{uid}-password">Nouveau mot de passe</label>
          <input
            class="input"
            id="{uid}-password"
            type="text"
            autocomplete="off"
            spellcheck="false"
            placeholder="Au moins 4 caractères"
            bind:value={password}
          />
          <button class="btn btn--small btn--primary" type="submit" disabled={busy}>Enregistrer</button>
          <button class="btn btn--small" type="button" onclick={() => (editingPassword = false)}>Annuler</button>
        </form>
      {:else}
        <span class="link-option__value">{link.hasPassword ? 'Protégé' : 'Aucun'}</span>
        <button class="btn btn--small" type="button" disabled={busy} onclick={() => (editingPassword = true)}>
          {link.hasPassword ? 'Changer' : 'Définir'}
        </button>
        {#if link.hasPassword}
          <button class="btn btn--small" type="button" disabled={busy} onclick={() => patch({ password: null })}>Retirer</button>
        {/if}
      {/if}
    </div>

    <div class="link-option">
      <label class="link-option__label" for="{uid}-expiry">Expiration</label>
      <input
        class="input link-option__date"
        id="{uid}-expiry"
        type="date"
        value={link.expiresOn ?? ''}
        disabled={busy}
        onchange={(e) => patch({ expiresOn: e.currentTarget.value || null })}
      />
      {#if link.expiresOn}
        <button class="btn btn--small" type="button" disabled={busy} onclick={() => patch({ expiresOn: null })}>Sans limite</button>
      {/if}
    </div>
    {#if link.expiresOn}
      <p class="panel__text">Le lien fonctionne jusqu’au {formatDay(link.expiresOn)} inclus.</p>
    {/if}
  </div>

  {#if error}
    <p class="form-error" role="alert">{error}</p>
  {/if}

  <div class="can-edit">
    <button class="btn btn--small" type="button" onclick={() => (confirmRegenerate = true)}>Régénérer le lien</button>
  </div>
</section>

<ConfirmDialog bind:open={confirmRegenerate} title="Régénérer le lien ?" confirmLabel="Régénérer" danger onConfirm={regenerate}>
  <p>Une nouvelle adresse sera créée. L’ancienne cessera de fonctionner immédiatement : il faudra envoyer la nouvelle à vos interlocuteurs.</p>
</ConfirmDialog>

<style>
  .link-url {
    display: flex;
    gap: 8px;
  }

  .link-url__input {
    flex: 1;
    min-width: 0;
  }

  .link-options {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .link-option {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 12px;
    min-height: var(--hit);
  }

  .link-option__label {
    width: 110px;
    font-size: 13px;
    color: var(--text-muted);
  }

  .link-option__value {
    font-size: 13px;
    min-width: 60px;
  }

  .link-option__form {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    flex: 1;
  }

  .link-option__form .input {
    flex: 1 1 180px;
  }

  .link-option__date {
    width: auto;
    color-scheme: dark;
  }
</style>
