<script lang="ts">
  import { untrack } from 'svelte';
  import { api as request } from '../../lib/api';
  import { formatDate } from '../../lib/format';
  import { queryId } from '../../lib/router.svelte';
  import { VERSION_STATUS } from '../../lib/status';
  import { formatRate, framesToTimecode, nominalFps } from '../../lib/timecode';
  import Player from '../../player/Player.svelte';
  import VersionPicker from '../../player/VersionPicker.svelte';
  import { adminReviewApi } from '../../review/api';
  import CommentsPanel from '../../review/CommentsPanel.svelte';
  import { excerpt, markers as toMarkers, overlayAt, type Thread } from '../../review/threads';
  import { listVersions } from '../media/versions';
  import type { Version, Video } from '../types';
  import { current } from '../current.svelte';
  import { uploads } from '../uploads.svelte';

  let { video }: { video: Video } = $props();

  const uid = $props.id();
  const api = adminReviewApi();
  let versions = $state<Version[]>([]);
  // "Répondre" from "Révisions" opens ?version=…&comment=…
  let selectedId = $state(queryId('version') ?? 0);
  const highlight = untrack(() => queryId('comment'));
  const position = { frame: 0 };
  let frame = $state(0);
  /** Admin only: the master itself instead of the playback version (grading check, §5.7 bis). */
  let original = $state(false);
  let marks = $state<{ in: number | null; out: number | null }>({ in: null, out: null });
  let threads = $state<Thread[]>([]);
  let player = $state<ReturnType<typeof Player> | null>(null);
  let panel = $state<ReturnType<typeof CommentsPanel> | null>(null);

  const playable = $derived(versions.filter((v) => v.videoUrl && v.fpsNum && v.fpsDen && v.durationFrames));
  const selected = $derived(playable.find((v) => v.id === selectedId) ?? playable[0] ?? null);
  const rate = $derived(selected ? { num: selected.fpsNum!, den: selected.fpsDen!, dropFrame: selected.dropFrame } : null);
  const markerList = $derived(toMarkers(threads));
  const overlay = $derived.by(() => {
    if (!rate || !selected) return null;
    const thread = overlayAt(threads, frame, nominalFps(rate));
    if (!thread) return null;
    const c = thread.comment;
    const tc = (f: number): string => framesToTimecode(selected.startFrame + f, rate);
    return {
      timecode: c.frameOut === null ? tc(c.frameIn) : `${tc(c.frameIn)} → ${tc(c.frameOut)}`,
      text: `${c.author} — « ${excerpt(c.text, 110)} »`,
    };
  });

  async function load(): Promise<void> {
    versions = await listVersions(video.id);
  }

  $effect(() => {
    void uploads.completed;
    load().catch(() => {});
  });

  function selectVersion(id: number): void {
    threads = [];
    marks = { in: null, out: null };
    selectedId = id;
  }

  /** The admin can cancel a validation (§5.1): back to "À valider". */
  async function cancelApproval(): Promise<void> {
    if (!selected) return;
    await request('PATCH', `/api/admin/versions/${selected.id}`, { status: 'to_review' });
    await load();
  }
</script>

{#if selected && rate}
  <section class="panel" aria-labelledby="{uid}-title">
    <div class="panel__head">
      <h2 class="panel__title" id="{uid}-title">Revue</h2>
      {#if playable.length > 1}
        <VersionPicker versions={playable} selected={selected.id} onSelect={selectVersion} />
      {/if}
    </div>
    <div class="meta">
      <span class="status status--{selected.status}">{VERSION_STATUS[selected.status]}</span>
      <span>V{selected.number}{selected.isPublished ? '' : ' · non publiée'}</span>
      <span class="mono">{selected.width}×{selected.height} · {formatRate(rate)} · TC {framesToTimecode(selected.startFrame, rate)}</span>
      <span class="edl">
        Marqueurs EDL :
        <a class="btn btn--small" href="/api/admin/versions/{selected.id}/edl" download>tous</a>
        <a class="btn btn--small" href="/api/admin/versions/{selected.id}/edl?filter=todo" download>à faire</a>
      </span>
      {#if selected.hlsUrl && selected.playable}
        <label class="check original">
          <input type="checkbox" bind:checked={original} /> Qualité originale (master)
        </label>
      {/if}
      {#if selected.status === 'approved'}
        <span>
          Validée{selected.approvedBy ? ` par ${selected.approvedBy}` : ''}{selected.approvedAt ? ` le ${formatDate(selected.approvedAt)}` : ''}
        </span>
        <button class="btn btn--small" type="button" onclick={cancelApproval}>Annuler la validation</button>
      {/if}
    </div>

    <div class="review">
      <div class="review__player">
        {#key `${selected.id}-${original}`}
          <Player
            bind:this={player}
            src={selected.videoUrl!}
            hls={original ? null : selected.hlsUrl}
            poster={selected.posterUrl}
            sprite={selected.sprite && selected.spriteUrl ? { url: selected.spriteUrl, ...selected.sprite } : null}
            {rate}
            startFrame={selected.startFrame}
            durationFrames={selected.durationFrames!}
            initialFrame={position.frame}
            markers={markerList}
            {overlay}
            onFrame={(f) => ((position.frame = f), (frame = f))}
            onMarks={(markIn, markOut) => (marks = { in: markIn, out: markOut })}
            onComment={(f) => panel?.focusComposer(f)}
            onMarker={(m) => (player?.goTo(m.frameIn), panel?.show(Number(m.id)))}
          />
        {/key}
      </div>
      <div class="review__side">
        {#key selected.id}
          <CommentsPanel
            bind:this={panel}
            {api}
            versionId={selected.id}
            versionLabel="V{selected.number}"
            {rate}
            startFrame={selected.startFrame}
            currentFrame={frame}
            {marks}
            initialHighlight={selected.id === queryId('version') ? highlight : null}
            signature={current.session?.organization?.name ?? ''}
            onSeek={(f) => player?.goTo(f)}
            onPause={() => player?.stop()}
            onClearMarks={() => player?.clearMarks()}
            onLoaded={(list, state) => {
              threads = list;
              if (state.status !== selected.status) void load();
            }}
          />
        {/key}
      </div>
    </div>
  </section>
{/if}

<style>
  .meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--text-muted);
  }

  .edl {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .review {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 20px;
  }

  .review__player {
    flex: 999 1 560px;
    min-width: 0;
  }

  .review__side {
    flex: 1 1 340px;
    min-width: 0;
    display: flex;
  }

  .review__side > :global(*) {
    flex: 1;
  }
</style>
