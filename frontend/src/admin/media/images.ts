/**
 * Poster (about 10 % into the video) and thumbnail sheet for the timeline
 * preview (at most 200 tiles of 256×144, JPEG), drawn with <video> and
 * <canvas> in the admin's browser (§5.7).
 */
import { secondsToFrame } from '../../lib/timecode';

export const TILE = { width: 256, height: 144 } as const;
export const MAX_TILES = 200;
export const COLUMNS = 10;
const POSTER_MAX_WIDTH = 1920;
const THUMB_WIDTH = 640;

export interface SpriteMeta {
  columns: number;
  count: number;
  width: number;
  height: number;
  /** Seconds between tiles; tile i shows the frame at (i + 0.5) × interval */
  interval: number;
}

export interface GeneratedImages {
  poster: Blob;
  posterFrame: number;
  /** 640 px wide, for the client space cards */
  thumb: Blob;
  sprite: Blob;
  spriteMeta: SpriteMeta;
}

interface Timing {
  durationSeconds: number;
  fpsNum: number;
  fpsDen: number;
}

/** Tile count and spacing: one per second, at most 200. */
export function spriteLayout(durationSeconds: number): { count: number; interval: number } {
  const count = Math.min(MAX_TILES, Math.max(1, Math.floor(durationSeconds)));
  return { count, interval: durationSeconds / count };
}

export async function generateImages(
  src: string,
  timing: Timing,
  onProgress: (ratio: number) => void = () => {},
): Promise<GeneratedImages> {
  const video = await openVideo(src);
  try {
    const duration = Number.isFinite(video.duration) ? video.duration : timing.durationSeconds;
    const posterTime = duration * 0.1;
    await seek(video, posterTime);
    const poster = await capturePoster(video);
    const thumb = await capturePoster(video, THUMB_WIDTH, 0.8);
    const posterFrame = secondsToFrame(posterTime, { num: timing.fpsNum, den: timing.fpsDen });

    const { count, interval } = spriteLayout(duration);
    const rows = Math.ceil(count / COLUMNS);
    const canvas = document.createElement('canvas');
    canvas.width = Math.min(count, COLUMNS) * TILE.width;
    canvas.height = rows * TILE.height;
    const context = canvas.getContext('2d')!;
    context.fillStyle = '#000';
    context.fillRect(0, 0, canvas.width, canvas.height);

    for (let i = 0; i < count; i++) {
      await seek(video, Math.min(duration - 0.001, (i + 0.5) * interval));
      drawContained(context, video, (i % COLUMNS) * TILE.width, Math.floor(i / COLUMNS) * TILE.height, TILE.width, TILE.height);
      onProgress((i + 1) / count);
    }
    const sprite = await toJpeg(canvas, 0.72);

    return {
      poster,
      posterFrame,
      thumb,
      sprite,
      spriteMeta: { columns: Math.min(count, COLUMNS), count, width: TILE.width, height: TILE.height, interval },
    };
  } finally {
    video.removeAttribute('src');
    video.load();
  }
}

/** The frame currently shown by a <video>, as a JPEG (1920 px wide at most by default). */
export async function capturePoster(video: HTMLVideoElement, maxWidth = POSTER_MAX_WIDTH, quality = 0.85): Promise<Blob> {
  const scale = Math.min(1, maxWidth / video.videoWidth);
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(video.videoWidth * scale);
  canvas.height = Math.round(video.videoHeight * scale);
  canvas.getContext('2d')!.drawImage(video, 0, 0, canvas.width, canvas.height);
  return toJpeg(canvas, quality);
}

function openVideo(src: string): Promise<HTMLVideoElement> {
  return new Promise((resolve, reject) => {
    const video = document.createElement('video');
    video.muted = true;
    video.playsInline = true;
    video.preload = 'auto';
    const timer = setTimeout(() => reject(new Error('La vidéo ne se charge pas.')), 60_000);
    video.addEventListener('loadeddata', () => {
      clearTimeout(timer);
      resolve(video);
    }, { once: true });
    video.addEventListener('error', () => {
      clearTimeout(timer);
      reject(new Error('Le navigateur ne parvient pas à décoder cette vidéo.'));
    }, { once: true });
    video.src = src;
  });
}

function seek(video: HTMLVideoElement, time: number): Promise<void> {
  return new Promise((resolve, reject) => {
    if (Math.abs(video.currentTime - time) < 0.001 && video.readyState >= 2) {
      resolve();
      return;
    }
    const timer = setTimeout(() => reject(new Error('La vidéo ne répond pas (positionnement trop long).')), 30_000);
    video.addEventListener('seeked', () => {
      clearTimeout(timer);
      resolve();
    }, { once: true });
    video.currentTime = time;
  });
}

function drawContained(context: CanvasRenderingContext2D, video: HTMLVideoElement, x: number, y: number, w: number, h: number): void {
  const scale = Math.min(w / video.videoWidth, h / video.videoHeight);
  const dw = video.videoWidth * scale;
  const dh = video.videoHeight * scale;
  context.drawImage(video, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh);
}

function toJpeg(canvas: HTMLCanvasElement, quality: number): Promise<Blob> {
  return new Promise((resolve, reject) =>
    canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Image non générée.'))), 'image/jpeg', quality),
  );
}
