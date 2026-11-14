<script lang="ts">
  import { onMount } from 'svelte';
  import { api, ApiError, setCsrfToken } from '../lib/api';
  import { navigate } from '../lib/router.svelte';
  import AuthScreen from './AuthScreen.svelte';
  import { ROLE_LABELS, type AdminSession, type Role } from './session';

  const MIN_PASSWORD_LENGTH = 12;

  let { token, onDone }: { token: string; onDone: (session: AdminSession) => void } = $props();

  let invitation = $state<{ organization: string; email: string; role: Role } | null>(null);
  let invalid = $state(false);
  let name = $state('');
  let password = $state('');
  let confirmation = $state('');
  let error = $state('');
  let invalidField = $state('');
  let busy = $state(false);

  onMount(async () => {
    try {
      invitation = await api('GET', `/api/admin/invitation/${token}`);
    } catch {
      invalid = true;
    }
  });

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (busy) return;
    invalidField = '';
    if (name.trim().length < 2) {
      [invalidField, error] = ['name', 'Votre nom doit compter au moins 2 caractères.'];
      return;
    }
    if (password.length < MIN_PASSWORD_LENGTH) {
      [invalidField, error] = ['password', `Le mot de passe doit compter au moins ${MIN_PASSWORD_LENGTH} caractères.`];
      return;
    }
    if (password !== confirmation) {
      [invalidField, error] = ['confirmation', 'Les deux mots de passe ne correspondent pas.'];
      return;
    }
    busy = true;
    error = '';
    try {
      await api('POST', `/api/admin/invitation/${token}`, { name, password, confirmation });
      const session = await api<AdminSession>('GET', '/api/admin/session');
      setCsrfToken(session.csrf);
      navigate('/admin', { replace: true });
      onDone(session);
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
      invalidField = e instanceof ApiError && typeof e.data.field === 'string' ? e.data.field : '';
    } finally {
      busy = false;
    }
  }
</script>

{#if invalid}
  <AuthScreen eyebrow="Invitation" title="Lien non valable">
    <p class="muted">Ce lien d’invitation a expiré ou a déjà servi. Demandez à l’administrateur de votre organisation de vous en renvoyer un.</p>
    <a class="btn btn--block" href="/admin">Aller à la connexion</a>
  </AuthScreen>
{:else if invitation}
  <AuthScreen eyebrow="Invitation" title="Rejoindre {invitation.organization}">
    <p class="muted intro">
      Vous êtes invité en tant que <strong>{ROLE_LABELS[invitation.role].toLowerCase()}</strong>. Votre identifiant de connexion sera
      <strong>{invitation.email}</strong>.
    </p>
    <form onsubmit={submit} novalidate>
      <div class="field">
        <label class="field__label" for="invite-name">Votre prénom et nom</label>
        <input id="invite-name" class="input" autocomplete="name" required placeholder="Camille Durand" aria-describedby="invite-name-hint" aria-invalid={invalidField === 'name'} bind:value={name} />
        <p class="field__hint" id="invite-name-hint">Vous, la personne. L’organisation « {invitation.organization} » existe déjà.</p>
      </div>
      <input class="visually-hidden" type="email" autocomplete="username" value={invitation.email} readonly tabindex="-1" aria-hidden="true" />
      <div class="field">
        <label class="field__label" for="invite-password">Mot de passe</label>
        <input
          id="invite-password"
          class="input"
          type="password"
          autocomplete="new-password"
          required
          aria-describedby="invite-password-hint"
          aria-invalid={invalidField === 'password'}
          bind:value={password}
        />
        <p class="field__hint" id="invite-password-hint">Au moins {MIN_PASSWORD_LENGTH} caractères.</p>
      </div>
      <div class="field">
        <label class="field__label" for="invite-confirmation">Confirmer le mot de passe</label>
        <input
          id="invite-confirmation"
          class="input"
          type="password"
          autocomplete="new-password"
          required
          aria-invalid={invalidField === 'confirmation'}
          bind:value={confirmation}
        />
      </div>
      {#if error}<p class="form-error" role="alert">{error}</p>{/if}
      <button class="btn btn--primary btn--block" type="submit" disabled={busy}>{busy ? 'Création…' : 'Créer mon compte'}</button>
    </form>
  </AuthScreen>
{:else}
  <AuthScreen eyebrow="Invitation" title="Chargement…">
    <p class="muted">Un instant…</p>
  </AuthScreen>
{/if}

<style>
  .intro {
    margin-bottom: 18px;
    line-height: 1.5;
  }
</style>
