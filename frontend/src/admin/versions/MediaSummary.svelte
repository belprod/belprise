<script lang="ts">
  import { formatBytes, formatSeconds } from '../../lib/format';
  import { formatRate, framesToTimecode } from '../../lib/timecode';
  import type { MediaInfo } from '../media/analyze';
  import { TIMECODE_SOURCE } from '../status';

  let { info }: { info: MediaInfo } = $props();

  const rate = $derived({ num: info.fpsNum, den: info.fpsDen, dropFrame: info.dropFrame });
</script>

<dl class="summary">
  <div><dt>Fichier</dt><dd class="mono">{info.name}</dd></div>
  <div><dt>Poids</dt><dd>{formatBytes(info.size)}</dd></div>
  <div><dt>Codec</dt><dd>{info.codecLabel}</dd></div>
  {#if info.fpsNum === 0}
    <!-- MXF: read by the server (or taken from the joined H.264 file). -->
    <div><dt>Informations</dt><dd class="muted">Cadence, durée et timecode lus après l’envoi</dd></div>
  {:else}
  <div><dt>Résolution</dt><dd>{info.width}×{info.height}</dd></div>
  <div><dt>Cadence</dt><dd>{formatRate(rate)}</dd></div>
  <div>
    <dt>Timecode de départ</dt>
    <dd><span class="mono">{framesToTimecode(info.startFrame, rate)}</span> <span class="muted">({TIMECODE_SOURCE[info.timecodeSource]})</span></dd>
  </div>
  <div><dt>Durée</dt><dd>{formatSeconds(info.durationSeconds)} · {info.durationFrames} images</dd></div>
  <div><dt>Débit</dt><dd>{(info.bitRate / 1e6).toFixed(1).replace('.', ',')} Mb/s</dd></div>
  {/if}
</dl>
{#each info.warnings as warning (warning)}
  <p class="notice">{warning}</p>
{/each}

<style>
  .summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px 20px;
    margin: 0;
  }

  .summary div {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  dt {
    font-family: var(--font-mono);
    font-size: 11px;
    letter-spacing: 0.04em;
    color: var(--text-muted);
  }

  dd {
    margin: 0;
    font-size: 13.5px;
    overflow-wrap: anywhere;
  }
</style>
