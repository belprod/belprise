<script lang="ts">
  let { label, value = $bindable() }: { label: string; value: string } = $props();

  const uid = $props.id();
  const HEX = /^#[0-9a-f]{6}$/i;

  function fromText(event: Event & { currentTarget: HTMLInputElement }): void {
    let text = event.currentTarget.value.trim();
    if (!text.startsWith('#')) text = `#${text}`;
    if (HEX.test(text)) value = text.toLowerCase();
  }
</script>

<div class="field">
  <span class="field__label" id="{uid}-label">{label}</span>
  <div class="color-field">
    <input
      class="color-field__swatch"
      type="color"
      aria-label="{label} : sélecteur"
      {value}
      oninput={(e) => (value = e.currentTarget.value)}
    />
    <input
      class="input input--mono"
      aria-label="{label} : code hexadécimal"
      maxlength="7"
      spellcheck="false"
      {value}
      oninput={fromText}
      onblur={(e) => (e.currentTarget.value = value)}
    />
  </div>
</div>

<style>
  .color-field {
    display: flex;
    gap: 8px;
  }

  .color-field__swatch {
    flex: none;
    width: var(--hit);
    height: var(--hit);
    padding: 3px;
    border: 1px solid var(--border-strong);
    border-radius: var(--radius-md);
    background: var(--surface-2);
    cursor: pointer;
  }

  .color-field__swatch::-webkit-color-swatch-wrapper {
    padding: 0;
  }

  .color-field__swatch::-webkit-color-swatch {
    border: none;
    border-radius: 6px;
  }

  .color-field__swatch::-moz-color-swatch {
    border: none;
    border-radius: 6px;
  }
</style>
