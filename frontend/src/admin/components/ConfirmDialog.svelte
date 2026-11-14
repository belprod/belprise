<script lang="ts">
  import type { Snippet } from 'svelte';
  import { ApiError } from '../../lib/api';

  let {
    open = $bindable(false),
    title,
    confirmLabel,
    danger = false,
    requireText,
    onConfirm,
    children,
  }: {
    open?: boolean;
    title: string;
    confirmLabel: string;
    danger?: boolean;
    /** Reinforced confirmation: this text must be typed again. */
    requireText?: string;
    onConfirm: (typed: string) => Promise<void>;
    children: Snippet;
  } = $props();

  const uid = $props.id();
  let dialog: HTMLDialogElement;
  let typed = $state('');
  let busy = $state(false);
  let error = $state('');

  const canConfirm = $derived(
    requireText === undefined || typed.trim().toLocaleLowerCase('fr') === requireText.trim().toLocaleLowerCase('fr'),
  );

  $effect(() => {
    if (open && !dialog.open) {
      typed = '';
      error = '';
      dialog.showModal();
    } else if (!open && dialog.open) {
      dialog.close();
    }
  });

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!canConfirm || busy) return;
    busy = true;
    error = '';
    try {
      await onConfirm(typed.trim());
      open = false;
    } catch (e) {
      error = e instanceof ApiError ? e.message : 'Une erreur est survenue.';
    } finally {
      busy = false;
    }
  }
</script>

<dialog class="dialog" bind:this={dialog} onclose={() => (open = false)} aria-labelledby="{uid}-title">
  <form class="dialog__form" onsubmit={submit}>
    <h2 class="dialog__title" id="{uid}-title">{title}</h2>
    <div class="dialog__body">{@render children()}</div>
    {#if requireText !== undefined}
      <div class="field">
        <label class="field__label" for="{uid}-confirm">Pour confirmer, saisissez « {requireText} »</label>
        <input class="input" id="{uid}-confirm" autocomplete="off" spellcheck="false" bind:value={typed} />
      </div>
    {/if}
    {#if error}
      <p class="form-error" role="alert">{error}</p>
    {/if}
    <div class="dialog__actions">
      <button class="btn" type="button" onclick={() => (open = false)}>Annuler</button>
      <button class="btn {danger ? 'btn--danger' : 'btn--primary'}" type="submit" disabled={busy || !canConfirm}>
        {confirmLabel}
      </button>
    </div>
  </form>
</dialog>
