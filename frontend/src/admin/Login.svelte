<script lang="ts">
  import { onMount, tick } from 'svelte';
  import { api, ApiError } from '../lib/api';
  import AuthScreen from './AuthScreen.svelte';
  import { login, loginCode, SIGNED_OUT, type AdminSession } from './session';

  let { onDone, codeStep = false }: { onDone: (session: AdminSession) => void; codeStep?: boolean } = $props();

  let email = $state('');
  let password = $state('');
  let code = $state('');
  let error = $state('');
  let busy = $state(false);
  let emailInput = $state<HTMLInputElement | null>(null);
  let codeInput = $state<HTMLInputElement | null>(null);
  /** Phone lost: one of the ten recovery codes instead of the 6 digits. */
  let recovery = $state(false);
  const codeReady = $derived(recovery ? code.replace(/[\s-]/g, '').length === 10 : code.replace(/\s/g, '').length === 6);

  // "Mot de passe oublié ?" (§5.10): the same answer whether the address has an account or not.
  let forgotMode = $state(false);
  let forgotSent = $state(false);

  async function sendForgot(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (busy) return;
    busy = true;
    error = '';
    try {
      await api('POST', '/api/admin/forgot', { email });
      forgotSent = true;
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
    } finally {
      busy = false;
    }
  }

  onMount(() => (codeStep ? codeInput : emailInput)?.focus());

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (busy) return;
    busy = true;
    error = '';
    try {
      const session = await login(email, password);
      password = '';
      onDone(session);
      if (session.totpRequired) {
        await tick();
        codeInput?.focus();
      }
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
      password = '';
    } finally {
      busy = false;
    }
  }

  async function submitCode(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (busy) return;
    busy = true;
    error = '';
    try {
      onDone(await loginCode(code));
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
      code = '';
      if (e instanceof ApiError && e.data.restart) {
        onDone(SIGNED_OUT);
      }
    } finally {
      busy = false;
    }
  }
</script>

{#if codeStep}
  <AuthScreen eyebrow="Double authentification" title="Code de vérification">
    <form onsubmit={submitCode} novalidate>
      <div class="field">
        <label class="field__label" for="login-code">
          {recovery ? 'Un de vos codes de secours (10 caractères)' : 'Code à 6 chiffres de votre application d’authentification'}
        </label>
        <input
          id="login-code"
          class="input input--mono"
          inputmode={recovery ? 'text' : 'numeric'}
          autocomplete={recovery ? 'off' : 'one-time-code'}
          autocapitalize="none"
          spellcheck="false"
          maxlength={recovery ? 13 : 7}
          required
          bind:value={code}
          bind:this={codeInput}
        />
      </div>
      {#if error}<p class="form-error" role="alert">{error}</p>{/if}
      <button class="btn btn--primary btn--block" type="submit" disabled={busy || !codeReady}>
        {busy ? 'Vérification…' : 'Valider'}
      </button>
      <p class="forgot">
        <button class="link" type="button" onclick={() => ((recovery = !recovery), (code = ''), (error = ''), codeInput?.focus())}>
          {recovery ? 'Utiliser l’application d’authentification' : 'Téléphone perdu ? Utiliser un code de secours'}
        </button>
      </p>
    </form>
  </AuthScreen>
{:else}
  <AuthScreen eyebrow="Administration" title={forgotMode ? 'Mot de passe oublié' : 'Connexion'}>
    {#if forgotMode}
      {#if forgotSent}
        <p class="sent" role="status">
          Si un compte existe pour <strong>{email}</strong>, un e-mail vient de partir avec un lien pour choisir un nouveau mot de passe
          (valable 1 heure). Pensez à regarder dans les indésirables.
        </p>
      {:else}
        <form onsubmit={sendForgot} novalidate>
          <p class="hint">Saisissez l’adresse de votre compte : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
          <div class="field">
            <label class="field__label" for="forgot-email">E-mail</label>
            <!-- svelte-ignore a11y_autofocus -->
            <input id="forgot-email" class="input" inputmode="email" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus bind:value={email} />
          </div>
          {#if error}<p class="form-error" role="alert">{error}</p>{/if}
          <button class="btn btn--primary btn--block" type="submit" disabled={busy || !email.trim()}>{busy ? 'Envoi…' : 'Recevoir le lien'}</button>
        </form>
      {/if}
      <p class="forgot">
        <button class="link" type="button" onclick={() => ((forgotMode = false), (forgotSent = false), (error = ''))}>Retour à la connexion</button>
      </p>
    {:else}
    <form onsubmit={submit} novalidate>
      <div class="field">
        <label class="field__label" for="login-email">E-mail</label>
        <input
          id="login-email"
          class="input"
          name="email"
          inputmode="email"
          autocomplete="username"
          autocapitalize="none"
          spellcheck="false"
          required
          bind:value={email}
          bind:this={emailInput}
        />
      </div>
      <div class="field">
        <label class="field__label" for="login-password">Mot de passe</label>
        <input
          id="login-password"
          class="input"
          type="password"
          name="password"
          autocomplete="current-password"
          required
          bind:value={password}
        />
      </div>
      {#if error}
        <p class="form-error" role="alert">{error}</p>
      {/if}
      <button class="btn btn--primary btn--block" type="submit" disabled={busy}>
        {busy ? 'Connexion…' : 'Se connecter'}
      </button>
      <p class="forgot">
        <button class="link" type="button" onclick={() => ((forgotMode = true), (error = ''))}>Mot de passe oublié ?</button>
      </p>
    </form>
    {/if}
  </AuthScreen>
{/if}

<style>
  .link {
    padding: 0;
    border: none;
    background: none;
    color: var(--text-muted);
    font: inherit;
    text-decoration: underline;
    cursor: pointer;
  }

  .link:hover {
    color: var(--text);
  }

  .hint,
  .sent {
    margin-bottom: 16px;
    color: var(--text-muted);
    font-size: 14px;
    line-height: 1.5;
  }

  .sent strong {
    color: var(--text);
    overflow-wrap: anywhere;
  }

  .forgot {
    margin-top: 16px;
    color: var(--text-muted);
    font-size: 13px;
    text-align: center;
  }
</style>
