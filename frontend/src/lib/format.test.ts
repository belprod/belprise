import { describe, expect, it } from 'vitest';
import { formatBytes, formatLongDate, formatRelative, formatSeconds, formatSpeed } from './format';

describe('format', () => {
  it('gives the Paris calendar day of an instant', () => {
    expect(formatLongDate('2026-12-06T09:00:00Z')).toBe('6 décembre 2026');
    expect(formatLongDate('2026-12-31T23:30:00Z')).toBe('1 janvier 2027');
  });

  it('formats sizes in French decimal units', () => {
    expect(formatBytes(52_000_000_000)).toBe('52,0 Go');
    expect(formatBytes(38_215_000_000)).toBe('38,2 Go');
    expect(formatBytes(850_000_000)).toBe('850,0 Mo');
    expect(formatBytes(1200)).toBe('1 Ko');
    expect(formatBytes(0)).toBe('0 Ko');
    expect(formatBytes(4_000_000_000_000)).toBe('4,0 To');
    expect(formatSpeed(12_345_678)).toBe('12,3 Mo/s');
  });

  it('formats durations', () => {
    expect(formatSeconds(35)).toBe('35 s');
    expect(formatSeconds(260)).toBe('4 min 20 s');
    expect(formatSeconds(3900)).toBe('1 h 05 min');
  });
});

describe('formatRelative', () => {
  const now = new Date('2026-11-06T15:00:00');
  it('says how long ago', () => {
    expect(formatRelative(new Date('2026-11-06T14:59:40').toISOString(), now)).toBe('à l’instant');
    expect(formatRelative(new Date('2026-11-06T14:55:00').toISOString(), now)).toBe('il y a 5 min');
    expect(formatRelative(new Date('2026-11-06T12:30:00').toISOString(), now)).toBe('il y a 2 h');
    expect(formatRelative(new Date('2026-11-05T20:00:00').toISOString(), now)).toBe('hier');
    expect(formatRelative(new Date('2026-10-30T20:00:00').toISOString(), now)).toBe('30 oct. 2026');
  });
});
