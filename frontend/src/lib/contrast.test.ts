import { describe, expect, it } from 'vitest';
import { AA_TEXT, contrastRatio, formatRatio, relativeLuminance } from './contrast';

describe('contrast', () => {
  it('matches the WCAG reference values', () => {
    expect(relativeLuminance('#000000')).toBe(0);
    expect(relativeLuminance('#ffffff')).toBe(1);
    expect(contrastRatio('#000000', '#ffffff')).toBe(21);
    expect(contrastRatio('#ffffff', '#ffffff')).toBe(1);
  });

  it('is symmetric', () => {
    expect(contrastRatio('#1c1915', '#f3ede4')).toBeCloseTo(contrastRatio('#f3ede4', '#1c1915'), 10);
  });

  it('puts #767676 just above and #777777 just below AA on white', () => {
    expect(contrastRatio('#767676', '#ffffff')).toBeGreaterThanOrEqual(AA_TEXT);
    expect(contrastRatio('#777777', '#ffffff')).toBeLessThan(AA_TEXT);
  });

  it('formats without rounding up past a threshold', () => {
    expect(formatRatio(4.4999)).toBe('4,4:1');
    expect(formatRatio(21)).toBe('21,0:1');
  });

  it('rejects malformed colours', () => {
    expect(() => relativeLuminance('red')).toThrow();
  });
});
