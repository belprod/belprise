<script lang="ts">
  import { renderSVG } from 'uqr';
  import { api, ApiError } from '../../lib/api';
  import type { AdminSession } from '../session';

  /** Two-factor set-up (§5.7): scan, type a code and the password; the recovery codes come back once. */
  let {
    onEnabled,
    autoStart = false,
  }: { onEnabled: (session: AdminSession, recoveryCodes: string[]) => void; autoStart?: boolean } = $props();

  let setup = $state<{ secret: string; uri: string } | null>(null);
  let code = $state('');
  let password = $state('');
  let busy = $state(false);
  let error = $state('');

  const qr = $derived(setup ? renderSVG(setup.uri, { border: 2 }) : '');
  const grouped = $derived(setup ? (setup.secret.match(/.{1,4}/g) ?? []).join(' ') : '');

  async function start(): Promise<void> {
    error = '';
    try {
      setup = await api<{ secret: string; uri: string }>('POST', '/api/admin/totp/setup');
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Mise en place impossible.';
    }
  }

  $effect(() => {
    if (autoStart && !setup) void start();
  });

  async function enable(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (busy) return;
    busy = true;
    error = '';
    try {
      const result = await api<AdminSession & { recoveryCodes: string[] }>('POST', '/api/admin/totp/enable', { code, password });
      setup = null;
      code = password = '';
      onEnabled(result, result.recoveryCodes);
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Activation impossible.';
    } finally {
      busy = false;
    }
  }
</script>

{#if setup}
  <ol class="steps">
    <li>
      Scannez ce code avec l’application (Google Authenticator, 1Password, Authy…) :
      <span class="qr" role="img" aria-label="Code QR de configuration">{@html qr}</span>
      ou saisissez la clé <span class="mono key">{grouped}</span>
    </li>
    <li>
      <form class="code-form" onsubmit={enable}>
        <label class="field__label" for="totp-code">Saisissez le code affiché par l’application</label>
        <input class="input input--mono" id="totp-code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" bind:value={code} />
        <label class="field__label" for="totp-password">Votre mot de passe, pour confirmer</label>
        <span class="code-form__row">
          <input class="input" id="totp-password" type="password" autocomplete="current-password" bind:value={password} />
          <button class="btn btn--primary" type="submit" disabled={busy || code.replace(/\s/g, '').length !== 6 || !password}>Activer</button>
        </span>
      </form>
    </li>
  </ol>
{:else if !autoStart}
  <div class="form-actions"><button class="btn btn--primary" type="button" onclick={start}>Mettre en place</button></div>
{/if}
{#if error}<p class="form-error" role="alert">{error}</p>{/if}

<style>
  .steps {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin: 0;
    padding-left: 18px;
    font-size: 13.5px;
  }

  .qr {
    display: block;
    width: 184px;
    margin: 10px 0;
    padding: 8px;
    border-radius: var(--radius-md);
    background: #fff;
  }

  .qr :global(svg) {
    display: block;
    width: 100%;
    height: auto;
  }

  .key {
    word-break: break-all;
  }

  .code-form {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .code-form__row {
    display: flex;
    gap: 8px;
  }

  .code-form .input {
    max-width: 240px;
  }

  .code-form .input--mono {
    max-width: 160px;
  }
</style>
