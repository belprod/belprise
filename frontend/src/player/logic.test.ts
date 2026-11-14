import { describe, expect, it } from 'vitest';
import { secondsToFrame } from '../lib/timecode';
import { actionForKey, frameTime, nextShuttle, nextSpeed, spriteTile } from './logic';

const key = (k: string, mods: Partial<{ shiftKey: boolean; metaKey: boolean; ctrlKey: boolean; altKey: boolean }> = {}) =>
  actionForKey({ key: k, shiftKey: false, metaKey: false, ctrlKey: false, altKey: false, ...mods });

describe('shortcuts (§5.1)', () => {
  it('maps every key of the specification', () => {
    expect(key(' ')).toBe('toggle');
    expect(key('j')).toBe('reverse');
    expect(key('k')).toBe('stop');
    expect(key('l')).toBe('forward');
    expect(key('ArrowLeft')).toBe('previousFrame');
    expect(key('ArrowRight')).toBe('nextFrame');
    expect(key('ArrowLeft', { shiftKey: true })).toBe('backSecond');
    expect(key('ArrowRight', { shiftKey: true })).toBe('forwardSecond');
    expect(key('i')).toBe('markIn');
    expect(key('o')).toBe('markOut');
    expect(key('c')).toBe('comment');
    expect(key('f')).toBe('fullscreen');
  });

  it('accepts caps lock but leaves browser shortcuts alone', () => {
    expect(key('L')).toBe('forward');
    expect(key('f', { metaKey: true })).toBeNull();
    expect(key('c', { ctrlKey: true })).toBeNull();
    expect(key('ArrowLeft', { altKey: true })).toBeNull();
    expect(key('x')).toBeNull();
  });
});

describe('frame positions', () => {
  it.each([
    ['25', { num: 25, den: 1 }],
    ['23.976', { num: 24000, den: 1001 }],
    ['29.97', { num: 30000, den: 1001 }],
    ['59.94', { num: 60000, den: 1001 }],
  ])('%s: seeking to frameTime(n) shows frame n', (_, rate) => {
    for (let frame = 0; frame < 10_000; frame++) {
      expect(secondsToFrame(frameTime(frame, rate), rate)).toBe(frame);
    }
  });
});

describe('JKL shuttle', () => {
  it('doubles the speed on repeated presses, within limits', () => {
    expect(nextShuttle(0, 'forward')).toBe(1);
    expect(nextShuttle(1, 'forward')).toBe(2);
    expect(nextShuttle(4, 'forward')).toBe(8);
    expect(nextShuttle(8, 'forward')).toBe(8);
    expect(nextShuttle(0, 'reverse')).toBe(-1);
    expect(nextShuttle(-2, 'reverse')).toBe(-4);
    expect(nextShuttle(-4, 'reverse')).toBe(-4);
  });

  it('changes direction at normal speed', () => {
    expect(nextShuttle(4, 'reverse')).toBe(-1);
    expect(nextShuttle(-2, 'forward')).toBe(1);
  });

  it('cycles the speed button', () => {
    expect([nextSpeed(0.5), nextSpeed(1), nextSpeed(1.5), nextSpeed(2), nextSpeed(4)]).toEqual([1, 1.5, 2, 0.5, 0.5]);
  });
});

describe('spriteTile', () => {
  const sprite = { columns: 10, count: 120, width: 256, height: 144, interval: 1 };

  it('locates the tile of a time', () => {
    expect(spriteTile(0, sprite)).toEqual({ x: 0, y: 0 });
    expect(spriteTile(9.99, sprite)).toEqual({ x: 9 * 256, y: 0 });
    expect(spriteTile(10, sprite)).toEqual({ x: 0, y: 144 });
    expect(spriteTile(57.4, sprite)).toEqual({ x: 7 * 256, y: 5 * 144 });
  });

  it('stays inside the sheet', () => {
    expect(spriteTile(500, sprite)).toEqual({ x: 9 * 256, y: 11 * 144 });
    expect(spriteTile(-3, sprite)).toEqual({ x: 0, y: 0 });
  });
});
