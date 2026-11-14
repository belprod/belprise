<script lang="ts">
  import { api, ApiError, upload } from '../../lib/api';
  import { AA_TEXT, contrastRatio, formatRatio } from '../../lib/contrast';
  import { formatDate } from '../../lib/format';
  import { followLink, navigate } from '../../lib/router.svelte';
  import ColorField from '../components/ColorField.svelte';
  import ConfirmDialog from '../components/ConfirmDialog.svelte';
  import LinkPanel from '../components/LinkPanel.svelte';
  import PageHead from '../components/PageHead.svelte';
  import type { Client, Project } from '../types';

  /** null: creation page */
  let { id }: { id: number | null } = $props();

  const DEFAULTS = { bgColor: '#0a0a0b', textColor: '#fafafa', accentColor: '#e8515f' };

  let client = $state<Client | null>(null);
  let projects = $state<Project[]>([]);
  let loadError = $state('');

  let name = $state('');
  let welcomeText = $state('');
  let bgColor = $state(DEFAULTS.bgColor);
  let textColor = $state(DEFAULTS.textColor);
  let accentColor = $state(DEFAULTS.accentColor);
  let saving = $state(false);
  let saved = $state(false);
  let formError = $state('');
  let invalidField = $state('');

  let logoBusy = $state(false);
  let logoError = $state('');
  let confirmDelete = $state(false);

  const ratio = $derived(contrastRatio(textColor, bgColor));

  $effect(() => {
    if (id === null) return;
    const clientId = id;
    Promise.all([
      api<Client>('GET', `/api/admin/clients/${clientId}`),
      api<Project[]>('GET', `/api/admin/projects?client=${clientId}&archived=1`),
    ])
      .then(([loaded, list]) => {
        fill(loaded);
        projects = list;
      })
      .catch((e) => (loadError = e instanceof ApiError && e.status === 404 ? 'Ce client n’existe pas.' : 'Chargement impossible.'));
  });

  function fill(loaded: Client): void {
    client = loaded;
    name = loaded.name;
    welcomeText = loaded.welcomeText;
    bgColor = loaded.bgColor;
    textColor = loaded.textColor;
    accentColor = loaded.accentColor;
  }

  async function save(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    saving = true;
    saved = false;
    formError = '';
    invalidField = '';
    const body = { name, welcomeText, bgColor, textColor, accentColor };
    try {
      if (client === null) {
        const created = await api<Client>('POST', '/api/admin/clients', body);
        navigate(`/admin/clients/${created.id}`, { replace: true });
      } else {
        fill(await api<Client>('PATCH', `/api/admin/clients/${client.id}`, body));
        saved = true;
      }
    } catch (e) {
      formError = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
      invalidField = e instanceof ApiError && typeof e.data.field === 'string' ? e.data.field : '';
    } finally {
      saving = false;
    }
  }

  async function uploadLogo(event: Event & { currentTarget: HTMLInputElement }): Promise<void> {
    const file = event.currentTarget.files?.[0];
    event.currentTarget.value = '';
    if (!file || client === null) return;
    logoBusy = true;
    logoError = '';
    const form = new FormData();
    form.append('logo', file);
    try {
      fill(await upload<Client>(`/api/admin/clients/${client.id}/logo`, form));
    } catch (e) {
      logoError = e instanceof ApiError ? e.message : 'Envoi impossible.';
    } finally {
      logoBusy = false;
    }
  }

  async function removeLogo(): Promise<void> {
    if (client === null) return;
    logoBusy = true;
    try {
      fill(await api<Client>('DELETE', `/api/admin/clients/${client.id}/logo`));
    } finally {
      logoBusy = false;
    }
  }

  async function setArchived(archived: boolean): Promise<void> {
    if (client === null) return;
    fill(await api<Client>('PATCH', `/api/admin/clients/${client.id}`, { archived }));
  }

  async function remove(typed: string): Promise<void> {
    if (client === null) return;
    await api('DELETE', `/api/admin/clients/${client.id}`, { confirm: typed });
    navigate('/admin/clients', { replace: true });
  }
</script>

