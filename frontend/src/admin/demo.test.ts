import { describe, expect, it } from 'vitest';
import { untilReset } from './demo';

describe('untilReset', () => {
  const now = Date.parse('2026-11-15T10:00:00Z');

  it('says hours and minutes, then minutes, then less than a minute', () => {
    expect(untilReset('2026-11-15T12:14:30Z', now)).toBe('2\u00a0h\u00a014');
    expect(untilReset('2026-11-15T13:05:00Z', now)).toBe('3\u00a0h\u00a005');
    expect(untilReset('2026-11-15T10:14:00Z', now)).toBe('14\u00a0min');
    expect(untilReset('2026-11-15T10:00:40Z', now)).toBe('moins d’une minute');
    expect(untilReset('2026-11-15T09:59:00Z', now)).toBe('moins d’une minute');
  });
});
