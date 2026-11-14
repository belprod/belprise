import { describe, expect, it } from 'vitest';
import { titleFromName } from './formats';

describe('titleFromName', () => {
  it('turns a file name into a video title', () => {
    expect(titleFromName('Film_manifeste_V3.mp4')).toBe('Film manifeste V3');
    expect(titleFromName('Chapitre0_Markcut.mov')).toBe('Chapitre0 Markcut');
    expect(titleFromName('  .mp4')).toBe('Sans titre');
    expect(titleFromName('a'.repeat(200) + '.mxf')).toHaveLength(160);
  });
});
