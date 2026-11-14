<script lang="ts">
  let {
    tags = $bindable([]),
    suggestions = [],
    id,
  }: { tags: string[]; suggestions?: string[]; id: string } = $props();

  const MAX_LENGTH = 40;
  let draft = $state('');

  const available = $derived(
    suggestions.filter((s) => !tags.some((t) => t.toLocaleLowerCase('fr') === s.toLocaleLowerCase('fr'))),
  );

  function add(raw: string): void {
    const name = raw.trim().replace(/\s+/g, ' ').slice(0, MAX_LENGTH);
    draft = '';
    if (name && !tags.some((t) => t.toLocaleLowerCase('fr') === name.toLocaleLowerCase('fr'))) {
      tags = [...tags, name];
    }
  }

  function remove(tag: string): void {
    tags = tags.filter((t) => t !== tag);
  }

  function onkeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault();
      add(draft);
    } else if (event.key === 'Backspace' && draft === '' && tags.length > 0) {
      tags = tags.slice(0, -1);
    }
  }

  // Picking a suggestion from the list fires an input event without a key press.
  function oninput(event: Event): void {
    if (!(event instanceof InputEvent) || event.inputType === 'insertReplacementText') {
      if (suggestions.includes(draft)) add(draft);
    }
  }
</script>

<div class="tag-input">
  {#if tags.length > 0}
    <ul class="tag-input__list" aria-label="Tags choisis">
      {#each tags as tag (tag)}
        <li class="tag">
          {tag}
          <button class="tag-input__remove" type="button" aria-label="Retirer le tag {tag}" onclick={() => remove(tag)}>×</button>
        </li>
      {/each}
    </ul>
  {/if}
  <input
    {id}
    class="tag-input__field"
    list="{id}-suggestions"
    autocomplete="off"
    placeholder={tags.length ? 'Ajouter un tag' : 'pub, corporate, captation…'}
    bind:value={draft}
    {onkeydown}
    {oninput}
    onblur={() => add(draft)}
  />
  <datalist id="{id}-suggestions">
    {#each available as suggestion (suggestion)}
      <option value={suggestion}></option>
    {/each}
  </datalist>
</div>

<style>
  .tag-input {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    min-height: var(--hit);
    padding: 6px 10px;
    border: 1px solid var(--border-strong);
    border-radius: var(--radius-md);
    background: var(--surface-2);
  }

  .tag-input:focus-within {
    border-color: var(--accent);
  }

  .tag-input__list {
    display: contents;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .tag-input__remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    margin-right: -6px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: none;
    color: var(--text-muted);
    font-size: 15px;
    line-height: 1;
  }

  .tag-input__remove:hover {
    color: var(--text);
    background: var(--surface-3);
  }

  .tag-input__field {
    flex: 1 1 140px;
    min-width: 0;
    min-height: 30px;
    border: none;
    background: transparent;
    font-size: 14px;
    outline: none;
  }

  .tag-input__field:focus-visible {
    box-shadow: none;
  }
</style>
