<script lang="ts">
  import { untrack } from 'svelte';
  import { api, ApiError } from '../../lib/api';
  import { formatBytes, formatDate, formatLongDate } from '../../lib/format';
  import AuthScreen from '../AuthScreen.svelte';
  import ConfirmDialog from '../components/ConfirmDialog.svelte';
  import type { StorageUsage } from '../session';

  /** Operator of the installation (§5.11): organizations and their quota, never their content. */
  interface SuperSession {
    authenticated: boolean;
    name: string | null;
    codeRequired: boolean;
    csrf: string;
  }

  interface Organization {
    id: number;
    name: string;
    /** Bytes; null: no limit */
    quota: number | null;
    users: number;
    pendingInvitations: number;
    createdAt: string;
    lastLoginAt: string | null;
    storage: StorageUsage;
    /** Downgrade awaiting its purge */
    purgeAt: string | null;
    downgradedFrom: string | null;
    deletionAt: string | null;
    deletionBy: 'admin' | 'operator' | null;
    admins: { id: number; name: string; email: string; totp: boolean }[];
  }

  interface Overview {
    organizations: Organization[];
    disk: { free: number | null; total: number | null };
    encoding: Encoding;
    /** Installed version and the latest one published on belprise.fr (D8) */
    version: { installed: string; latest: string | null; newer: boolean; url: string | null; checking: boolean };
    log: { organization: string; actor: string; action: string; details: string; at: string }[];
  }

  /** Where the playback versions are encoded (§5.7 bis). */
  interface Encoding {
    mode: 'browser' | 'server';
    threads: number;
    ffmpeg: { version: string; path: string; probe: boolean } | null;
    speed: { ratio: number; threads: number; at: string; minutesPerHour: number } | null;
    queue: { queued: number; running: number; failed: number };
  }

  let encodingError = $state('');
  let encodingBusy = $state(false);

  async function setEncoding(changes: { mode?: 'browser' | 'server'; threads?: number }): Promise<void> {
    if (!overview) return;
    encodingBusy = true;
    encodingError = '';
    try {
      overview.encoding = await api<Encoding>('PATCH', '/api/superadmin/encoding', changes);
    } catch (e) {
      encodingError = e instanceof ApiError ? e.message : 'Enregistrement impossible.';
    } finally {
      encodingBusy = false;
    }
  }

  let { initial }: { initial: SuperSession } = $props();
  let session = $state<SuperSession>(untrack(() => initial));

  // Sign-in
  let email = $state('');
  let password = $state('');
  let code = $state('');
  let error = $state('');
  let busy = $state(false);

  // Organizations
  let overview = $state<Overview | null>(null);
  let listError = $state('');
  let newName = $state('');
  let newEmail = $state('');
  /** Gigabytes; empty: no limit */
  let newQuota = $state('');
  let createError = $state('');
  let created = $state<{ link: string; sent: boolean } | null>(null);

  $effect(() => {
    if (session.authenticated) void load();
  });

  async function load(): Promise<void> {
    try {
      overview = await api<Overview>('GET', '/api/superadmin/organizations');
      listError = '';
    } catch (e) {
      listError = e instanceof ApiError ? e.message : 'Chargement impossible.';
      if (e instanceof ApiError && e.status === 401) session = { ...session, authenticated: false };
    }
  }

  async function run(work: () => Promise<SuperSession>): Promise<void> {
    if (busy) return;
    busy = true;
    error = '';
    try {
      session = await work();
      password = '';
      code = '';
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
      if (e instanceof ApiError && e.data.restart) session = { ...session, codeRequired: false };
      code = '';
    } finally {
      busy = false;
    }
  }

  const signIn = (event: SubmitEvent): Promise<void> => (event.preventDefault(), run(() => api('POST', '/api/superadmin/login', { email, password })));
  const sendCode = (event: SubmitEvent): Promise<void> => (event.preventDefault(), run(() => api('POST', '/api/superadmin/login/totp', { code })));
  const signOut = (): Promise<void> => run(() => api('POST', '/api/superadmin/logout'));

  // A quota the storage no longer fits starts the downgrade: confirmed first.
  let pending = $state<{ org: Organization; quota: number } | null>(null);
  let confirmDowngrade = $state(false);
  let deleting = $state<Organization | null>(null);
  let confirmDeletion = $state(false);

  const GB = 1_000_000_000;

  /** "250" → 250; "" → null (no limit); anything else → undefined. */
  function parseGigabytes(value: string): number | null | undefined {
    const text = value.trim();
    if (text === '') return null;
    const n = Number(text.replace(',', '.'));
    return Number.isInteger(n) && n >= 1 && n <= 1_000_000 ? n : undefined;
  }

  function chooseQuota(org: Organization, value: string): void {
    const gigabytes = parseGigabytes(value);
    if (gigabytes === undefined) {
      listError = 'Quota : un nombre entier de Go (1 au moins), ou vide pour « sans limite ».';
      return;
    }
    const bytes = gigabytes === null ? null : gigabytes * GB;
    if (bytes === org.quota) return;
    if (bytes !== null && org.purgeAt === null && org.storage.used + org.storage.reserved > bytes) {
      pending = { org, quota: bytes };
      confirmDowngrade = true;
      return;
    }
    void setQuota(org, gigabytes);
  }

  $effect(() => {
    // Downgrade dialog closed without confirming: the list shows the real quota again.
    if (!confirmDowngrade && pending) {
      pending = null;
      void load();
    }
  });

  async function downgrade(): Promise<void> {
    if (!pending) return;
    await api('PATCH', `/api/superadmin/organizations/${pending.org.id}`, { quotaGb: pending.quota / GB });
  }

  async function requestDeletion(typed: string): Promise<void> {
    if (!deleting) return;
    await api('POST', `/api/superadmin/organizations/${deleting.id}/deletion`, { confirm: typed });
    await load();
  }

  // Reset of an organization's administrator (§5.10): the link goes to the administrator.
  let resetting = $state<{ org: Organization; admin: Organization['admins'][number]; kind: 'password' | 'totp' } | null>(null);
  let confirmReset = $state(false);
  let resetDone = $state('');

  async function sendReset(): Promise<void> {
    if (!resetting) return;
    const result = await api<{ sent: boolean; email: string }>('POST', `/api/superadmin/organizations/${resetting.org.id}/users/${resetting.admin.id}/reset`, { kind: resetting.kind });
    resetDone = result.sent ? `Lien envoyé à ${result.email} (valable 1 heure).` : `L’e-mail à ${result.email} n’a pas pu partir.`;
    await load();
  }

  async function cancelDeletion(org: Organization): Promise<void> {
    listError = '';
    try {
      await api('DELETE', `/api/superadmin/organizations/${org.id}/deletion`);
      await load();
    } catch (e) {
      listError = e instanceof ApiError ? e.message : 'Annulation impossible.';
    }
  }

  async function setQuota(org: Organization, quotaGb: number | null): Promise<void> {
    listError = '';
    try {
      await api('PATCH', `/api/superadmin/organizations/${org.id}`, { quotaGb });
      await load();
    } catch (e) {
      listError = e instanceof ApiError ? e.message : 'Changement impossible.';
    }
  }

  async function create(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    createError = '';
    created = null;
    const quotaGb = parseGigabytes(newQuota);
    if (quotaGb === undefined) {
      createError = 'Quota : un nombre entier de Go (1 au moins), ou vide pour « sans limite ».';
      return;
    }
    try {
      created = await api('POST', '/api/superadmin/organizations', { name: newName, email: newEmail, quotaGb });
      newName = newEmail = newQuota = '';
      await load();
    } catch (e) {
      createError = e instanceof ApiError ? e.message : 'Création impossible.';
    }
  }

  const percent = (s: StorageUsage): number => Math.round(Math.min(1, s.ratio) * 100);
