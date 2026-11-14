export { VERSION_STATUS } from '../lib/status';

export const TIMECODE_SOURCE: Record<string, string> = {
  tmcd: 'piste de timecode',
  xmp: 'métadonnées XMP',
  default: 'aucun timecode dans le fichier',
  manual: 'corrigé à la main',
};
