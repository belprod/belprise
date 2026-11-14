import { api, upload } from '../../lib/api';
import type { Version } from '../types';
import type { MediaInfo } from './analyze';
import type { GeneratedImages } from './images';

/** What the server stores from an analysis (see Belprise\Media\MediaMeta). */
export function toMeta(info: MediaInfo): Record<string, unknown> {
  return {
    codec: info.codec,
    width: info.width,
    height: info.height,
    fpsNum: info.fpsNum,
    fpsDen: info.fpsDen,
    dropFrame: info.dropFrame,
    startFrame: info.startFrame,
    durationFrames: info.durationFrames,
    bitRate: info.bitRate,
    timecodeSource: info.timecodeSource,
  };
}

export function uploadImages(versionId: number, images: Partial<GeneratedImages>): Promise<Version> {
  const form = new FormData();
  if (images.poster) {
    form.append('poster', images.poster, 'poster.jpg');
    form.append('posterFrame', String(images.posterFrame ?? 0));
  }
  if (images.thumb) {
    form.append('thumb', images.thumb, 'thumb.jpg');
  }
  if (images.sprite && images.spriteMeta) {
    form.append('sprite', images.sprite, 'sprite.jpg');
    form.append('spriteMeta', JSON.stringify(images.spriteMeta));
  }
  return upload<Version>(`/api/admin/versions/${versionId}/images`, form);
}

export function versionFileUrl(versionId: number): string {
  return `/api/admin/versions/${versionId}/file`;
}

export function listVersions(videoId: number): Promise<Version[]> {
  return api<Version[]>('GET', `/api/admin/videos/${videoId}/versions`);
}
