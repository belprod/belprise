import { describe, expect, it } from 'vitest';
import { qualitiesFor } from './playback';

describe('qualitiesFor', () => {
  it('produces one 720p at 3 Mbit/s, never above the master', () => {
    expect(qualitiesFor(2160)).toEqual([{ height: 720, bitrate: 3_000_000 }]);
    expect(qualitiesFor(1080)).toEqual([{ height: 720, bitrate: 3_000_000 }]);
    expect(qualitiesFor(720)).toEqual([{ height: 720, bitrate: 3_000_000 }]);
    expect(qualitiesFor(541)).toEqual([{ height: 540, bitrate: 3_000_000 }]);
  });
});
