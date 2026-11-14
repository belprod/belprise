/** Masters accepted by belprise (§5.7): what the file pickers offer. */
export const MASTER_ACCEPT = '.mp4,.mov,.mxf,video/mp4,video/quicktime,application/mxf';

/** "Film_manifeste-V3.mp4" → "Film manifeste-V3": the title of a video created from a dropped file. */
export function titleFromName(name: string): string {
  return name.replace(/\.[^.]+$/, '').replace(/_+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160) || 'Sans titre';
}
