<script lang="ts">
  import { api } from '../../lib/api';
  import { STANDARD_RATES, supportsDropFrame, timecodeToFrames } from '../../lib/timecode';
  import ConfirmDialog from '../components/ConfirmDialog.svelte';
  import type { Version } from '../types';

  let { open = $bindable(false), version, onSaved }: { open?: boolean; version: Version; onSaved: (v: Version) => void } = $props();

  const uid = $props.id();
  let rateKey = $state('');
  let dropFrame = $state(false);
  let startTimecode = $state('');
  let width = $state(0);
  let height = $state(0);

  const rates = $derived.by(() => {
    const list = STANDARD_RATES.map((r) => ({ key: `${r.num}/${r.den}`, label: `${r.label} i/s`, num: r.num, den: r.den }));
    const current = `${version.fpsNum}/${version.fpsDen}`;
    if (version.fpsNum && version.fpsDen && !list.some((r) => r.key === current)) {
      list.push({ key: current, label: `${(version.fpsNum / version.fpsDen).toFixed(3)} i/s (détectée)`, num: version.fpsNum, den: version.fpsDen });
    }
    return list;
  });
  const rate = $derived(rates.find((r) => r.key === rateKey) ?? rates[2]!);
  const dfPossible = $derived(supportsDropFrame(rate));
  const valid = $derived(timecodeToFrames(startTimecode, { num: rate.num, den: rate.den, dropFrame: dfPossible && dropFrame }) !== null);

  $effect(() => {
    if (open) {
      rateKey = `${version.fpsNum ?? 25}/${version.fpsDen ?? 1}`;
      dropFrame = version.dropFrame;
      startTimecode = version.startTimecode ?? '00:00:00:00';
      width = version.width ?? 1920;
      height = version.height ?? 1080;
    }
  });

  async function save(): Promise<void> {
    onSaved(
      await api<Version>('PATCH', `/api/admin/versions/${version.id}`, {
        fpsNum: rate.num,
        fpsDen: rate.den,
        dropFrame: dfPossible && dropFrame,
        startTimecode,
        width: Number(width),
        height: Number(height),
      }),
    );
  }
</script>

<ConfirmDialog bind:open title="Métadonnées de la V{version.number}" confirmLabel="Enregistrer" onConfirm={save}>
  <div class="form-grid">
    <p>Valeurs détectées à l’import. Corrigez-les si elles ne correspondent pas au montage d’origine.</p>
    <div class="field">
      <label class="field__label" for="{uid}-rate">Cadence</label>
      <select class="select select--field" id="{uid}-rate" bind:value={rateKey}>
        {#each rates as r (r.key)}<option value={r.key}>{r.label}</option>{/each}
      </select>
    </div>
    {#if dfPossible}
      <label class="check"><input type="checkbox" bind:checked={dropFrame} /> Drop-frame</label>
    {/if}
    <div class="field">
      <label class="field__label" for="{uid}-tc">Timecode de départ</label>
      <input class="input input--mono" id="{uid}-tc" bind:value={startTimecode} aria-invalid={!valid} spellcheck="false" />
      {#if !valid}<p class="field__hint">Format HH:MM:SS:FF, valide pour cette cadence.</p>{/if}
    </div>
    <div class="dims">
      <div class="field">
        <label class="field__label" for="{uid}-w">Largeur</label>
        <input class="input" id="{uid}-w" type="number" min="16" max="4096" bind:value={width} />
      </div>
      <div class="field">
        <label class="field__label" for="{uid}-h">Hauteur</label>
        <input class="input" id="{uid}-h" type="number" min="16" max="4096" bind:value={height} />
      </div>
    </div>
  </div>
</ConfirmDialog>

<style>
  .dims {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }
</style>
