<script lang="ts">
  import { api, ApiError, upload } from '../../lib/api';
  import { contrastRatio, formatRatio } from '../../lib/contrast';
  import { formatDate, formatLongDate } from '../../lib/format';
  import ColorField from '../components/ColorField.svelte';
  import ConfirmDialog from '../components/ConfirmDialog.svelte';
  import PageHead from '../components/PageHead.svelte';
  import { current } from '../current.svelte';
  import { ROLE_LABELS, type Role, type SessionOrganization } from '../session';

  interface Member {
    id: number;
    name: string;
    email: string;
    role: Role;
    totp: boolean;
    lastLoginAt: string | null;
    me: boolean;
  }

  interface Invitation {
    id: number;
    email: string;
    role: Role;
    expiresAt: string;
    expired: boolean;
  }

  interface Team {
    users: Member[];
    invitations: Invitation[];
  }

  /** The team: one administrator per organization (§2.2), the others are editors or viewers. */
  const TEAM_ROLES: Role[] = ['editor', 'viewer'];
  const ROLE_HINTS: Record<Role, string> = {
    admin: 'tout, y compris supprimer et gérer l’équipe',
    editor: 'créer, modifier, archiver, importer, répondre',
    viewer: 'consulter seulement',
  };

  let team = $state<Team | null>(null);
  let error = $state('');

  // Invitation form
  let email = $state('');
  let role = $state<Role>('editor');
  let inviteBusy = $state(false);
  let inviteError = $state('');
  let sentLink = $state<{ email: string; link: string; sent: boolean } | null>(null);
  let copied = $state(false);

  // Organization name
  let orgName = $state(current.session?.organization?.name ?? '');
  let orgBusy = $state(false);
  let orgDone = $state(false);
  let orgError = $state('');

  let journal = $state<{ actor: string; action: string; details: string; at: string }[]>([]);

  let removing = $state<Member | null>(null);

  // Reset by e-mail (§5.10): the link goes to the member, never to the administrator.
  let resetting = $state<{ member: Member; kind: 'password' | 'totp' } | null>(null);
  let confirmReset = $state(false);
  let resetDone = $state('');

  function askReset(member: Member, kind: 'password' | 'totp'): void {
    resetting = { member, kind };
    resetDone = '';
    confirmReset = true;
  }

  async function sendReset(): Promise<void> {
    if (!resetting) return;
    const result = await api<{ sent: boolean; email: string }>('POST', `/api/admin/users/${resetting.member.id}/reset`, { kind: resetting.kind });
    resetDone = result.sent
      ? `Lien envoyé à ${result.email} (valable 1 heure).`
      : `L’e-mail à ${result.email} n’a pas pu partir : réessayez plus tard.`;
  }
  let confirmRemove = $state(false);

  // Handing the administrator role over (§2.2): the password is asked again.
  const candidates = $derived(team ? team.users.filter((u) => u.role !== 'admin') : []);
  let transferTo = $state('');
  let transferPassword = $state('');
  let transferBusy = $state(false);
  let transferError = $state('');

  async function transfer(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (transferBusy) return;
    transferBusy = true;
    transferError = '';
    try {
      await api('POST', `/api/admin/users/${transferTo}/transfer`, { password: transferPassword });
      // Now an editor: the administration reloads with the new rights.
      window.location.assign('/admin');
    } catch (e) {
      transferError = e instanceof ApiError ? e.message : 'Transfert impossible.';
      transferPassword = '';
    } finally {
      transferBusy = false;
    }
  }

  $effect(() => {
    void load();
  });

  async function load(): Promise<void> {
    try {
      api<typeof journal>('GET', '/api/admin/organization/log').then((rows) => (journal = rows)).catch(() => {});
      team = await api<Team>('GET', '/api/admin/users');
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Chargement impossible.';
    }
  }

  async function invite(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    inviteBusy = true;
    inviteError = '';
    sentLink = null;
    try {
      sentLink = await api('POST', '/api/admin/invitations', { email, role });
      email = '';
      copied = false;
      await load();
    } catch (e) {
      inviteError = e instanceof ApiError ? e.message : 'Invitation impossible.';
    } finally {
      inviteBusy = false;
    }
  }

  async function act(run: () => Promise<unknown>): Promise<void> {
    error = '';
    try {
      await run();
      await load();
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Action impossible.';
      await load();
    }
  }

  const resend = (invitation: Invitation): Promise<void> =>
    act(async () => {
      sentLink = await api('POST', `/api/admin/invitations/${invitation.id}/resend`);
      copied = false;
    });
  const cancel = (invitation: Invitation): Promise<void> => act(() => api('DELETE', `/api/admin/invitations/${invitation.id}`));
  const setRole = (member: Member, next: Role): Promise<void> => act(() => api('PATCH', `/api/admin/users/${member.id}`, { role: next }));

  async function remove(): Promise<void> {
    if (removing) await act(() => api('DELETE', `/api/admin/users/${removing!.id}`));
    removing = null;
  }

  async function copyLink(): Promise<void> {
    if (!sentLink) return;
    await navigator.clipboard.writeText(sentLink.link);
    copied = true;
  }

  // Deletion of the organization (§5.10): name and password, executed 30 days later.
  let deleteName = $state('');
  let deletePassword = $state('');
  let deleteBusy = $state(false);
  let deleteError = $state('');
  let deleteField = $state('');
  const lifecycle = $derived(current.session?.lifecycle ?? null);

  async function requestDeletion(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    deleteBusy = true;
    deleteError = '';
    deleteField = '';
    try {
      const result = await api<{ deletionAt: string }>('POST', '/api/admin/organization/deletion', { confirm: deleteName, password: deletePassword });
      if (current.session) {
        current.session.lifecycle = {
          ...(current.session.lifecycle ?? { purgeAt: null, downgradedFrom: null }),
          deletionAt: result.deletionAt,
          deletionBy: 'admin',
          deletionRequestedBy: current.session.user?.name ?? null,
        };
      }
      deleteName = deletePassword = '';
      api<typeof journal>('GET', '/api/admin/organization/log').then((rows) => (journal = rows)).catch(() => {});
    } catch (e) {
      deleteError = e instanceof ApiError ? e.message : 'Demande impossible.';
      deleteField = e instanceof ApiError && typeof e.data.field === 'string' ? e.data.field : '';
    } finally {
      deleteBusy = false;
    }
  }

  async function cancelDeletion(): Promise<void> {
    deleteBusy = true;
    deleteError = '';
    try {
      await api('DELETE', '/api/admin/organization/deletion');
      if (current.session?.lifecycle) {
        current.session.lifecycle = { ...current.session.lifecycle, deletionAt: null, deletionBy: null, deletionRequestedBy: null };
      }
      api<typeof journal>('GET', '/api/admin/organization/log').then((rows) => (journal = rows)).catch(() => {});
    } catch (e) {
      deleteError = e instanceof ApiError ? e.message : 'Annulation impossible.';
    } finally {
      deleteBusy = false;
    }
  }

  // Back-office identity (§5.7): logo, accent, dark or light background.
  interface OrganizationState {
    organization: SessionOrganization;
    theme: Record<string, string>;
  }
  /** Backgrounds of the two themes (same values as Belprise\View\AdminTheme). */
  const BACKGROUNDS = { dark: '#0a0a0b', light: '#f4f4f5' } as const;
  const MIN_ACCENT = 3;
  let accent = $state(current.session?.organization?.accentColor ?? '#e8515f');
  let theme = $state<'dark' | 'light'>(current.session?.organization?.theme ?? 'dark');
  let brandBusy = $state(false);
  let brandDone = $state(false);
  let brandError = $state('');
  let logoBusy = $state(false);
  let logoError = $state('');
  const accentRatio = $derived(/^#[0-9a-f]{6}$/i.test(accent) ? contrastRatio(accent, BACKGROUNDS[theme]) : 0);
  const onAccent = $derived(
    /^#[0-9a-f]{6}$/i.test(accent) && contrastRatio(accent, '#0a0a0b') < 4.5 && contrastRatio(accent, '#ffffff') > contrastRatio(accent, '#0a0a0b')
      ? '#ffffff'
      : '#0a0a0b',
  );

  function applyOrganization(state: OrganizationState): void {
    if (!current.session) return;
    current.session.organization = state.organization;
    current.session.theme = state.theme;
  }

  async function saveBrand(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    brandBusy = true;
    brandDone = false;
    brandError = '';
    try {
      applyOrganization(await api<OrganizationState>('PATCH', '/api/admin/organization', { accentColor: accent, theme }));
      brandDone = true;
    } catch (e) {
      brandError = e instanceof ApiError ? e.message : 'Enregistrement impossible.';
    } finally {
      brandBusy = false;
    }
  }

  async function uploadLogo(event: Event & { currentTarget: HTMLInputElement }): Promise<void> {
    const file = event.currentTarget.files?.[0];
    event.currentTarget.value = '';
    if (!file) return;
    logoBusy = true;
    logoError = '';
    const form = new FormData();
    form.append('logo', file);
    try {
      applyOrganization(await upload<OrganizationState>('/api/admin/organization/logo', form));
    } catch (e) {
      logoError = e instanceof ApiError ? e.message : 'Envoi impossible.';
    } finally {
      logoBusy = false;
    }
  }

  async function removeLogo(): Promise<void> {
    logoBusy = true;
    try {
      applyOrganization(await api<OrganizationState>('DELETE', '/api/admin/organization/logo'));
    } finally {
      logoBusy = false;
    }
  }

  // Two-factor authentication required for the whole team (§5.7).
  let securityBusy = $state(false);
  let securityError = $state('');

  async function setTotpRequired(required: boolean): Promise<void> {
    securityBusy = true;
    securityError = '';
    try {
      applyOrganization(await api<OrganizationState>('PATCH', '/api/admin/organization', { totpRequired: required }));
    } catch (e) {
      securityError = e instanceof ApiError ? e.message : 'Enregistrement impossible.';
    } finally {
      securityBusy = false;
    }
  }

  async function renameOrganization(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    orgBusy = true;
    orgDone = false;
    orgError = '';
    try {
      applyOrganization(await api<OrganizationState>('PATCH', '/api/admin/organization', { name: orgName }));
      orgDone = true;
    } catch (e) {
      orgError = e instanceof ApiError ? e.message : 'Enregistrement impossible.';
    } finally {
      orgBusy = false;
    }
  }
</script>

<PageHead eyebrow="Organisation" title="Équipe" />

<div class="panels">
  <section class="panel panel--wide" aria-labelledby="members-title">
    <div class="panel__head">
      <h2 class="panel__title" id="members-title">Utilisateurs</h2>
    </div>
    {#if error}<p class="form-error" role="alert">{error}</p>{/if}
    {#if resetDone}<p class="saved" role="status">{resetDone}</p>{/if}
    {#if team}
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>Dernière connexion</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
          <tbody>
            {#each team.users as member (member.id)}
              <tr>
                <td class="table__main-cell">
                  {member.name}{#if member.me}{' '}<span class="muted">(vous)</span>{/if}
                  {#if member.totp}<span class="pill pill--ok">2FA</span>{/if}
                </td>
                <td class="muted">{member.email}</td>
                <td>
                  {#if member.role === 'admin'}
                    <strong>{ROLE_LABELS.admin}</strong>
                  {:else}
                    <label class="visually-hidden" for="role-{member.id}">Rôle de {member.name}</label>
                    <select class="select" id="role-{member.id}" value={member.role} onchange={(e) => setRole(member, e.currentTarget.value as Role)}>
                      {#each TEAM_ROLES as r (r)}<option value={r}>{ROLE_LABELS[r]}</option>{/each}
                    </select>
                  {/if}
                </td>
                <td class="muted">{member.lastLoginAt ? formatDate(member.lastLoginAt) : 'jamais'}</td>
                <td class="table__actions">
                  {#if !member.me}
                    <button class="btn btn--small" type="button" onclick={() => askReset(member, 'password')}>Mot de passe…</button>
                    {#if member.totp}<button class="btn btn--small" type="button" onclick={() => askReset(member, 'totp')}>Double auth…</button>{/if}
                  {/if}
                  {#if member.role !== 'admin'}
                    <button class="btn btn--small" type="button" onclick={() => ((removing = member), (confirmRemove = true))}>Retirer…</button>
                  {/if}
                </td>
              </tr>
            {/each}
            {#each team.invitations as invitation (invitation.id)}
              <tr class="pending">
                <td class="muted">{invitation.expired ? 'Invitation expirée' : 'Invitation en attente'}</td>
                <td>{invitation.email}</td>
                <td>{ROLE_LABELS[invitation.role]}</td>
                <td class="muted">{invitation.expired ? 'expirée' : `jusqu’au ${formatDate(invitation.expiresAt)}`}</td>
                <td class="table__actions">
                  <button class="btn btn--small" type="button" onclick={() => resend(invitation)}>Renvoyer</button>
                  <button class="btn btn--small" type="button" onclick={() => cancel(invitation)}>Annuler</button>
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {:else if !error}
      <p class="loading">Chargement…</p>
    {/if}
  </section>

  <form class="panel" onsubmit={invite} novalidate aria-labelledby="invite-title">
    <h2 class="panel__title" id="invite-title">Inviter quelqu’un</h2>
    <p class="panel__text">La personne reçoit un e-mail avec un lien, valable 7 jours, pour créer son compte. Vous restez le seul administrateur.</p>
    <div class="form-grid">
      <div class="field">
        <label class="field__label" for="invite-email">E-mail</label>
        <input class="input" id="invite-email" type="email" autocomplete="off" autocapitalize="none" spellcheck="false" bind:value={email} />
      </div>
      <fieldset class="field roles">
        <legend class="field__label">Rôle</legend>
        {#each TEAM_ROLES as r (r)}
          <label class="check">
            <input type="radio" name="invite-role" value={r} bind:group={role} />
            <span><strong>{ROLE_LABELS[r]}</strong> <span class="muted">— {ROLE_HINTS[r]}</span></span>
          </label>
        {/each}
      </fieldset>
    </div>
    {#if inviteError}<p class="form-error" role="alert">{inviteError}</p>{/if}
    <div class="form-actions">
      <button class="btn btn--primary" type="submit" disabled={inviteBusy || !email.trim()}>{inviteBusy ? 'Envoi…' : 'Envoyer l’invitation'}</button>
    </div>
    {#if sentLink}
      <div class="box" role="status">
        <p>
          {sentLink.sent ? `Invitation envoyée à ${sentLink.email}.` : `L’e-mail n’a pas pu partir : transmettez ce lien à ${sentLink.email}.`}
          Lien, valable 7 jours, une seule fois :
        </p>
        <div class="link-row">
          <input class="input input--mono" readonly value={sentLink.link} aria-label="Lien d’invitation" onfocus={(e) => e.currentTarget.select()} />
          <button class="btn btn--small" type="button" onclick={copyLink}>{copied ? 'Copié' : 'Copier'}</button>
        </div>
      </div>
    {/if}
  </form>

  {#if candidates.length > 0}
    <form class="panel" onsubmit={transfer} novalidate aria-labelledby="transfer-title">
      <h2 class="panel__title" id="transfer-title">Transférer le rôle d’administrateur</h2>
      <p class="panel__text">
        Une organisation n’a qu’un administrateur. La personne choisie le devient, et vous devenez éditeur : vous ne pourrez plus gérer l’équipe ni les réglages.
      </p>
      <div class="form-grid">
        <div class="field">
          <label class="field__label" for="transfer-to">Nouvel administrateur</label>
          <select class="select select--field" id="transfer-to" bind:value={transferTo}>
            <option value="" disabled>Choisir un membre…</option>
            {#each candidates as member (member.id)}<option value={String(member.id)}>{member.name} — {member.email}</option>{/each}
          </select>
        </div>
        <div class="field">
          <label class="field__label" for="transfer-password">Votre mot de passe</label>
          <input class="input" id="transfer-password" type="password" autocomplete="current-password" bind:value={transferPassword} />
        </div>
      </div>
      {#if transferError}<p class="form-error" role="alert">{transferError}</p>{/if}
      <div class="form-actions">
        <button class="btn btn--primary" type="submit" disabled={transferBusy || !transferTo || !transferPassword}>Transférer le rôle</button>
      </div>
    </form>
  {/if}

  <section class="panel" aria-labelledby="security-title">
    <h2 class="panel__title" id="security-title">Sécurité de l’équipe</h2>
    <label class="check">
      <input type="checkbox" checked={current.session?.organization?.totpRequired === true} disabled={securityBusy} onchange={(e) => setTotpRequired(e.currentTarget.checked)} />
      <span><strong>Double authentification obligatoire</strong> <span class="muted">— chaque membre doit l’activer à sa prochaine action, avant de continuer</span></span>
    </label>
    {#if securityError}<p class="form-error" role="alert">{securityError}</p>{/if}
  </section>

  <form class="panel" onsubmit={renameOrganization} novalidate aria-labelledby="org-title">
    <h2 class="panel__title" id="org-title">Organisation</h2>
    <div class="field">
      <label class="field__label" for="org-name">Nom de l’organisation</label>
      <input class="input" id="org-name" autocomplete="organization" bind:value={orgName} />
      <p class="field__hint">Il signe vos réponses aux révisions des clients.</p>
    </div>
    {#if orgError}<p class="form-error" role="alert">{orgError}</p>{/if}
    <div class="form-actions">
      <button class="btn btn--primary" type="submit" disabled={orgBusy || orgName.trim().length < 2}>Enregistrer</button>
      {#if orgDone}<span class="saved" role="status">Nom enregistré</span>{/if}
    </div>
  </form>
</div>

<section class="panel brand" aria-labelledby="brand-title">
  <h2 class="panel__title" id="brand-title">Identité visuelle</h2>
  <p class="panel__text">Les couleurs et le logo de votre administration, pour toute l’équipe. Les nouvelles collections les reprennent par défaut.</p>
  <div class="brand__grid">
    <div class="brand__logo">
      <p class="field__label">Logo</p>
      <div class="logo-box">
        {#if current.session?.organization?.logoUrl}
          <img src={current.session.organization.logoUrl} alt="Logo de {current.session.organization.name}" />
        {:else}
          <span class="muted">Aucun logo : le menu affiche « belprise. »</span>
        {/if}
      </div>
      <p class="field__hint">SVG, PNG ou WebP, 2 Mo au plus. Affiché en haut du menu.</p>
      {#if logoError}<p class="form-error" role="alert">{logoError}</p>{/if}
      <div class="form-actions">
        <label class="btn btn--small file-button" class:file-button--busy={logoBusy}>
          {current.session?.organization?.logoUrl ? 'Remplacer' : 'Choisir un fichier'}
          <input class="visually-hidden" type="file" accept=".svg,.png,.webp,image/svg+xml,image/png,image/webp" disabled={logoBusy} onchange={uploadLogo} />
        </label>
        {#if current.session?.organization?.logoUrl}
          <button class="btn btn--small" type="button" disabled={logoBusy} onclick={removeLogo}>Retirer</button>
        {/if}
      </div>
    </div>

    <form class="brand__colors" onsubmit={saveBrand} novalidate>
      <fieldset class="brand__theme">
        <legend class="field__label">Fond de l’interface</legend>
        <label class="check"><input type="radio" name="theme" value="dark" bind:group={theme} /> Sombre</label>
        <label class="check"><input type="radio" name="theme" value="light" bind:group={theme} /> Clair</label>
      </fieldset>
      <ColorField label="Accent" bind:value={accent} />

      <div class="preview" style:background={BACKGROUNDS[theme]} style:color={theme === 'light' ? '#141416' : '#fafafa'} aria-hidden="true">
        <span class="preview__item" style:background={theme === 'light' ? '#e9e9ec' : '#18181b'}>Vidéos</span>
        <span class="preview__item">Collections</span>
        <span class="preview__button" style:background={accent} style:color={onAccent}>Nouvelle vidéo</span>
        <span class="preview__ring" style:border-color={accent}>Sélection</span>
      </div>
      <p class="contrast" class:contrast--low={accentRatio < MIN_ACCENT}>
        {#if accentRatio < MIN_ACCENT}
          Accent trop proche du fond : {formatRatio(accentRatio)}, il faut au moins 3:1 pour voir les boutons, le focus et les sélections.
        {:else}
          Accent / fond : {formatRatio(accentRatio)} (au moins 3:1). Le texte des boutons ({onAccent === '#ffffff' ? 'blanc' : 'noir'}) et l’accent en texte sont ajustés pour rester lisibles.
        {/if}
      </p>
      {#if brandError}<p class="form-error" role="alert">{brandError}</p>{/if}
      <div class="form-actions">
        <button class="btn btn--primary" type="submit" disabled={brandBusy || accentRatio < MIN_ACCENT}>Appliquer</button>
        {#if brandDone}<span class="saved" role="status">Identité enregistrée</span>{/if}
      </div>
    </form>
  </div>
</section>

<section class="panel danger" aria-labelledby="delete-org-title">
  <h2 class="panel__title" id="delete-org-title">Supprimer l’organisation</h2>
  {#if lifecycle?.deletionAt}
    <p class="panel__text">
      <strong>Suppression programmée le {formatLongDate(lifecycle.deletionAt)}</strong>,
      demandée par {lifecycle.deletionBy === 'operator' ? 'l’exploitant de Belprise' : lifecycle.deletionRequestedBy}.
      Ce jour-là, comptes, clients, projets, vidéos, masters, révisions, collections et liens seront effacés définitivement.
    </p>
    {#if deleteError}<p class="form-error" role="alert">{deleteError}</p>{/if}
    {#if lifecycle.deletionBy === 'operator'}
      <p class="panel__text">Seul l’exploitant de Belprise peut annuler cette demande.</p>
    {:else}
      <div class="form-actions">
        <button class="btn btn--primary" type="button" disabled={deleteBusy} onclick={cancelDeletion}>Annuler la suppression</button>
      </div>
    {/if}
  {:else}
    <p class="panel__text">
      Efface l’organisation et toutes ses données (comptes, clients, vidéos, masters, révisions, collections, liens).
      La suppression a lieu <strong>30 jours</strong> après la demande : l’administrateur est prévenu par e-mail, puis à J-7 et J-1,
      et la demande reste annulable d’ici là. Pendant ce délai, les nouveaux envois sont bloqués et les liens partagés restent actifs.
    </p>
    <form class="delete-form" onsubmit={requestDeletion} novalidate>
      <div class="field">
        <label class="field__label" for="delete-org-name">Nom de l’organisation, pour confirmer</label>
        <input class="input" id="delete-org-name" autocomplete="off" aria-invalid={deleteField === 'confirm'} bind:value={deleteName} placeholder={current.session?.organization?.name ?? ''} />
      </div>
      <div class="field">
        <label class="field__label" for="delete-org-password">Votre mot de passe</label>
        <input class="input" id="delete-org-password" type="password" autocomplete="current-password" aria-invalid={deleteField === 'current'} bind:value={deletePassword} />
      </div>
      {#if deleteError}<p class="form-error" role="alert">{deleteError}</p>{/if}
      <div class="form-actions">
        <button
          class="btn btn--danger"
          type="submit"
          disabled={deleteBusy || deleteName.trim().toLowerCase() !== (current.session?.organization?.name ?? '').toLowerCase() || deletePassword === ''}
        >Programmer la suppression</button>
      </div>
    </form>
  {/if}
</section>

{#if journal.length > 0}
  <section class="panel journal" aria-labelledby="journal-title">
    <h2 class="panel__title" id="journal-title">Journal de l’organisation</h2>
    <p class="panel__text">Actions de l’exploitant de Belprise, archivages, purges et demandes de suppression. L’exploitant n’a jamais accès à vos contenus.</p>
    <ul>
      {#each journal as entry, i (i)}
        <li><span class="muted">{formatDate(entry.at)}</span> — <strong>{entry.action}</strong>{#if entry.details}{' · '}{entry.details}{/if}</li>
      {/each}
    </ul>
  </section>
{/if}

<ConfirmDialog
  bind:open={confirmReset}
  title={resetting?.kind === 'totp' ? `Réinitialiser la double authentification de ${resetting?.member.name} ?` : `Réinitialiser le mot de passe de ${resetting?.member.name} ?`}
  confirmLabel="Envoyer le lien"
  onConfirm={sendReset}
>
  <p>
    {resetting?.member.name} reçoit à <strong>{resetting?.member.email}</strong> un lien valable 1 heure, à usage unique,
    {resetting?.kind === 'totp' ? 'pour désactiver sa double authentification (son mot de passe lui sera demandé).' : 'pour choisir un nouveau mot de passe.'}
    Ses sessions ouvertes se fermeront. Vous ne verrez pas ce lien.
  </p>
</ConfirmDialog>

<ConfirmDialog bind:open={confirmRemove} title="Retirer {removing?.name} ?" confirmLabel="Retirer de l’équipe" danger onConfirm={remove}>
  <p>{removing?.name} ne pourra plus se connecter ; son compte est supprimé. Ses réponses aux révisions restent.</p>
</ConfirmDialog>

<style>
  .brand {
    margin-top: 20px;
  }

  .brand__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
    gap: 24px;
  }

  .brand__logo,
  .brand__colors {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .brand__theme {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 20px;
    margin: 0;
    padding: 0;
    border: none;
  }

  .brand__theme legend {
    width: 100%;
    margin-bottom: 6px;
  }

  .logo-box {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 96px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface-2);
    font-size: 13px;
  }

  .logo-box img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
  }

  .file-button {
    cursor: pointer;
  }

  .file-button:focus-within {
    box-shadow: var(--focus-ring);
  }

  .file-button--busy {
    opacity: 0.55;
  }

  .preview {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-size: 13px;
  }

  .preview__item {
    padding: 6px 12px;
    border-radius: var(--radius-sm);
  }

  .preview__button {
    padding: 8px 16px;
    border-radius: var(--radius-pill);
    font-weight: 600;
  }

  .preview__ring {
    padding: 5px 12px;
    border: 2px solid;
    border-radius: var(--radius-sm);
  }

  .contrast {
    font-size: 13px;
    color: var(--text-muted);
  }

  .contrast--low {
    padding: 10px 14px;
    border-radius: var(--radius-md);
    background: rgb(255 214 10 / 0.12);
    color: color-mix(in srgb, var(--status-changes) var(--status-ink, 100%), var(--text));
  }

  .danger {
    margin-top: 20px;
    border-color: color-mix(in srgb, var(--status-refused) 40%, var(--border));
  }

  .delete-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));
    gap: 14px;
    align-items: end;
  }

  .delete-form .form-error,
  .delete-form .form-actions {
    grid-column: 1 / -1;
  }

  .journal {
    margin-top: 20px;
  }

  .journal ul {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 12px 0 0;
    padding: 0;
    list-style: none;
    font-size: 13px;
  }

  .panel--wide {
    grid-column: 1 / -1;
  }

  .pending td:first-child {
    font-style: italic;
  }

  .table__main-cell {
    font-weight: 500;
  }

  .table__main-cell .pill {
    margin-left: 8px;
  }

  .table__actions {
    white-space: nowrap;
    text-align: right;
  }

  .table__actions .btn + .btn {
    margin-left: 6px;
  }

  .roles {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin: 0;
    padding: 0;
    border: none;
  }

  .roles .check {
    align-items: flex-start;
    min-height: 36px;
  }

  .box {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 16px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface-2);
    font-size: 13px;
  }

  .link-row {
    display: flex;
    gap: 8px;
  }

  .link-row .input {
    flex: 1;
    min-width: 0;
    font-size: 12px;
  }
</style>
