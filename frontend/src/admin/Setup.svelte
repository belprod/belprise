<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api';
  import AuthScreen from './AuthScreen.svelte';
  import { setupAccount, type AdminSession } from './session';

  const MIN_PASSWORD_LENGTH = 12;

  let { onDone }: { onDone: (session: AdminSession) => void } = $props();

  let organization = $state('');
  let name = $state('');
  let email = $state('');
  let password = $state('');
  let confirmation = $state('');
  let error = $state('');
  let invalidField = $state('');
  let busy = $state(false);
  let firstInput: HTMLInputElement;

  onMount(() => firstInput.focus());

  function validate(): string {
    const checks: [boolean, string, string][] = [
      [organization.trim().length < 2, 'organization', 'Le nom de l’organisation doit compter au moins 2 caractères.'],
      [name.trim().length < 2, 'name', 'Votre nom doit compter au moins 2 caractères.'],
      [!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim()), 'email', 'Saisissez une adresse e-mail valide.'],
      [password.length < MIN_PASSWORD_LENGTH, 'password', `Le mot de passe doit compter au moins ${MIN_PASSWORD_LENGTH} caractères.`],
      [password !== confirmation, 'confirmation', 'Les deux mots de passe ne correspondent pas.'],
    ];
    const failed = checks.find(([bad]) => bad);
    invalidField = failed?.[1] ?? '';
    return failed?.[2] ?? '';
  }

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (busy) return;
    error = validate();
    if (error) return;
    busy = true;
    try {
      onDone(await setupAccount({ organization, name, email, password, confirmation }));
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
      invalidField = e instanceof ApiError && typeof e.data.field === 'string' ? e.data.field : '';
    } finally {
      busy = false;
    }
  }
</script>

<AuthScreen eyebrow="Premier lancement" title="Créer votre organisation">
  <form onsubmit={submit} novalidate>
    <div class="field">
      <label class="field__label" for="setup-organization">Nom de l’organisation</label>
      <input
        id="setup-organization"
        class="input"
        name="organization"
        autocomplete="organization"
        required
        aria-invalid={invalidField === 'organization'}
        bind:value={organization}
        bind:this={firstInput}
      />
    </div>
    <div class="field">
      <label class="field__label" for="setup-name">Votre prénom et nom</label>
      <input id="setup-name" class="input" name="name" autocomplete="name" required aria-invalid={invalidField === 'name'} bind:value={name} />
    </div>
    <div class="field">
      <label class="field__label" for="setup-email">E-mail</label>
      <input
        id="setup-email"
        class="input"
        type="email"
        name="email"
        autocomplete="username"
        autocapitalize="none"
        spellcheck="false"
        required
        aria-invalid={invalidField === 'email'}
        bind:value={email}
      />
    </div>
    <div class="field">
      <label class="field__label" for="setup-password">Mot de passe</label>
      <input
        id="setup-password"
        class="input"
        type="password"
        name="password"
        autocomplete="new-password"
        required
        aria-describedby="setup-password-hint"
        aria-invalid={invalidField === 'password'}
        bind:value={password}
      />
      <p class="field__hint" id="setup-password-hint">Au moins {MIN_PASSWORD_LENGTH} caractères.</p>
    </div>
    <div class="field">
      <label class="field__label" for="setup-confirmation">Confirmer le mot de passe</label>
      <input
        id="setup-confirmation"
        class="input"
        type="password"
        name="confirmation"
        autocomplete="new-password"
        required
        aria-invalid={invalidField === 'confirmation'}
        bind:value={confirmation}
      />
    </div>
    {#if error}
      <p class="form-error" role="alert">{error}</p>
    {/if}
    <button class="btn btn--primary btn--block" type="submit" disabled={busy}>
      {busy ? 'Création…' : 'Créer le compte'}
    </button>
  </form>
</AuthScreen>
