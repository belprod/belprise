<script lang="ts">
  import { onMount } from 'svelte';
  import { api, ApiError } from '../lib/api';
  import AuthScreen from './AuthScreen.svelte';

  /** Reset link received by e-mail (§5.10): new password, or two-factor turned off. */
  const MIN_PASSWORD_LENGTH = 12;

  let { token }: { token: string } = $props();

  let reset = $state<{ kind: 'password' | 'totp'; name: string; email: string; organization: string } | null>(null);
  let invalid = $state(false);
  let done = $state(false);
  let password = $state('');
  let confirmation = $state('');
  let error = $state('');
  let invalidField = $state('');
  let busy = $state(false);

  onMount(async () => {
    try {
      reset = await api('GET', `/api/admin/reset/${token}`);
    } catch {
      invalid = true;
    }
  });

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (busy || !reset) return;
    invalidField = '';
    if (reset.kind === 'password') {
      if (password.length < MIN_PASSWORD_LENGTH) {
        [invalidField, error] = ['password', `Le mot de passe doit compter au moins ${MIN_PASSWORD_LENGTH} caractères.`];
        return;
      }
      if (password !== confirmation) {
        [invalidField, error] = ['confirmation', 'Les deux mots de passe ne correspondent pas.'];
        return;
      }
    }
    busy = true;
    error = '';
    try {
      await api('POST', `/api/admin/reset/${token}`, reset.kind === 'password' ? { password, confirmation } : { password });
      done = true;
      password = confirmation = '';
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
      invalidField = e instanceof ApiError && typeof e.data.field === 'string' ? e.data.field : '';
    } finally {
      busy = false;
    }
  }
</script>

{#if invalid}
  <AuthScreen eyebrow="Réinitialisation" title="Lien non valable">
    <p class="text">Ce lien a déjà servi ou a expiré (il est valable 1 heure). Demandez-en un nouveau à l’administrateur de votre organisation.</p>
    <a class="btn btn--block" href="/admin">Aller à la connexion</a>
  </AuthScreen>
{:else if done && reset}
  <AuthScreen eyebrow="Réinitialisation" title="C’est fait">
    <p class="text" role="status">
      {reset.kind === 'password'
        ? 'Votre nouveau mot de passe est enregistré.'
        : 'La double authentification est désactivée. Après connexion, réactivez-la avec votre nouveau téléphone dans « Mon compte ».'}
    </p>
    <a class="btn btn--primary btn--block" href="/admin">Se connecter</a>
  </AuthScreen>
{:else if reset}
  <AuthScreen eyebrow={reset.organization} title={reset.kind === 'password' ? 'Nouveau mot de passe' : 'Double authentification'}>
    <p class="text">Compte <strong>{reset.email}</strong> ({reset.name}).</p>
    <form onsubmit={submit} novalidate>
      <input class="visually-hidden" type="email" autocomplete="username" value={reset.email} readonly tabindex="-1" aria-hidden="true" />
      <div class="field">
        <label class="field__label" for="reset-password">{reset.kind === 'password' ? 'Nouveau mot de passe' : 'Votre mot de passe actuel'}</label>
        <input
          id="reset-password"
          class="input"
          type="password"
          autocomplete={reset.kind === 'password' ? 'new-password' : 'current-password'}
          aria-invalid={invalidField === 'password'}
          bind:value={password}
        />
        {#if reset.kind === 'password'}<p class="field__hint">Au moins {MIN_PASSWORD_LENGTH} caractères.</p>{/if}
      </div>
      {#if reset.kind === 'password'}
        <div class="field">
          <label class="field__label" for="reset-confirmation">Confirmer le mot de passe</label>
          <input id="reset-confirmation" class="input" type="password" autocomplete="new-password" aria-invalid={invalidField === 'confirmation'} bind:value={confirmation} />
        </div>
      {:else}
        <p class="text muted">La double authentification sera désactivée ; vous la réactiverez dans « Mon compte ».</p>
      {/if}
      {#if error}<p class="form-error" role="alert">{error}</p>{/if}
      <button class="btn btn--primary btn--block" type="submit" disabled={busy}>
        {busy ? 'Enregistrement…' : reset.kind === 'password' ? 'Enregistrer le mot de passe' : 'Désactiver la double authentification'}
      </button>
    </form>
  </AuthScreen>
{:else}
  <AuthScreen eyebrow="Réinitialisation" title="Chargement…"><p class="text muted">Vérification du lien…</p></AuthScreen>
{/if}

<style>
  .text {
    margin-bottom: 16px;
    line-height: 1.55;
  }
</style>
