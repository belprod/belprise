<script lang="ts">
  import { onMount, tick } from 'svelte';
  import { api, ApiError } from '../lib/api';
  import AuthScreen from './AuthScreen.svelte';
  import { untilReset, type DemoAccount, type DemoState } from './demo';
  import { login, loginCode, ROLE_LABELS, SIGNED_OUT, type AdminSession } from './session';

  let {
    onDone,
    codeStep = false,
    demo = null,
  }: { onDone: (session: AdminSession) => void; codeStep?: boolean; demo?: DemoState | null } = $props();

  /** Public demo: one click per shared account (§5.16). */
  function enterAs(account: DemoAccount, event: MouseEvent): void {
    email = account.email;
    password = account.password;
    void submit(event);
  }

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

  async function submit(event: Event): Promise<void> {
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
  <AuthScreen eyebrow={demo ? 'Démo publique' : 'Administration'} title={forgotMode ? 'Mot de passe oublié' : 'Connexion'}>
    {#if demo}
      <section class="demo" aria-labelledby="demo-title">
        <h2 id="demo-title" class="demo__title">Entrez avec un compte de démonstration</h2>
        <div class="demo__accounts">
          {#each demo.accounts as account (account.email)}
            <button class="btn demo__account" type="button" disabled={busy} onclick={(e) => enterAs(account, e)}>
              <span class="demo__role">{ROLE_LABELS[account.role]}</span>
              <span class="demo__email">{account.email}</span>
            </button>
          {/each}
        </div>
        <p class="demo__note">
          Mot de passe des trois comptes : <code>{demo.accounts[0]?.password}</code>.
          Tout est remis à zéro toutes les 6 heures (prochaine fois dans {untilReset(demo.resetAt)}).
          L’envoi de fichiers et l’envoi d’e-mails sont désactivés.
        </p>
        <p class="demo__credit">{demo.credit}</p>
      </section>
    {/if}
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
      {#if !demo}
        <p class="forgot">
          <button class="link" type="button" onclick={() => ((forgotMode = true), (error = ''))}>Mot de passe oublié ?</button>
        </p>
      {/if}
    </form>
    {/if}
  </AuthScreen>
{/if}

<style>
  .demo {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border);
  }

  .demo__title {
    font-size: 14px;
    font-weight: 600;
  }

  .demo__accounts {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .demo__account {
    justify-content: space-between;
    gap: 12px;
    width: 100%;
  }

  .demo__role {
    font-weight: 600;
  }

  .demo__email {
    min-width: 0;
    overflow: hidden;
    color: var(--text-muted);
    font-family: var(--font-mono);
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .demo__note,
  .demo__credit {
    color: var(--text-muted);
    font-size: 13px;
    line-height: 1.5;
  }

  .demo__credit {
    font-size: 12px;
  }

  .demo__note code {
    color: var(--text);
    font-family: var(--font-mono);
  }

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
