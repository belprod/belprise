<script lang="ts">
  import { untrack } from 'svelte';
  import { api, ApiError } from '../../lib/api';
  import { formatBytes } from '../../lib/format';
  import PageHead from '../components/PageHead.svelte';
  import RecoveryCodesList from '../components/RecoveryCodesList.svelte';
  import TotpSetup from '../components/TotpSetup.svelte';
  import { ROLE_LABELS, type AdminSession, type StorageUsage } from '../session';

  let { session, onSession }: { session: AdminSession; onSession: (session: AdminSession) => void } = $props();
  let storage = $state<StorageUsage | null>(untrack(() => session.storage ?? null));

  // Profile (the e-mail is the login: changing it needs the password)
  // Form fields start from the session as it was when the page opened.
  let name = $state(untrack(() => session.user?.name ?? ''));
  let email = $state(untrack(() => session.user?.email ?? ''));
  let profilePassword = $state('');
  let profileBusy = $state(false);
  let profileError = $state('');
  let profileDone = $state(false);
  const emailChanged = $derived(email.trim().toLowerCase() !== (session.user?.email ?? '').toLowerCase());

  // Password
  let current = $state('');
  let password = $state('');
  let confirmation = $state('');
  let passwordBusy = $state(false);
  let passwordError = $state('');
  let passwordDone = $state(false);

  // TOTP and its recovery codes (shown once, after turning it on or renewing them)
  let disablePassword = $state('');
  let renewPassword = $state('');
  let totpBusy = $state(false);
  let totpError = $state('');
  let recoveryCodes = $state<string[] | null>(null);
  const required = $derived(session.organization?.totpRequired === true);
  const usedShare = $derived(storage ? Math.min(100, storage.ratio * 100) : 0);

  $effect(() => {
    api<{ storage: StorageUsage }>('GET', '/api/admin/dashboard').then((d) => (storage = d.storage)).catch(() => {});
  });

  async function saveProfile(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    profileBusy = true;
    profileError = '';
    profileDone = false;
    try {
      onSession(await api<AdminSession>('PATCH', '/api/admin/account', { name, email, password: profilePassword }));
      profilePassword = '';
      profileDone = true;
    } catch (e) {
      profileError = e instanceof ApiError ? e.message : 'Enregistrement impossible.';
    } finally {
      profileBusy = false;
    }
  }

  async function changePassword(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    passwordBusy = true;
    passwordError = '';
    passwordDone = false;
    try {
      await api('POST', '/api/admin/password', { current, password, confirmation });
      current = password = confirmation = '';
      passwordDone = true;
    } catch (e) {
      passwordError = e instanceof ApiError ? e.message : 'Changement impossible.';
    } finally {
      passwordBusy = false;
    }
  }

  function enabled(next: AdminSession, codes: string[]): void {
    onSession(next);
    recoveryCodes = codes;
  }

  async function renewCodes(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    totpBusy = true;
    totpError = '';
    try {
      const result = await api<AdminSession & { recoveryCodes: string[] }>('POST', '/api/admin/totp/recovery-codes', { password: renewPassword });
      onSession(result);
      recoveryCodes = result.recoveryCodes;
      renewPassword = '';
    } catch (e) {
      totpError = e instanceof ApiError ? e.message : 'Génération impossible.';
    } finally {
      totpBusy = false;
    }
  }

  async function disableTotp(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    totpBusy = true;
    totpError = '';
    try {
      onSession(await api<AdminSession>('POST', '/api/admin/totp/disable', { password: disablePassword }));
      disablePassword = '';
    } catch (e) {
      totpError = e instanceof ApiError ? e.message : 'Désactivation impossible.';
    } finally {
      totpBusy = false;
    }
  }
</script>

<PageHead eyebrow="Réglages" title="Mon compte" />

