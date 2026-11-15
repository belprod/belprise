<script lang="ts">
  import { api, ApiError } from '../../lib/api';
  import { MASTER_ACCEPT, titleFromName } from '../media/formats';
  import { fileSource } from '../media/source';
  import type { Project, Video } from '../types';
  import { serverEncoding, uploads } from '../uploads.svelte';
  import Combobox from './Combobox.svelte';
  import { current } from '../current.svelte';
  import { isDemo } from '../session';

  /**
   * Several masters at once (§5.7): each file is analysed, creates its video
   * (title taken from the file name) and joins the upload queue.
   * projectId null: the project is chosen here (videos list).
   */
  let { projectId = null, onCreated }: { projectId?: number | null; onCreated?: () => void } = $props();

  const uid = $props.id();
  let projects = $state<Project[]>([]);
  let chosenProject = $state('');
  let dragging = $state(false);
  let rows = $state<{ name: string; state: 'analyse' | 'queued' | 'error'; message: string }[]>([]);

  const target = $derived(projectId ?? (chosenProject ? Number(chosenProject) : null));

  $effect(() => {
    if (projectId === null) {
      api<Project[]>('GET', '/api/admin/projects').then((list) => (projects = list.filter((p) => !p.archived && !p.clientArchived))).catch(() => {});
    }
  });

  async function take(files: FileList | File[]): Promise<void> {
    if (target === null) return;
    const { analyzeMaster } = await import('../media/analyze');
    for (const file of Array.from(files)) {
      const row = { name: file.name, state: 'analyse' as const, message: 'Analyse…' };
      rows.push(row);
      const index = rows.length - 1;
      try {
        const info = await analyzeMaster(fileSource(file));
        // ProRes or MXF: with the encoding on the server, nothing to join (§5.7 bis).
        if (!info.playable && !serverEncoding()) {
          throw new Error(`${info.codecLabel} : créez la vidéo puis envoyez ce master depuis sa fiche, avec sa version de lecture en H.264.`);
        }
        const video = await api<Video>('POST', '/api/admin/videos', { projectId: target, title: titleFromName(file.name) });
        uploads.add(file, info, video);
        rows[index] = { name: file.name, state: 'queued', message: `N° ${video.number} créée, envoi en file d’attente.` };
        onCreated?.();
      } catch (e) {
        const message = (e as Error).message;
        rows[index] = { name: file.name, state: 'error', message };
      }
    }
  }

  function onDrop(event: DragEvent): void {
    event.preventDefault();
    dragging = false;
    if (event.dataTransfer?.files?.length) void take(event.dataTransfer.files);
  }

  function onPick(event: Event & { currentTarget: HTMLInputElement }): void {
    const files = event.currentTarget.files;
    if (files?.length) void take(Array.from(files));
    event.currentTarget.value = '';
  }
</script>

{#if !isDemo(current.session)}
<div
  class="drop can-edit"
  class:drop--over={dragging}
  role="group"
  aria-labelledby="{uid}-title"
  ondragover={(e) => {
    if (e.dataTransfer?.types.includes('Files')) {
      e.preventDefault();
      dragging = true;
    }
  }}
  ondragleave={(e) => {
    if (e.currentTarget === e.target) dragging = false;
  }}
  ondrop={onDrop}
>
  <p class="drop__title" id="{uid}-title">Importer des masters</p>
  <p class="drop__text">Glissez un ou plusieurs fichiers ici : chacun devient une vidéo, envoyée à la suite des autres.</p>
  <div class="drop__actions">
    {#if projectId === null}
      <label class="visually-hidden" for="{uid}-project">Projet des nouvelles vidéos</label>
      <Combobox
        id="{uid}-project"
        placeholder="Choisir le projet…"
        bind:value={chosenProject}
        options={projects.map((p) => ({ value: String(p.id), label: p.name, group: p.clientName }))}
      />
    {/if}
    <label class="btn btn--small file-button" class:file-button--off={target === null}>
      Choisir des fichiers
      <input class="visually-hidden" type="file" multiple accept={MASTER_ACCEPT} disabled={target === null} onchange={onPick} />
    </label>
  </div>
  {#if projectId === null && target === null}
    <p class="drop__hint">Choisissez d’abord le projet.</p>
  {/if}
  {#if rows.length}
    <ul class="drop__rows" aria-live="polite">
      {#each rows as row, i (i)}
        <li class:drop__error={row.state === 'error'}><span class="mono">{row.name}</span> — {row.message}</li>
      {/each}
    </ul>
  {/if}
</div>
{/if}

<style>
  .drop {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 20px;
    padding: 16px 18px;
    border: 1px dashed var(--border-strong);
    border-radius: var(--radius-lg);
    background: var(--surface-1);
  }

  .drop--over {
    border-style: solid;
    border-color: var(--accent);
    box-shadow: 0 0 0 1px var(--accent);
  }

  .drop__title {
    font-weight: 600;
  }

  .drop__text,
  .drop__hint {
    color: var(--text-muted);
    font-size: 13px;
  }

  .drop__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
  }

  .file-button {
    cursor: pointer;
  }

  .file-button:focus-within {
    box-shadow: var(--focus-ring);
  }

  .file-button--off {
    opacity: 0.5;
    cursor: not-allowed;
  }

  .drop__rows {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin: 4px 0 0;
    padding: 0;
    list-style: none;
    font-size: 13px;
  }

  .drop__error {
    color: color-mix(in srgb, var(--status-refused) var(--status-ink, 100%), var(--text));
  }
</style>
