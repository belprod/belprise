<script lang="ts">
  import type { Snippet } from 'svelte';

  let {
    open = $bindable(false),
    title,
    confirmLabel,
    busy = false,
    canConfirm = true,
    error = '',
    onConfirm,
    children,
  }: {
    open?: boolean;
    title: string;
    confirmLabel: string;
    busy?: boolean;
    canConfirm?: boolean;
    error?: string;
    onConfirm: () => void;
    children: Snippet;
  } = $props();

  const uid = $props.id();
  let dialog: HTMLDialogElement;

  $effect(() => {
    if (open && !dialog.open) dialog.showModal();
    else if (!open && dialog.open) dialog.close();
  });
</script>

<!-- Client-page dialog, in the client's colours. -->
<dialog class="dialog" bind:this={dialog} onclose={() => (open = false)} aria-labelledby="{uid}-title">
  <form class="dialog__form" onsubmit={(e) => (e.preventDefault(), onConfirm())}>
    <h2 class="dialog__title" id="{uid}-title">{title}</h2>
    {@render children()}
    {#if error}<p class="dialog__error" role="alert">{error}</p>{/if}
    <div class="dialog__actions">
      <button class="dialog__cancel" type="button" onclick={() => (open = false)}>Annuler</button>
      <button class="dialog__confirm" type="submit" disabled={busy || !canConfirm}>{confirmLabel}</button>
    </div>
  </form>
</dialog>

<style>
  .dialog {
    width: min(440px, calc(100vw - 32px));
    padding: 0;
    border: 1px solid color-mix(in srgb, var(--client-text) 18%, var(--client-bg));
    border-radius: var(--radius-lg);
    background: var(--client-bg);
    color: var(--client-text);
  }

  .dialog::backdrop {
    background: rgb(0 0 0 / 0.55);
  }

  .dialog__form {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 24px;
    font-size: 13.5px;
  }

  .dialog__title {
    font-family: var(--font-display);
    font-weight: 400;
    font-size: 28px;
    line-height: 1.1;
  }

  .dialog__form :global(input) {
    min-height: var(--hit);
    padding: 0 14px;
    border: 1px solid color-mix(in srgb, var(--client-text) 20%, var(--client-bg));
    border-radius: var(--radius-md);
    background: color-mix(in srgb, var(--client-text) 5%, var(--client-bg));
    color: var(--client-text);
    font-size: 15px;
  }

  .dialog__form :global(.dialog__note) {
    font-size: 12px;
    color: color-mix(in srgb, var(--client-text) 62%, var(--client-bg));
  }

  .dialog__error {
    color: color-mix(in srgb, #e5484d var(--status-ink, 100%), var(--client-text));
  }

  .dialog__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 10px;
  }

  .dialog__cancel,
  .dialog__confirm {
    min-height: var(--hit);
    padding: 0 20px;
    border-radius: var(--radius-pill);
    font-size: 13.5px;
    font-weight: 600;
  }

  .dialog__cancel {
    border: 1px solid color-mix(in srgb, var(--client-text) 20%, var(--client-bg));
    background: transparent;
    color: var(--client-text);
  }

  .dialog__confirm {
    border: none;
    background: var(--client-accent);
    color: var(--client-on-accent);
  }

  .dialog__confirm:disabled {
    opacity: 0.5;
  }
</style>