</script>

{#if !session.authenticated}
  <AuthScreen eyebrow="Exploitation" title={session.codeRequired ? 'Code de vérification' : 'Connexion'}>
    {#if session.codeRequired}
      <form onsubmit={sendCode} novalidate>
        <div class="field">
          <label class="field__label" for="super-code">Code à 6 chiffres de votre application d’authentification</label>
          <!-- svelte-ignore a11y_autofocus -->
          <input id="super-code" class="input input--mono" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus bind:value={code} />
        </div>
        {#if error}<p class="form-error" role="alert">{error}</p>{/if}
        <button class="btn btn--primary btn--block" type="submit" disabled={busy || code.replace(/\s/g, '').length !== 6}>Valider</button>
      </form>
    {:else}
      <form onsubmit={signIn} novalidate>
        <div class="field">
          <label class="field__label" for="super-email">E-mail</label>
          <!-- svelte-ignore a11y_autofocus -->
          <input id="super-email" class="input" inputmode="email" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus bind:value={email} />
        </div>
        <div class="field">
          <label class="field__label" for="super-password">Mot de passe</label>
          <input id="super-password" class="input" type="password" autocomplete="current-password" bind:value={password} />
        </div>
        {#if error}<p class="form-error" role="alert">{error}</p>{/if}
        <button class="btn btn--primary btn--block" type="submit" disabled={busy}>Se connecter</button>
      </form>
    {/if}
  </AuthScreen>
{:else}
  <div class="super">
    <header class="super__head">
      <p class="wordmark">belprise<span class="wordmark__dot">.</span> <span class="eyebrow">// exploitation</span></p>
      <span class="grow"></span>
      {#if overview}<span class="muted mono" title={overview.version.checking ? 'Nouvelles versions vérifiées chaque jour sur belprise.fr' : 'Vérification des nouvelles versions désactivée'}>v{overview.version.installed}</span>{/if}
      <span class="muted">{session.name}</span>
      <button class="btn btn--small" type="button" onclick={signOut}>Se déconnecter</button>
    </header>

    <main class="super__main">
      <h1 class="display">Organisations</h1>
      <p class="muted lead">Quotas et stockage. Les contenus des organisations ne sont jamais accessibles d’ici.</p>
      {#if overview?.version.newer}
        <p class="created update" role="status">
          <strong>belprise {overview.version.latest} est disponible</strong> (installée : {overview.version.installed}).
          {#if overview.version.url}<a href={overview.version.url} rel="noopener noreferrer">Nouveautés et téléchargement</a>.{/if}
          Mise à jour : <span class="mono">php app/bin/update.php belprise-{overview.version.latest}.zip</span>, ou nouvelle image Docker.
        </p>
      {/if}
      {#if listError}<p class="form-error" role="alert">{listError}</p>{/if}
      {#if resetDone}<p class="created" role="status">{resetDone}</p>{/if}

      {#if overview}
        {#if overview.disk.total}
          <p class="muted">Disque du serveur : {formatBytes(overview.disk.free ?? 0)} libres sur {formatBytes(overview.disk.total)}.</p>
        {/if}
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr><th>Organisation</th><th>Quota</th><th>Stockage</th><th>Utilisateurs</th><th>Créée</th><th>Dernière connexion</th><th><span class="visually-hidden">Actions</span></th></tr>
            </thead>
            <tbody>
              {#each overview.organizations as org (org.id)}
                <tr>
                  <td>
                    <span class="strong">{org.name}</span>
                    {#if org.deletionAt}
                      <span class="state state--danger">Suppression le {formatLongDate(org.deletionAt)}{org.deletionBy === 'admin' ? ' (demandée par l’organisation)' : ''}</span>
                    {/if}
                    {#if org.purgeAt}
                      <span class="state">Quota abaissé{org.downgradedFrom ? ` (était ${org.downgradedFrom})` : ''} · purge le {formatLongDate(org.purgeAt)}</span>
                    {/if}
                    {#each org.admins as admin (admin.id)}
                      <span class="admin">
                        {admin.name} · {admin.email}
                        <button class="link-button" type="button" onclick={() => ((resetting = { org, admin, kind: 'password' }), (resetDone = ''), (confirmReset = true))}>mot de passe</button>
                        {#if admin.totp}<button class="link-button" type="button" onclick={() => ((resetting = { org, admin, kind: 'totp' }), (resetDone = ''), (confirmReset = true))}>double auth.</button>{/if}
                      </span>
                    {/each}
                  </td>
                  <td>
                    <form class="quota" onsubmit={(e) => (e.preventDefault(), chooseQuota(org, new FormData(e.currentTarget).get('gb')?.toString() ?? ''))}>
                      <label class="visually-hidden" for="quota-{org.id}">Quota de {org.name}, en Go (vide : sans limite)</label>
                      <input class="input quota__input" id="quota-{org.id}" name="gb" inputmode="numeric" placeholder="Sans limite" value={org.quota === null ? '' : String(org.quota / GB)} />
                      <span class="muted">Go</span>
                      <button class="btn btn--small" type="submit">OK</button>
                    </form>
                  </td>
                  <td>
                    <span class:over={org.storage.ratio >= 1} class:warn={org.storage.ratio >= 0.8 && org.storage.ratio < 1}>
                      {formatBytes(org.storage.used + org.storage.reserved)}{org.storage.quota === null ? '' : ` · ${percent(org.storage)} %`}
                    </span>
                  </td>
                  <td>{org.users}{#if org.pendingInvitations}{' '}<span class="muted">+ {org.pendingInvitations} invit.</span>{/if}</td>
                  <td class="muted">{formatDate(org.createdAt)}</td>
                  <td class="muted">{org.lastLoginAt ? formatDate(org.lastLoginAt) : 'jamais'}</td>
                  <td>
                    {#if org.deletionAt}
                      <button class="btn btn--small" type="button" onclick={() => cancelDeletion(org)}>Annuler la suppression</button>
                    {:else}
                      <button class="btn btn--small" type="button" onclick={() => ((deleting = org), (confirmDeletion = true))}>Supprimer…</button>
                    {/if}
                  </td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>

        <form class="panel create" onsubmit={create} novalidate aria-labelledby="create-title">
          <h2 class="panel__title" id="create-title">Nouvelle organisation</h2>
          <p class="panel__text">Son administrateur reçoit une invitation par e-mail. Une organisation n’a qu’un administrateur ; il invite ensuite son équipe.</p>
          <div class="create__grid">
            <div class="field">
              <label class="field__label" for="new-name">Nom de l’organisation</label>
              <input class="input" id="new-name" placeholder="Studio, société, agence…" aria-describedby="new-name-hint" bind:value={newName} />
              <p class="field__hint" id="new-name-hint">La structure, pas la personne : son administrateur choisira son propre nom en ouvrant l’invitation.</p>
            </div>
            <div class="field">
              <label class="field__label" for="new-email">E-mail de l’administrateur</label>
              <input class="input" id="new-email" type="email" autocapitalize="none" spellcheck="false" bind:value={newEmail} />
            </div>
            <div class="field">
              <label class="field__label" for="new-quota">Quota en Go</label>
              <input class="input" id="new-quota" inputmode="numeric" placeholder="Sans limite" aria-describedby="new-quota-hint" bind:value={newQuota} />
              <p class="field__hint" id="new-quota-hint">Vide : sans limite (seul l’espace du serveur compte).</p>
            </div>
          </div>
          {#if createError}<p class="form-error" role="alert">{createError}</p>{/if}
          <div class="form-actions">
            <button class="btn btn--primary" type="submit" disabled={!newName.trim() || !newEmail.trim()}>Créer et inviter</button>
          </div>
          {#if created}
            <p class="created" role="status">
              {created.sent ? 'Invitation envoyée.' : 'L’e-mail n’a pas pu partir : transmettez ce lien.'}
              <span class="mono">{created.link}</span>
            </p>
          {/if}
        </form>
        <section class="panel" aria-labelledby="encoding-title">
          <h2 class="panel__title" id="encoding-title">Préparation des versions de lecture</h2>
          <p class="panel__text">
            La version 720p que lisent les clients. Dans le navigateur, l’administrateur garde la page ouverte après l’envoi ; sur le serveur,
            elle est encodée en arrière-plan par la tâche planifiée, une vidéo à la fois.
          </p>
          <fieldset class="field encoding">
            <legend class="field__label">Encodage</legend>
            <label class="check">
              <input type="radio" name="encoding-mode" value="browser" checked={overview.encoding.mode === 'browser'} disabled={encodingBusy} onchange={() => setEncoding({ mode: 'browser' })} />
              <span><strong>Dans le navigateur</strong> <span class="muted">— aucun calcul sur le serveur</span></span>
            </label>
            <label class="check">
              <input type="radio" name="encoding-mode" value="server" checked={overview.encoding.mode === 'server'} disabled={encodingBusy || !overview.encoding.ffmpeg} onchange={() => setEncoding({ mode: 'server' })} />
              <span>
                <strong>Sur le serveur</strong>
                <span class="muted">— {overview.encoding.ffmpeg ? `${overview.encoding.ffmpeg.version}, ${overview.encoding.ffmpeg.path}` : 'FFmpeg est introuvable sur ce serveur'}</span>
              </span>
            </label>
          </fieldset>
          {#if overview.encoding.mode === 'server'}
            <div class="field">
              <label class="field__label" for="encoding-threads">Processeurs utilisés (fils)</label>
              <select class="select select--field encoding__threads" id="encoding-threads" value={String(overview.encoding.threads)} disabled={encodingBusy}
                onchange={(e) => setEncoding({ threads: Number(e.currentTarget.value) })}>
                {#each [1, 2, 4, 8] as n (n)}<option value={String(n)}>{n}</option>{/each}
              </select>
              <p class="field__hint">Sur un hébergement mutualisé, gardez 1 : au-delà, l’hébergeur coupe souvent l’encodage.</p>
            </div>
            <p class="panel__text">
              {#if overview.encoding.speed}
                Dernier encodage : {overview.encoding.speed.ratio.toLocaleString('fr-FR')} × le temps réel avec {overview.encoding.speed.threads} fil(s),
                soit environ <strong>{overview.encoding.speed.minutesPerHour} min pour une heure de vidéo</strong>.
              {:else}
                Vitesse pas encore mesurée : elle s’affiche après le premier encodage, ou avec <span class="mono">php app/bin/encode-test.php</span> sur le serveur.
              {/if}
              File d’attente : {overview.encoding.queue.queued} en attente, {overview.encoding.queue.running} en cours{overview.encoding.queue.failed ? `, ${overview.encoding.queue.failed} en échec` : ''}.
            </p>
          {/if}
          {#if encodingError}<p class="form-error" role="alert">{encodingError}</p>{/if}
        </section>

        {#if overview.log.length > 0}
          <section class="panel" aria-labelledby="log-title">
            <h2 class="panel__title" id="log-title">Journal de la plateforme</h2>
            <p class="panel__text">Créations, quotas, purges et suppressions. Une organisation supprimée n’y laisse que son nom.</p>
            <ul class="log">
              {#each overview.log as entry, i (i)}
                <li><span class="muted">{formatDate(entry.at)}</span> — <strong>{entry.organization}</strong> · {entry.action}{#if entry.details}{' · '}{entry.details}{/if} <span class="muted">({entry.actor})</span></li>
              {/each}
            </ul>
          </section>
        {/if}
      {:else if !listError}
        <p class="loading">Chargement…</p>
      {/if}
    </main>
  </div>

  {#if pending}
    <ConfirmDialog bind:open={confirmDowngrade} title="Abaisser le quota de {pending.org.name} ?" confirmLabel="Abaisser et prévenir" danger onConfirm={downgrade}>
      <p>
        {pending.org.name} utilise {formatBytes(pending.org.storage.used + pending.org.storage.reserved)}, plus que le nouveau quota
        ({formatBytes(pending.quota)}). Son administrateur est prévenu par e-mail tout de suite, puis à J-7 et J-1 ;
        les nouveaux envois sont bloqués.
      </p>
      <p>
        Dans 30 jours, si le stockage dépasse encore le quota, des vidéos entières seront supprimées :
        {formatBytes(pending.org.storage.used + pending.org.storage.reserved - pending.quota)} au moins. Un quota suffisant annule la purge.
      </p>
    </ConfirmDialog>
  {/if}

  {#if resetting}
    <ConfirmDialog bind:open={confirmReset} title="Réinitialiser {resetting.kind === 'totp' ? 'la double authentification' : 'le mot de passe'} de {resetting.admin.name} ?" confirmLabel="Envoyer le lien" onConfirm={sendReset}>
      <p>
        Le lien (1 heure, une seule fois) part à <strong>{resetting.admin.email}</strong>, l’administrateur de {resetting.org.name} :
        vous ne le voyez pas, et la demande est notée dans le journal de l’organisation.
      </p>
    </ConfirmDialog>
  {/if}

  {#if deleting}
    <ConfirmDialog bind:open={confirmDeletion} title="Supprimer {deleting.name} ?" confirmLabel="Programmer la suppression" danger requireText={deleting.name} onConfirm={requestDeletion}>
      <p>
        La suppression ne s’exécute que <strong>30 jours</strong> après la demande. L’administrateur de {deleting.name} est prévenu par e-mail
        tout de suite, puis à J-7 et J-1 ; l’équipe garde l’accès pour récupérer ses masters. Seul l’exploitant peut annuler cette demande.
      </p>
    </ConfirmDialog>
  {/if}
{/if}

<style>
  .super {
    min-height: 100vh;
    background: var(--bg);
  }

  .super__head {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 18px clamp(20px, 3vw, 48px);
    border-bottom: 1px solid var(--border);
  }

  .super__head .eyebrow {
    margin-left: 8px;
  }

  .grow {
    flex: 1;
  }

  .super__main {
    display: flex;
    flex-direction: column;
    gap: 16px;
    max-width: 1280px;
    margin: 0 auto;
    padding: 32px clamp(20px, 3vw, 48px) 48px;
  }

  .lead {
    margin-top: -8px;
  }

  .strong {
    font-weight: 600;
  }

  .warn {
    color: var(--status-changes);
  }

  .over {
    color: var(--status-refused);
    font-weight: 600;
  }

  .create__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
  }

  .admin {
    display: block;
    margin-top: 4px;
    color: var(--text-muted);
    font-size: 12.5px;
  }

  .link-button {
    margin-left: 6px;
    padding: 0;
    border: none;
    background: none;
    color: var(--accent-text);
    font: inherit;
    text-decoration: underline;
    cursor: pointer;
  }

  .state {
    display: block;
    margin-top: 4px;
    font-size: 12.5px;
    color: var(--status-changes);
  }

  .state--danger {
    color: var(--status-refused);
  }

  .log {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 12px 0 0;
    padding: 0;
    list-style: none;
    font-size: 13px;
  }

  .encoding {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 12px 0;
    padding: 0;
    border: none;
  }

  .encoding__threads {
    max-width: 120px;
  }

  .update {
    padding: 12px 16px;
    border: 1px solid var(--accent);
    border-radius: var(--radius-md);
  }

  .quota {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .quota__input {
    width: 96px;
  }

  .created {
    margin-top: 12px;
    font-size: 13px;
    overflow-wrap: anywhere;
  }
</style>
