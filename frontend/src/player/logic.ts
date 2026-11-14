/** Pure player logic (tested): shortcuts, frame positions, JKL shuttle, thumbnail tiles. */
import type { FrameRate } from '../lib/timecode';

export type PlayerAction =
  | 'toggle'
  | 'reverse'
  | 'stop'
  | 'forward'
  | 'previousFrame'
  | 'nextFrame'
  | 'backSecond'
  | 'forwardSecond'
  | 'markIn'
  | 'markOut'
  | 'comment'
  | 'fullscreen';

export interface KeyInput {
  key: string;
  shiftKey: boolean;
  metaKey: boolean;
  ctrlKey: boolean;
  altKey: boolean;
}

/** §5.1 keyboard shortcuts. */
export function actionForKey(event: KeyInput): PlayerAction | null {
  if (event.metaKey || event.ctrlKey || event.altKey) return null;
  switch (event.key) {
    case ' ':
      return event.shiftKey ? null : 'toggle';
    case 'ArrowLeft':
      return event.shiftKey ? 'backSecond' : 'previousFrame';
    case 'ArrowRight':
      return event.shiftKey ? 'forwardSecond' : 'nextFrame';
  }
  if (event.shiftKey) return null;
  switch (event.key.toLowerCase()) {
    case 'j':
      return 'reverse';
    case 'k':
      return 'stop';
    case 'l':
      return 'forward';
    case 'i':
      return 'markIn';
    case 'o':
      return 'markOut';
    case 'c':
      return 'comment';
    case 'f':
      return 'fullscreen';
    default:
      return null;
  }
}

/** Elements where typing must never trigger a shortcut. */
export function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

/** Media time in the middle of a frame: seeking there always shows that frame. */
export function frameTime(frame: number, rate: Pick<FrameRate, 'num' | 'den'>): number {
  return ((frame + 0.5) * rate.den) / rate.num;
}

export const MAX_SHUTTLE = 8;
export const MAX_REVERSE = 4;

/**
 * JKL shuttle: L plays forward, each new L doubles the speed; J does the
 * same backwards (negative); K stops. 0 means stopped.
 */
export function nextShuttle(current: number, action: 'forward' | 'reverse'): number {
  if (action === 'forward') {
    return current > 0 ? Math.min(current * 2, MAX_SHUTTLE) : 1;
  }
  return current < 0 ? Math.max(current * 2, -MAX_REVERSE) : -1;
}

export const SPEEDS = [0.5, 1, 1.5, 2] as const;

export function nextSpeed(current: number): number {
  const index = SPEEDS.findIndex((s) => s === current);
  return SPEEDS[(index + 1) % SPEEDS.length] ?? 1;
}

export interface SpriteInfo {
  url: string;
  columns: number;
  count: number;
  width: number;
  height: number;
  interval: number;
}

/** Tile of the thumbnail sheet shown for a media time. */
export function spriteTile(seconds: number, sprite: Omit<SpriteInfo, 'url'>): { x: number; y: number } {
  const index = Math.min(sprite.count - 1, Math.max(0, Math.floor(seconds / sprite.interval)));
  return { x: (index % sprite.columns) * sprite.width, y: Math.floor(index / sprite.columns) * sprite.height };
}

export function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}
