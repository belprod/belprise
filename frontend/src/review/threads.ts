/** Comment threads: grouping, counts, filters, timeline markers, overlay (§5.1). */
import type { ReviewComment } from './types';

export interface Thread {
  comment: ReviewComment;
  replies: ReviewComment[];
}

export type Filter = 'all' | 'todo' | 'done' | 'refused';

export function threads(comments: ReviewComment[]): Thread[] {
  const replies = new Map<number, ReviewComment[]>();
  for (const comment of comments) {
    if (comment.parentId !== null) {
      replies.set(comment.parentId, [...(replies.get(comment.parentId) ?? []), comment]);
    }
  }
  const byDate = (a: ReviewComment, b: ReviewComment): number => a.createdAt.localeCompare(b.createdAt) || a.id - b.id;
  return comments
    .filter((c) => c.parentId === null)
    .sort((a, b) => a.frameIn - b.frameIn || byDate(a, b))
    .map((comment) => ({ comment, replies: (replies.get(comment.id) ?? []).sort(byDate) }));
}

export function counts(list: Thread[]): Record<Filter, number> {
  const live = list.filter((t) => !t.comment.deleted);
  const count = (status: string): number => live.filter((t) => t.comment.status === status).length;
  const done = count('done');
  const refused = count('refused');
  return { all: live.length, todo: live.length - done - refused, done, refused };
}

export function filterThreads(list: Thread[], filter: Filter): Thread[] {
  if (filter === 'all') return list;
  return list.filter((t) => !t.comment.deleted && t.comment.status === filter);
}

export function excerpt(text: string, length = 80): string {
  const flat = text.replace(/\s+/g, ' ').trim();
  return flat.length > length ? `${flat.slice(0, length - 1).trimEnd()}…` : flat;
}

export interface Marker {
  id: number;
  frameIn: number;
  frameOut: number | null;
  color: string;
  label: string;
}

const MARKER_COLORS: Record<string, string> = {
  todo: 'var(--status-changes)',
  done: 'var(--status-approved)',
  refused: 'var(--status-refused)',
};

/** Point for an instant, bar for a range; yellow "À faire", green "Fait", red "Refusé". */
export function markers(list: Thread[]): Marker[] {
  return list
    .filter((t) => !t.comment.deleted)
    .map(({ comment }) => ({
      id: comment.id,
      frameIn: comment.frameIn,
      frameOut: comment.frameOut,
      color: MARKER_COLORS[comment.status ?? 'todo'] ?? 'var(--status-changes)',
      label: `${comment.author} — ${excerpt(comment.text, 60)}`,
    }));
}

/**
 * Comment shown over the picture at a frame: a range while it lasts, an
 * instant for two seconds. The latest one to start wins.
 */
export function overlayAt(list: Thread[], frame: number, fps: number): Thread | null {
  let found: Thread | null = null;
  for (const thread of list) {
    const { comment } = thread;
    if (comment.deleted) continue;
    const end = comment.frameOut ?? comment.frameIn + Math.round(fps * 2);
    if (frame >= comment.frameIn && frame <= end && (!found || comment.frameIn >= found.comment.frameIn)) {
      found = thread;
    }
  }
  return found;
}
