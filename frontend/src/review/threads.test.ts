import { describe, expect, it } from 'vitest';
import { counts, excerpt, filterThreads, markers, overlayAt, threads } from './threads';
import type { ReviewComment } from './types';

let next = 1;
const c = (fields: Partial<ReviewComment>): ReviewComment => ({
  id: next++,
  parentId: null,
  author: 'Claire',
  isAdmin: false,
  frameIn: 0,
  frameOut: null,
  text: 'Texte',
  status: 'todo',
  deleted: false,
  createdAt: '2026-11-06T10:00:00Z',
  mine: false,
  ...fields,
});

describe('threads', () => {
  const late = c({ frameIn: 500 });
  const early = c({ frameIn: 100, status: 'done' });
  const reply2 = c({ parentId: late.id, status: null, createdAt: '2026-11-06T12:00:00Z' });
  const reply1 = c({ parentId: late.id, status: null, createdAt: '2026-11-06T11:00:00Z' });
  const gone = c({ frameIn: 300, deleted: true, text: '' });
  const list = threads([late, reply2, early, reply1, gone]);

  it('groups replies under their comment, ordered by timecode then date', () => {
    expect(list.map((t) => t.comment.id)).toEqual([early.id, gone.id, late.id]);
    expect(list[2]!.replies.map((r) => r.id)).toEqual([reply1.id, reply2.id]);
  });

  it('counts and filters "Tous / À faire / Fait", ignoring deleted comments', () => {
    expect(counts(list)).toEqual({ all: 2, todo: 1, done: 1, refused: 0 });
    expect(filterThreads(list, 'todo').map((t) => t.comment.id)).toEqual([late.id]);
    expect(filterThreads(list, 'done').map((t) => t.comment.id)).toEqual([early.id]);
    expect(filterThreads(list, 'all')).toHaveLength(3);
  });

  it('makes one marker per live comment, coloured by status', () => {
    const m = markers(list);
    expect(m.map((x) => x.id)).toEqual([early.id, late.id]);
    expect(m[0]!.color).toBe('var(--status-approved)');
    expect(m[1]!.color).toBe('var(--status-changes)');
  });
});

describe('refused revisions', () => {
  const refused = c({ frameIn: 10, status: 'refused' });
  const todo = c({ frameIn: 20, status: 'todo' });
  const list = threads([refused, todo]);

  it('are counted apart from "À faire", filtered and drawn in red', () => {
    expect(counts(list)).toEqual({ all: 2, todo: 1, done: 0, refused: 1 });
    expect(filterThreads(list, 'refused').map((t) => t.comment.id)).toEqual([refused.id]);
    expect(markers(list)[0]!.color).toBe('var(--status-refused)');
  });
});

describe('overlayAt', () => {
  const instant = c({ frameIn: 100 });
  const range = c({ frameIn: 200, frameOut: 400 });
  const inside = c({ frameIn: 250 });
  const list = threads([instant, range, inside]);

  it('shows an instant for two seconds and a range while it lasts', () => {
    expect(overlayAt(list, 99, 25)).toBeNull();
    expect(overlayAt(list, 100, 25)?.comment.id).toBe(instant.id);
    expect(overlayAt(list, 150, 25)?.comment.id).toBe(instant.id);
    expect(overlayAt(list, 151, 25)).toBeNull();
    expect(overlayAt(list, 220, 25)?.comment.id).toBe(range.id);
    expect(overlayAt(list, 260, 25)?.comment.id).toBe(inside.id);
    expect(overlayAt(list, 380, 25)?.comment.id).toBe(range.id);
    expect(overlayAt(list, 401, 25)).toBeNull();
  });
});

describe('excerpt', () => {
  it('flattens and shortens', () => {
    expect(excerpt('a\n\nb')).toBe('a b');
    expect(excerpt('x'.repeat(100), 10)).toBe('xxxxxxxxx…');
  });
});
