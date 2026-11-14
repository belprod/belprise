import { describe, expect, it } from 'vitest';
import fixture from '../../../tests/fixtures/timecode.json';
import {
  formatRate,
  framesPerDay,
  framesToTimecode,
  frameToSeconds,
  secondsToFrame,
  timecodeToFrames,
  type FrameRate,
} from './timecode';

const rates = fixture.rates as Record<string, FrameRate>;
const rate = (name: string): FrameRate => rates[name]!;

describe('timecode — shared reference cases', () => {
  it.each(fixture.cases as [string, number, string][])('%s: frame %i ↔ %s', (name, frames, tc) => {
    expect(framesToTimecode(frames, rate(name))).toBe(tc);
    expect(timecodeToFrames(tc, rate(name))).toBe(frames);
  });

  it.each(fixture.wrap as [string, number, string][])('%s: frame %i wraps to %s', (name, frames, tc) => {
    expect(framesToTimecode(frames, rate(name))).toBe(tc);
  });

  it.each(fixture.parse as [string, string, number][])('%s: parses %s', (name, tc, frames) => {
    expect(timecodeToFrames(tc, rate(name))).toBe(frames);
  });

  it.each(fixture.invalid as [string, string][])('%s: rejects "%s"', (name, tc) => {
    expect(timecodeToFrames(tc, rate(name))).toBeNull();
  });
});

describe('timecode — round trip', () => {
  it.each(Object.keys(rates))('%s: every frame of the first and last 11 minutes, and samples', (name) => {
    const r = rate(name);
    const day = framesPerDay(r);
    const span = Math.round((r.num / r.den) * 660);
    const frames = [
      ...Array.from({ length: span }, (_, i) => i),
      ...Array.from({ length: span }, (_, i) => day - 1 - i),
      ...Array.from({ length: 20000 }, (_, i) => Math.floor((i * 7919 * 131) % day)),
    ];
    for (const frame of frames) {
      const tc = framesToTimecode(frame, r);
      if (timecodeToFrames(tc, r) !== frame) {
        throw new Error(`${name}: frame ${frame} → ${tc} → ${timecodeToFrames(tc, r)}`);
      }
    }
  });

  it('counts frames per day', () => {
    expect(framesPerDay(rate('25'))).toBe(2160000);
    expect(framesPerDay(rate('29.97DF'))).toBe(2589408);
    expect(framesPerDay(rate('59.94DF'))).toBe(5178816);
  });
});

describe('time ↔ frame', () => {
  it('maps media time to the displayed frame', () => {
    const r = rate('23.976');
    for (let frame = 0; frame < 5000; frame++) {
      expect(secondsToFrame(frameToSeconds(frame, r), r)).toBe(frame);
    }
    expect(secondsToFrame(1, rate('25'))).toBe(25);
    // Frame start times rounded down to the microsecond, as browsers report them.
    for (const name of ['23.976', '29.97', '59.94']) {
      const r = rate(name);
      for (let frame = 0; frame < 200_000; frame += 7) {
        const reported = Math.floor(frameToSeconds(frame, r) * 1e6) / 1e6;
        expect(secondsToFrame(reported, r)).toBe(frame);
      }
    }
    expect(secondsToFrame(0.0399, rate('25'))).toBe(0);
  });

  it('formats rates in French', () => {
    expect(formatRate(rate('23.976'))).toBe('23,976 i/s');
    expect(formatRate(rate('29.97DF'))).toBe('29,97 i/s DF');
    expect(formatRate(rate('25'))).toBe('25 i/s');
  });
});
