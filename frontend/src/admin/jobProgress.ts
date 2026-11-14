/**
 * One progress bar for a whole import: the master upload, then the
 * "optimisation" (images, playback encoding, sending its file), each
 * weighted by its usual share of the time.
 */
export type Stage = 'upload' | 'images' | 'encode' | 'send';

const WEIGHTS: Record<Stage, number> = { upload: 50, images: 5, encode: 35, send: 10 };

export interface ProgressSource {
  stages: Stage[];
  stage: Stage;
  state: string;
  sent: number;
  size: number;
  imagesProgress: number;
  encodeProgress: number;
  partIndex: number;
  partCount: number;
}

function stageRatio(job: ProgressSource): number {
  const sent = job.size > 0 ? job.sent / job.size : 0;
  switch (job.stage) {
    case 'upload':
      return job.state === 'finishing' ? 1 : sent;
    case 'images':
      return job.imagesProgress;
    case 'encode':
      return job.encodeProgress;
    case 'send':
      return job.partCount > 0 ? (job.partIndex + sent) / job.partCount : 0;
  }
}

/** 0 to 1 over every stage of the job. */
export function overallProgress(job: ProgressSource): number {
  if (job.state === 'done') return 1;
  const total = job.stages.reduce((sum, s) => sum + WEIGHTS[s], 0);
  const index = job.stages.indexOf(job.stage);
  if (total === 0 || index < 0) return 0;
  const before = job.stages.slice(0, index).reduce((sum, s) => sum + WEIGHTS[s], 0);
  const ratio = Math.min(1, Math.max(0, stageRatio(job)));
  return (before + WEIGHTS[job.stage] * ratio) / total;
}

/** What the admin sees: sending the master, or the optimisation that follows. */
export function stepLabel(stage: Stage): string {
  return stage === 'upload' ? 'Envoi' : 'Optimisation';
}
