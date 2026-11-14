<script lang="ts">
  let {
    versions,
    selected,
    onSelect,
  }: { versions: { id: number; number: number }[]; selected: number; onSelect: (id: number) => void } = $props();
</script>

<div class="picker" role="group" aria-label="Versions">
  {#each versions as version (version.id)}
    <button
      class="picker__item"
      type="button"
      aria-pressed={version.id === selected}
      onclick={() => onSelect(version.id)}
    >
      V{version.number}
    </button>
  {/each}
</div>

<style>
  .picker {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 4px;
    border: 1px solid color-mix(in srgb, var(--client-text) 14%, var(--client-bg));
    border-radius: var(--radius-pill);
    background: color-mix(in srgb, var(--client-text) 6%, var(--client-bg));
  }

  .picker__item {
    min-width: 52px;
    height: 36px;
    padding: 0 14px;
    border: none;
    border-radius: var(--radius-pill);
    background: transparent;
    color: color-mix(in srgb, var(--client-text) 74%, var(--client-bg));
    font-family: var(--font-mono);
    font-size: 13px;
    font-weight: 500;
  }

  .picker__item[aria-pressed='true'] {
    background: var(--client-accent);
    color: var(--client-on-accent);
  }
</style>
