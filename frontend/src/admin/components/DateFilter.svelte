<script lang="ts">
  /** "Plage de dates" filter (§5.7): presets or two dates. Outputs local YYYY-MM-DD bounds. */
  let { from = $bindable(''), to = $bindable(''), id }: { from?: string; to?: string; id: string } = $props();

  let preset = $state('');

  const day = (d: Date): string =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

  function apply(value: string): void {
    preset = value;
    const today = new Date();
    if (value === '') {
      from = to = '';
    } else if (value === '7' || value === '30') {
      const start = new Date(today);
      start.setDate(today.getDate() - Number(value) + 1);
      from = day(start);
      to = day(today);
    } else if (value === 'month') {
      from = day(new Date(today.getFullYear(), today.getMonth(), 1));
      to = day(today);
    }
  }
</script>

<label class="visually-hidden" for={id}>Période</label>
<select class="select" {id} value={preset} onchange={(e) => apply(e.currentTarget.value)}>
  <option value="">Date : toutes</option>
  <option value="7">7 derniers jours</option>
  <option value="30">30 derniers jours</option>
  <option value="month">Ce mois-ci</option>
  <option value="custom">Période…</option>
</select>
{#if preset === 'custom'}
  <label class="visually-hidden" for="{id}-from">Du</label>
  <input class="input date" id="{id}-from" type="date" bind:value={from} />
  <label class="visually-hidden" for="{id}-to">Au</label>
  <input class="input date" id="{id}-to" type="date" bind:value={to} />
{/if}

<style>
  .date {
    width: auto;
    border-radius: var(--radius-pill);
    color-scheme: dark;
  }
</style>
