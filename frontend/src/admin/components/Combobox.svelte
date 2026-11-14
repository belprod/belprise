<script lang="ts">
  import { tick } from 'svelte';
  import { filterOptions, groupOptions, type ComboOption } from './combobox';

  /**
   * Drop-down list you can type into: only the matching choices remain
   * (accents and case ignored). ARIA "combobox" pattern, keyboard driven.
   * The <label for={id}> stays with the caller.
   */
  let {
    id,
    options,
    value = $bindable(''),
    placeholder = '',
    field = false,
    required = false,
    disabled = false,
    invalid = false,
    onchange,
  }: {
    id: string;
    options: ComboOption[];
    value?: string;
    placeholder?: string;
    /** Form field look (full width), instead of a toolbar filter */
    field?: boolean;
    required?: boolean;
    disabled?: boolean;
    invalid?: boolean;
    onchange?: (value: string) => void;
  } = $props();

  const listId = $derived(`${id}-list`);
  let input = $state<HTMLInputElement | null>(null);
  let open = $state(false);
  let query = $state('');
  let active = $state(0);

  const selected = $derived(options.find((o) => o.value === value) ?? null);
  const ordered = $derived(groupOptions(options));
  const shown = $derived(filterOptions(ordered, query));
  const optionId = (index: number): string => `${id}-opt-${index}`;

  // Native "required": the input is empty while nothing is chosen.
  $effect(() => {
    input?.setCustomValidity(required && value === '' ? 'Choisissez dans la liste.' : '');
  });

  function openList(): void {
    if (disabled || open) return;
    query = '';
    open = true;
    active = Math.max(0, ordered.findIndex((o) => o.value === value));
    void tick().then(revealActive);
  }

  function close(): void {
    open = false;
    query = '';
  }

  function choose(option: ComboOption): void {
    const changed = option.value !== value;
    value = option.value;
    close();
    if (changed) onchange?.(option.value);
  }

  function revealActive(): void {
    document.getElementById(optionId(active))?.scrollIntoView({ block: 'nearest' });
  }

  function move(delta: number): void {
    if (!open) {
      openList();
      return;
    }
    if (shown.length === 0) return;
    active = (active + delta + shown.length) % shown.length;
    void tick().then(revealActive);
  }

  function onKeydown(event: KeyboardEvent): void {
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        move(1);
        break;
      case 'ArrowUp':
        event.preventDefault();
        move(-1);
        break;
      case 'Home':
      case 'End':
        if (open && shown.length > 0) {
          event.preventDefault();
          active = event.key === 'Home' ? 0 : shown.length - 1;
          void tick().then(revealActive);
        }
        break;
      case 'Enter':
        if (open) {
          event.preventDefault();
          const option = shown[active];
          if (option) choose(option);
        }
        break;
      case 'Escape':
        if (open) {
          event.preventDefault();
          event.stopPropagation();
          close();
        }
        break;
      case 'Tab':
        close();
        break;
    }
  }

  function onInput(event: Event): void {
    query = (event.currentTarget as HTMLInputElement).value;
    open = true;
    active = 0;
  }
</script>

<div class="combo" class:combo--field={field}>
  <input
    bind:this={input}
    {id}
    class="select combo__input"
    class:select--field={field}
    type="text"
    role="combobox"
    autocomplete="off"
    spellcheck="false"
    aria-autocomplete="list"
    aria-expanded={open}
    aria-controls={listId}
    aria-activedescendant={open && shown[active] ? optionId(active) : undefined}
    aria-invalid={invalid || undefined}
    {required}
    {disabled}
    value={open ? query : (selected?.label ?? '')}
    placeholder={open ? (selected?.label ?? placeholder) : placeholder}
    onfocus={openList}
    onclick={openList}
    oninput={onInput}
    onkeydown={onKeydown}
    onblur={close}
  />
  {#if open}
    <!-- pointerdown is cancelled so the input keeps the focus while choosing -->
    <ul class="combo__list" id={listId} role="listbox" onpointerdown={(e) => e.preventDefault()}>
      {#each shown as option, index (option.value)}
        {#if option.group && option.group !== shown[index - 1]?.group}
          <li class="combo__group" role="presentation">{option.group}</li>
        {/if}
        <!-- Keyboard choice goes through the input (aria-activedescendant) -->
        <!-- svelte-ignore a11y_click_events_have_key_events -->
        <li
          id={optionId(index)}
          class="combo__option"
          class:combo__option--active={index === active}
          role="option"
          aria-selected={option.value === value}
          onclick={() => choose(option)}
          onpointermove={() => (active = index)}
        >
          {option.label}
        </li>
      {:else}
        <li class="combo__empty" role="presentation">Aucun résultat</li>
      {/each}
    </ul>
  {/if}
</div>

<style>
  .combo {
    position: relative;
    display: inline-block;
    min-width: 0;
  }

  .combo--field {
    display: block;
    width: 100%;
  }

  .combo__input {
    width: 100%;
    min-width: 150px;
    text-overflow: ellipsis;
    cursor: pointer;
  }

  .combo__input:focus {
    cursor: text;
  }

  .combo__input::placeholder {
    color: var(--text-muted);
  }

  .combo__list {
    position: absolute;
    z-index: 30;
    top: calc(100% + 6px);
    left: 0;
    width: max-content;
    min-width: 100%;
    max-width: min(420px, 90vw);
    max-height: 320px;
    margin: 0;
    padding: 6px;
    overflow-y: auto;
    list-style: none;
    border: 1px solid var(--border-strong);
    border-radius: var(--radius-md);
    background: var(--surface-2);
    box-shadow: 0 12px 32px rgb(0 0 0 / 0.45);
  }

  .combo__group {
    padding: 10px 10px 4px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  .combo__option {
    padding: 9px 10px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    cursor: pointer;
    overflow-wrap: anywhere;
  }

  .combo__option--active {
    background: var(--surface-3);
  }

  .combo__option[aria-selected='true'] {
    color: var(--accent-text);
    font-weight: 600;
  }

  .combo__empty {
    padding: 9px 10px;
    color: var(--text-muted);
    font-size: 13px;
  }
</style>
