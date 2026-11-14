<script lang="ts">
  import { formatBytes, formatSeconds, formatSpeed } from '../../lib/format';
  import { overallProgress, stepLabel } from '../jobProgress';
  import { uploads, type UploadJob } from '../uploads.svelte';

  let { job, compact = false }: { job: UploadJob; compact?: boolean } = $props();

  const running = $derived(!['done', 'error', 'cancelled'].includes(job.state));
  const percent = $derived(Math.floor(overallProgress(job) * 100));
  const uploadStep = $derived(job.stage === 'upload');
  // Only what needs the admin: a cut, an error, the end. The step says the rest.
  const message = $derived(running && job.state !== 'offline' && !(uploadStep && job.state === 'preparing') ? '' : job.message);
</script>

<div class="job" class:job--compact={compact}>
  {#if !compact}<p class="eyebrow">// {job.videoLabel}</p>{/if}
  <p class="job__name">{job.name}</p>
  {#if running}
    <div class="bar" role="progressbar" aria-label="Import de {job.name}" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100}>
      <div class="bar__fill" class:bar__fill--paused={job.state === 'offline'} style:width="{percent}%"></div>
    </div>
    <p class="job__figures job__step"><span>{stepLabel(job.stage)}</span><span>{percent} %</span></p>
    {#if uploadStep && job.state === 'uploading'}
      <p class="job__figures">
        <span>{formatBytes(job.sent)} / {formatBytes(job.size)}{job.speed > 0 ? ` · ${formatSpeed(job.speed)}` : ''}</span>
        <span>{job.eta === null ? '' : `encore ${formatSeconds(job.eta)}`}</span>
      </p>
    {:else if !uploadStep}
      <p class="job__hint">Laissez cette page ouverte jusqu’à la fin.</p>
    {/if}
  {/if}
  {#if message}
    <p class="job__message" class:job__message--error={job.state === 'error'} role={job.state === 'error' ? 'alert' : 'status'}>
      {message}
    </p>
  {/if}
  <div class="job__actions">
    {#if running}
      {#if job.state !== 'finishing'}<button class="btn btn--small" type="button" onclick={() => uploads.cancel(job)}>Annuler</button>{/if}
    {:else if job.state === 'error'}
      <button class="btn btn--small btn--primary" type="button" onclick={() => uploads.retry(job)}>Reprendre</button>
      <button class="btn btn--small" type="button" onclick={() => uploads.cancel(job).then(() => uploads.dismiss(job))}>Abandonner</button>
    {:else}
      <button class="btn btn--small" type="button" onclick={() => uploads.dismiss(job)}>Fermer</button>
    {/if}
  </div>
</div>

<style>
  .job {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
  }

  .job__name {
    font-size: 13px;
    font-weight: 500;
    overflow-wrap: anywhere;
  }

  .bar {
    height: 6px;
    border-radius: 3px;
    background: var(--border);
    overflow: hidden;
  }

  .bar__fill {
    height: 100%;
    border-radius: 3px;
    background: var(--accent);
    transition: width 300ms linear;
  }

  .bar__fill--paused {
    background: var(--status-changes);
  }

  .job__figures {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-family: var(--font-mono);
    font-size: 11.5px;
    color: var(--text-muted);
  }

  .job__step {
    align-items: baseline;
    font-family: var(--font-ui);
    font-size: 12.5px;
    font-weight: 500;
    color: var(--text);
  }

  .job__step span:last-child {
    flex-shrink: 0;
    font-family: var(--font-mono);
    font-size: 11.5px;
    white-space: nowrap;
  }

  .job__hint {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-muted);
  }

  .job__message {
    font-size: 12px;
    color: var(--text-muted);
  }

  .job__message--error {
    color: color-mix(in srgb, #ff8a95 var(--status-ink, 100%), var(--text));
  }

  .job__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .job__actions:empty {
    display: none;
  }

  .job--compact .job__actions .btn {
    min-height: 32px;
  }
</style>
