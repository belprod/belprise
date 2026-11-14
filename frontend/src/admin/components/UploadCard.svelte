<script lang="ts">
  import { uploads } from '../uploads.svelte';
  import JobProgress from '../versions/JobProgress.svelte';

  const job = $derived(uploads.current);
</script>

{#if job}
  <div class="upload-card">
    <p class="upload-card__label">{job.state === 'offline' ? 'IMPORT EN PAUSE' : 'IMPORT EN COURS'}</p>
    <JobProgress {job} compact />
    {#if job.stage === 'upload' && (job.state === 'uploading' || job.state === 'offline')}
      <p class="upload-card__hint">Reprise automatique en cas de coupure</p>
    {/if}
  </div>
{/if}

<style>
  .upload-card {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface-2);
  }

  .upload-card__label {
    font-family: var(--font-mono);
    font-size: 11px;
    letter-spacing: 0.06em;
    color: var(--text-muted);
  }

  .upload-card__hint {
    font-size: 12px;
    color: var(--text-muted);
  }
</style>
