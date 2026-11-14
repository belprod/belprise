/**
 * SMPTE timecode ↔ frame number. Timecodes are always stored as frame
 * numbers; this module converts for display and input only.
 * Same algorithm as app/src/Timecode.php (shared test fixture).
 */

export interface FrameRate {
  num: number;
  den: number;
  /** Drop-frame counting (29.97 and 59.94 only) */
  dropFrame: boolean;
}

/** Frame rates offered in the admin (§5.7). */
export const STANDARD_RATES: readonly { label: string; num: number; den: number }[] = [
  { label: '23,976', num: 24000, den: 1001 },
  { label: '24', num: 24, den: 1 },
  { label: '25', num: 25, den: 1 },
  { label: '29,97', num: 30000, den: 1001 },
  { label: '30', num: 30, den: 1 },
  { label: '50', num: 50, den: 1 },
  { label: '59,94', num: 60000, den: 1001 },
  { label: '60', num: 60, den: 1 },
];

/** Frames per timecode second (24 for 23.976, 30 for 29.97…). */
export function nominalFps(rate: Pick<FrameRate, 'num' | 'den'>): number {
  return Math.round(rate.num / rate.den);
}

/** Drop-frame exists for 29.97 and 59.94 only. */
export function supportsDropFrame(rate: Pick<FrameRate, 'num' | 'den'>): boolean {
  const fps = nominalFps(rate);
  return rate.den === 1001 && (fps === 30 || fps === 60);
}

function dropPerMinute(rate: FrameRate): number {
  return rate.dropFrame && supportsDropFrame(rate) ? nominalFps(rate) / 15 : 0;
}

/** Number of frames in 24 hours of timecode. */
export function framesPerDay(rate: FrameRate): number {
  const fps = nominalFps(rate);
  const drop = dropPerMinute(rate);
  return fps * 86400 - drop * (24 * 60 - 24 * 6);
}

const pad = (n: number): string => String(n).padStart(2, '0');

export function framesToTimecode(frames: number, rate: FrameRate): string {
  const fps = nominalFps(rate);
  const drop = dropPerMinute(rate);
  const day = framesPerDay(rate);
  let n = ((Math.trunc(frames) % day) + day) % day;

  if (drop > 0) {
    const perTenMinutes = fps * 600 - drop * 9;
    const perMinute = fps * 60 - drop;
    const tens = Math.floor(n / perTenMinutes);
    const rest = n % perTenMinutes;
    n += drop * 9 * tens + (rest > drop ? drop * Math.floor((rest - drop) / perMinute) : 0);
  }

  const ff = n % fps;
  const totalSeconds = Math.floor(n / fps);
  const ss = totalSeconds % 60;
  const mm = Math.floor(totalSeconds / 60) % 60;
  const hh = Math.floor(totalSeconds / 3600);
  return `${pad(hh)}:${pad(mm)}:${pad(ss)}${drop > 0 ? ';' : ':'}${pad(ff)}`;
}

/** "HH:MM:SS:FF" (or ";" / "." before the frames) → frame number; null if invalid. */
export function timecodeToFrames(timecode: string, rate: FrameRate): number | null {
  const match = /^(\d{1,2}):(\d{2}):(\d{2})[:;.](\d{2})$/.exec(timecode.trim());
  if (!match) return null;
  const [hh, mm, ss, ff] = match.slice(1).map(Number) as [number, number, number, number];
  const fps = nominalFps(rate);
  const drop = dropPerMinute(rate);
  if (hh > 23 || mm > 59 || ss > 59 || ff >= fps) return null;
  // Labels skipped by drop-frame counting do not exist.
  if (drop > 0 && ss === 0 && ff < drop && mm % 10 !== 0) return null;

  const totalMinutes = hh * 60 + mm;
  return ((hh * 3600 + mm * 60 + ss) * fps + ff) - drop * (totalMinutes - Math.floor(totalMinutes / 10));
}

export function frameToSeconds(frame: number, rate: Pick<FrameRate, 'num' | 'den'>): number {
  return (frame * rate.den) / rate.num;
}

/**
 * Frame shown at a media time. Browsers report frame times rounded down to
 * the microsecond: at 24000/1001, frame 547 starts at 22.814458 s, which is
 * 546.99999 frames. A margin of 1/1000 of a frame absorbs that.
 */
export function secondsToFrame(seconds: number, rate: Pick<FrameRate, 'num' | 'den'>): number {
  return Math.floor((seconds * rate.num) / rate.den + 1e-3);
}

/** "25 i/s", "29,97 i/s DF" */
export function formatRate(rate: FrameRate): string {
  const value = rate.num / rate.den;
  const text = Number.isInteger(value) ? String(value) : value.toFixed(3).replace(/0+$/, '').replace('.', ',');
  return `${text} i/s${rate.dropFrame && supportsDropFrame(rate) ? ' DF' : ''}`;
}