{#if loadError}
  <PageHead eyebrow="Client" title="Introuvable" back={{ href: '/admin/clients', label: 'Clients' }} />
  <p class="form-error" role="alert">{loadError}</p>
{:else if id !== null && client === null}
  <p class="loading">Chargement…</p>
{:else}
  <PageHead
    eyebrow={client ? `Client${client.archived ? ' · archivé' : ''}` : 'Clients'}
    title={client ? client.name : 'Nouveau client'}
    back={{ href: '/admin/clients', label: 'Clients' }}
  />

  <div class="panels">
    <form class="panel" onsubmit={save} novalidate>
      <h2 class="panel__title">{client ? 'Fiche' : 'Informations'}</h2>
      <div class="form-grid">
        <div class="field">
          <label class="field__label" for="client-name">Nom</label>
          <input class="input" id="client-name" required maxlength="120" aria-invalid={invalidField === 'name'} bind:value={name} />
        </div>
        <div class="field">
          <label class="field__label" for="client-welcome">Texte d’accueil de l’espace client</label>
          <textarea class="input textarea" id="client-welcome" maxlength="2000" bind:value={welcomeText}></textarea>
        </div>
        <div class="colors">
          <ColorField label="Fond" bind:value={bgColor} />
          <ColorField label="Texte" bind:value={textColor} />
          <ColorField label="Accent" bind:value={accentColor} />
        </div>

        <div class="preview" style:background={bgColor} style:color={textColor} aria-hidden="true">
          <span class="preview__eyebrow">// N° 1042</span>
          <span class="preview__title">Film manifeste</span>
          <span class="preview__button" style:background={accentColor}>Valider cette version</span>
        </div>
        <p class="contrast" class:contrast--low={ratio < AA_TEXT}>
          {#if ratio < AA_TEXT}
            Contraste insuffisant entre le texte et le fond : {formatRatio(ratio)}, le minimum WCAG AA est 4,5:1.
          {:else}
            Contraste texte / fond : {formatRatio(ratio)} (WCAG AA respecté).
          {/if}
        </p>
      </div>

      {#if formError}
        <p class="form-error" role="alert">{formError}</p>
      {/if}
      <div class="form-actions can-edit">
        <button class="btn btn--primary" type="submit" disabled={saving}>{client ? 'Enregistrer' : 'Créer le client'}</button>
        {#if saved}<span class="saved" role="status">Enregistré</span>{/if}
      </div>
    </form>

    {#if client}
      <div class="stack">
        <section class="panel" aria-labelledby="logo-title">
          <h2 class="panel__title" id="logo-title">Logo</h2>
          <div class="logo-box" style:background={client.bgColor}>
            {#if client.logoUrl}
              <img src={client.logoUrl} alt="Logo de {client.name}" />
            {:else}
              <span style:color={client.textColor}>Aucun logo</span>
            {/if}
          </div>
          <p class="panel__text">SVG, PNG ou WebP, 2 Mo au plus. Il remplace la marque Belprise sur les pages du client.</p>
          {#if logoError}<p class="form-error" role="alert">{logoError}</p>{/if}
          <div class="form-actions can-edit">
            <label class="btn btn--small file-button" class:file-button--busy={logoBusy}>
              {client.logoUrl ? 'Remplacer' : 'Choisir un fichier'}
              <input
                class="visually-hidden"
                type="file"
                accept=".svg,.png,.webp,image/svg+xml,image/png,image/webp"
                disabled={logoBusy}
                onchange={uploadLogo}
              />
            </label>
            {#if client.logoUrl}
              <button class="btn btn--small" type="button" disabled={logoBusy} onclick={removeLogo}>Retirer</button>
            {/if}
          </div>
        </section>

        {#if client.link}
          <LinkPanel
            bind:link={client.link}
            title="Espace client"
            description="Tous les projets et vidéos du client, avec les droits du lien Client."
          />
        {/if}
      </div>
    {/if}
  </div>

  {#if client}
    <section class="panel" aria-labelledby="projects-title">
      <div class="panel__head">
        <h2 class="panel__title" id="projects-title">Projets</h2>
        <a class="btn btn--small btn--icon-plus can-edit" href="/admin/projects/new?client={client.id}" onclick={followLink}>Nouveau projet</a>
      </div>
      {#if projects.length === 0}
        <p class="panel__text">Aucun projet pour l’instant.</p>
      {:else}
        <ul class="project-list">
          {#each projects as project (project.id)}
            <li>
              <a class="table__main" href="/admin/projects/{project.id}" onclick={followLink}>{project.name}</a>
              <span class="muted">· {project.videoCount} vidéo{project.videoCount > 1 ? 's' : ''} · {formatDate(project.createdAt)}</span>
              {#if project.archived}<span class="pill pill--off">Archivé</span>{/if}
            </li>
          {/each}
        </ul>
      {/if}
    </section>

    <section class="panel panel--danger" aria-labelledby="danger-title">
      <h2 class="panel__title" id="danger-title">Archivage et suppression</h2>
      <p class="panel__text">
        Un client archivé disparaît des listes ; ses liens continuent de fonctionner. La suppression efface ses projets, ses vidéos,
        leurs fichiers et tous ses liens, définitivement.
      </p>
      <div class="form-actions">
        <button class="btn btn--small can-edit" type="button" onclick={() => setArchived(!client!.archived)}>
          {client.archived ? 'Désarchiver' : 'Archiver'}
        </button>
        <button class="btn btn--small can-delete" type="button" onclick={() => (confirmDelete = true)}>Supprimer le client…</button>
      </div>
    </section>

    <ConfirmDialog
      bind:open={confirmDelete}
      title="Supprimer {client.name} ?"
      confirmLabel="Supprimer définitivement"
      danger
      requireText={client.name}
      onConfirm={remove}
    >
      <p>
        {projects.length} projet(s), toutes leurs vidéos, leurs fichiers et leurs révisions seront supprimés.
        Les liens envoyés au client cesseront de fonctionner. Cette action est irréversible.
      </p>
    </ConfirmDialog>
  {/if}
{/if}

<style>
  .stack {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
  }

  .colors {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
  }

  .preview {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
  }

  .preview__eyebrow {
    font-family: var(--font-mono);
    font-size: 11.5px;
    opacity: 0.7;
  }

  .preview__title {
    font-family: var(--font-display);
    font-size: 28px;
    line-height: 1.1;
  }

  .preview__button {
    margin-top: 8px;
    padding: 8px 16px;
    border-radius: var(--radius-pill);
    color: #0a0a0b;
    font-size: 12.5px;
    font-weight: 600;
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

  .logo-box {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 120px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
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

  .project-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 13.5px;
  }

  .project-list li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
  }
</style>
