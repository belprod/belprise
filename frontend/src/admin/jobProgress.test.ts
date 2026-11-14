import { describe, expect, it } from 'vitest';
import { overallProgress, stepLabel, type ProgressSource, type Stage } from './jobProgress';

const all: Stage[] = ['upload', 'images', 'encode', 'send'];
const job = (over: Partial<ProgressSource>): ProgressSource => ({
  stages: all,
  stage: 'upload',
  state: 'uploading',
  sent: 0,
  size: 100,
  imagesProgress: 0,
  encodeProgress: 0,
  partIndex: 0,
  partCount: 1,
  ...over,
});

describe('overallProgress', () => {
  it('runs from 0 to 1 across upload, images, encoding and sending', () => {
    expect(overallProgress(job({ sent: 0 }))).toBe(0);
    expect(overallProgress(job({ sent: 50 }))).toBe(0.25);
    expect(overallProgress(job({ state: 'finishing' }))).toBe(0.5);
    expect(overallProgress(job({ stage: 'images', state: 'images', imagesProgress: 1 }))).toBeCloseTo(0.55);
    expect(overallProgress(job({ stage: 'encode', state: 'encoding', encodeProgress: 0.5 }))).toBeCloseTo(0.725);
    expect(overallProgress(job({ stage: 'send', sent: 50 }))).toBeCloseTo(0.95);
    expect(overallProgress(job({ stage: 'send', state: 'done' }))).toBe(1);
  });

  it('never goes back while the stages follow each other', () => {
    const steps = [
      job({ state: 'finishing' }),
      job({ stage: 'images', state: 'images' }),
      job({ stage: 'encode', state: 'encoding' }),
      job({ stage: 'encode', state: 'encoding', encodeProgress: 1, sent: 99 }),
      job({ stage: 'send', sent: 0, size: 10 }),
    ];
    const values = steps.map(overallProgress);
    expect([...values].sort((a, b) => a - b)).toEqual(values);
  });

  it('spreads over the stages there are only', () => {
    expect(overallProgress(job({ stages: ['encode', 'send'], stage: 'encode', state: 'encoding', encodeProgress: 1 }))).toBeCloseTo(35 / 45);
    expect(overallProgress(job({ stages: ['upload', 'images'], stage: 'images', state: 'images', imagesProgress: 0.5 }))).toBeCloseTo(52.5 / 55);
  });

  it('shows two steps only', () => {
    expect(stepLabel('upload')).toBe('Envoi');
    expect(['images', 'encode', 'send'].map((s) => stepLabel(s as Stage))).toEqual(Array(3).fill('Optimisation'));
  });
});