<div class="panels">
  {#if session.demo}
    <section class="panel" aria-labelledby="demo-title">
      <h2 class="panel__title" id="demo-title">Compte de démonstration</h2>
      <p class="panel__text">
        {session.user?.name} — {session.user?.email}{session.user ? ` — ${ROLE_LABELS[session.user.role]}` : ''}.
      </p>
      <p class="panel__text">
        Les comptes de la démo sont partagés : le profil, le mot de passe et la double authentification ne sont pas modifiables ici.
        Sur votre propre installation, chacun gère son compte depuis cette page.
      </p>
    </section>
  {:else}
  <form class="panel" onsubmit={saveProfile} novalidate aria-labelledby="profile-title">
    <h2 class="panel__title" id="profile-title">Profil</h2>
    <div class="form-grid">
      <div class="field">
        <label class="field__label" for="profile-name">Votre prénom et nom</label>
        <input class="input" id="profile-name" autocomplete="name" bind:value={name} />
        <p class="field__hint">Il apparaît dans l’équipe de {session.organization?.name}.</p>
      </div>
      <div class="field">
        <label class="field__label" for="profile-email">E-mail de connexion</label>
        <input class="input" id="profile-email" type="email" autocomplete="username" autocapitalize="none" spellcheck="false" bind:value={email} />
      </div>
      {#if emailChanged}
        <div class="field">
          <label class="field__label" for="profile-password">Mot de passe, pour changer d’e-mail</label>
          <input class="input" id="profile-password" type="password" autocomplete="current-password" bind:value={profilePassword} />
        </div>
      {/if}
    </div>
    {#if profileError}<p class="form-error" role="alert">{profileError}</p>{/if}
    <div class="form-actions">
      <button class="btn btn--primary" type="submit" disabled={profileBusy || (emailChanged && !profilePassword)}>Enregistrer</button>
      {#if profileDone}<span class="saved" role="status">Profil enregistré</span>{/if}
    </div>
  </form>

  <form class="panel" onsubmit={changePassword} novalidate aria-labelledby="password-title">
    <h2 class="panel__title" id="password-title">Mot de passe</h2>
    <div class="form-grid">
      <div class="field">
        <label class="field__label" for="pw-current">Mot de passe actuel</label>
        <input class="input" id="pw-current" type="password" autocomplete="current-password" bind:value={current} />
      </div>
      <div class="field">
        <label class="field__label" for="pw-new">Nouveau mot de passe</label>
        <input class="input" id="pw-new" type="password" autocomplete="new-password" aria-describedby="pw-hint" bind:value={password} />
        <p class="field__hint" id="pw-hint">Au moins 12 caractères.</p>
      </div>
      <div class="field">
        <label class="field__label" for="pw-confirm">Confirmer le nouveau mot de passe</label>
        <input class="input" id="pw-confirm" type="password" autocomplete="new-password" bind:value={confirmation} />
      </div>
    </div>
    {#if passwordError}<p class="form-error" role="alert">{passwordError}</p>{/if}
    <div class="form-actions">
      <button class="btn btn--primary" type="submit" disabled={passwordBusy || !current || !password}>Changer le mot de passe</button>
      {#if passwordDone}<span class="saved" role="status">Mot de passe changé</span>{/if}
    </div>
  </form>

  <section class="panel" aria-labelledby="totp-title">
    <div class="panel__head">
      <h2 class="panel__title" id="totp-title">Double authentification</h2>
      <span class="pill {session.totpEnabled ? 'pill--ok' : 'pill--off'}">{session.totpEnabled ? 'Activée' : 'Désactivée'}</span>
    </div>
    <p class="panel__text">
      Un code à 6 chiffres, donné par une application d’authentification (Google Authenticator, 1Password, Authy…), est demandé
      après le mot de passe. {required ? 'Obligatoire dans votre organisation.' : 'Recommandé.'}
    </p>

    {#if recoveryCodes}
      <RecoveryCodesList codes={recoveryCodes} email={session.user?.email ?? ''} onDone={() => (recoveryCodes = null)} />
    {/if}

    {#if session.totpEnabled}
      <p class="panel__text">
        Codes de secours restants : <strong>{session.recoveryCodesLeft ?? 0}</strong> sur 10.
        {#if (session.recoveryCodesLeft ?? 0) <= 3}Pensez à en générer de nouveaux.{/if}
      </p>
      <form class="form-grid" onsubmit={renewCodes}>
        <div class="field">
          <label class="field__label" for="totp-renew">Mot de passe, pour de nouveaux codes de secours</label>
          <input class="input" id="totp-renew" type="password" autocomplete="current-password" bind:value={renewPassword} />
        </div>
        <div class="form-actions">
          <button class="btn" type="submit" disabled={totpBusy || !renewPassword}>Nouveaux codes (les anciens ne marchent plus)</button>
        </div>
      </form>
      {#if !required}
        <form class="form-grid" onsubmit={disableTotp}>
          <div class="field">
            <label class="field__label" for="totp-disable">Mot de passe, pour désactiver</label>
            <input class="input" id="totp-disable" type="password" autocomplete="current-password" bind:value={disablePassword} />
          </div>
          <div class="form-actions">
            <button class="btn" type="submit" disabled={totpBusy || !disablePassword}>Désactiver</button>
          </div>
        </form>
      {/if}
    {:else}
      <TotpSetup onEnabled={enabled} />
    {/if}
    {#if totpError}<p class="form-error" role="alert">{totpError}</p>{/if}
    <p class="field__hint">
      Téléphone perdu ? Connectez-vous avec un code de secours, ou demandez à l’administrateur de votre organisation de réinitialiser votre double authentification.
    </p>
  </section>
  {/if}

  <section class="panel" aria-labelledby="storage-title">
    <h2 class="panel__title" id="storage-title">Stockage</h2>
    {#if storage}
      <dl class="storage">
        <div><dt>Utilisé</dt><dd>{formatBytes(storage.used + storage.reserved)}</dd></div>
        <div><dt>Quota</dt><dd>{storage.quotaLabel}</dd></div>
      </dl>
      {#if storage.quota !== null}
        <div class="meter" role="meter" aria-label="Stockage utilisé" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(usedShare)}>
          <div class="meter__fill" class:meter__fill--warn={storage.ratio >= 0.8} style:width="{usedShare}%"></div>
        </div>
      {/if}
      <p class="panel__text">
        {#if storage.quota !== null}{Math.round(usedShare)} % du quota de {session.organization?.name}, fixé par l’exploitant.{:else}Pas de limite fixée par l’exploitant : seul l’espace du serveur compte.{/if}
        {#if storage.reserved > 0}Dont {formatBytes(storage.reserved)} réservés par des envois en cours.{/if}
        Masters, images, logos et envois en cours sont comptés.
      </p>
    {:else}
      <p class="loading">Calcul…</p>
    {/if}
  </section>
</div>

<style>
  .meter__fill.meter__fill--warn {
    background: var(--status-changes);
  }

  .storage {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin: 0;
  }

  dt {
    font-size: 12.5px;
    color: var(--text-muted);
  }

  dd {
    margin: 2px 0 0;
    font-family: var(--font-display);
    font-size: 28px;
    line-height: 1.1;
  }

  .meter {
    height: 8px;
    border-radius: 4px;
    background: var(--surface-3);
    overflow: hidden;
  }

  .meter__fill {
    height: 100%;
    background: var(--accent);
  }
</style>
