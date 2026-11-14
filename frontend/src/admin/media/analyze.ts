/**
 * Master analysis in the admin's browser (no FFmpeg, §5.7): container,
 * codec, resolution, exact frame rate and start timecode.
 *
 * Only box headers and the "moov" box are read, never the video data, so a
 * 50 GB file is analysed in a few reads wherever its "moov" sits.
 */
import { createFile, MP4BoxBuffer, type Movie } from 'mp4box';
import { framesPerDay, nominalFps, STANDARD_RATES, supportsDropFrame, timecodeToFrames } from '../../lib/timecode';
import type { ByteSource } from './source';

export class AnalysisError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'AnalysisError';
  }
}

export interface MediaInfo {
  name: string;
  size: number;
  codec: string;
  codecLabel: string;
  width: number;
  height: number;
  fpsNum: number;
  fpsDen: number;
  dropFrame: boolean;
  variableFrameRate: boolean;
  durationSeconds: number;
  durationFrames: number;
  startFrame: number;
  timecodeSource: 'tmcd' | 'xmp' | 'default';
  bitRate: number;
  warnings: string[];
  /**
   * H.264 / HEVC: browsers decode it (review, playback versions). ProRes and MXF
   * masters are not: a playback file in H.264 is joined to them (§5.7).
   */
  playable: boolean;
  container: 'mp4' | 'mov' | 'mxf';
}

const PRORES = new Set(['apch', 'apcn', 'apcs', 'apco', 'ap4h', 'ap4x']);

/**
 * Any accepted master. MXF cannot be read here: its metadata will come from the
 * playback file joined to it, so only its name and size are known.
 */
export async function analyzeMaster(source: ByteSource): Promise<MediaInfo> {
  if (/\.mxf$/i.test(source.name)) {
    return {
      name: source.name, size: source.size, codec: 'mxf', codecLabel: 'MXF', width: 0, height: 0, fpsNum: 0, fpsDen: 1,
      dropFrame: false, variableFrameRate: false, durationSeconds: 0, durationFrames: 0, startFrame: 0,
      timecodeSource: 'default', bitRate: 0, warnings: [], playable: false, container: 'mxf',
    };
  }

  return analyzeMp4(source);
}

interface TopBox {
  type: string;
  start: number;
  size: number;
  headerSize: number;
}

const MAX_LONG_SIDE = 4096;
const MAX_SHORT_SIDE = 2160;
const HIGH_BITRATE = 30_000_000;
const MAX_METADATA_BOX = 256 * 1024 * 1024;

const CODEC_NAMES: Record<string, string> = {
  hvc1: 'HEVC (H.265)',
  hev1: 'HEVC (H.265)',
  av01: 'AV1',
  vp09: 'VP9',
  vp08: 'VP8',
  apch: 'ProRes 422 HQ',
  apcn: 'ProRes 422',
  apcs: 'ProRes 422 LT',
  apco: 'ProRes 422 Proxy',
  ap4h: 'ProRes 4444',
  mp4v: 'MPEG-4 Part 2',
  avc1: 'H.264',
  avc3: 'H.264',
};

const H264_PROFILES: Record<number, string> = { 66: 'Baseline', 77: 'Main', 88: 'Extended', 100: 'High', 110: 'High 10', 122: 'High 4:2:2', 244: 'High 4:4:4' };

export async function analyzeMp4(source: ByteSource): Promise<MediaInfo> {
  if (!/\.(mp4|mov)$/i.test(source.name)) {
    throw new AnalysisError('Formats acceptés : .mp4, .mov (H.264, HEVC, ProRes) et .mxf.');
  }
  const boxes = await listTopLevelBoxes(source);
  if (boxes[0]?.type !== 'ftyp') {
    throw new AnalysisError('Ce fichier n’est pas un MP4.');
  }
  const moov = boxes.find((box) => box.type === 'moov');
  if (!moov) {
    throw new AnalysisError('Fichier MP4 incomplet : ses métadonnées (« moov ») sont introuvables.');
  }

  // Feed mp4box every box in file order: whole metadata boxes, mdat headers only.
  // allowInvalidBoxTypes: editing software writes QuickTime metadata atoms named
  // "©TIM", "©TSC"… in udta, even in "mp42" files; mp4box would reject the
  // whole moov for that one non-ASCII character.
  const file = createFile(false, undefined, { allowInvalidBoxTypes: true });
  let movie: Movie | null = null;
  let parseError = '';
  file.onReady = (info) => (movie = info);
  file.onError = (_module, message) => (parseError = message);

  const metadata: { start: number; bytes: Uint8Array }[] = [];
  for (const box of boxes) {
    const whole = box.type !== 'mdat' && box.size <= MAX_METADATA_BOX;
    const buffer = await source.read(box.start, whole ? box.size : box.headerSize);
    if (whole && box.type !== 'free' && box.type !== 'skip') {
      metadata.push({ start: box.start, bytes: new Uint8Array(buffer) });
    }
    file.appendBuffer(MP4BoxBuffer.fromArrayBuffer(buffer, box.start));
  }
  file.flush();

  const info = movie as Movie | null;
  if (!info) {
    throw new AnalysisError(`Ce MP4 n’a pas pu être lu${parseError ? ` (${parseError})` : ''}.`);
  }
  // mp4box does not know the ProRes sample entries: their track is listed as
  // "metadata", without its video box; the size comes from the track header.
  const video = info.videoTracks[0] ?? info.tracks.find((t) => PRORES.has(t.codec.slice(0, 4)));
  const size = video?.video ?? (video ? { width: video.track_width, height: video.track_height } : null);
  if (!video || !size || !size.width || !size.height) {
    throw new AnalysisError('Ce fichier ne contient aucune piste vidéo.');
  }

  const fourcc = video.codec.slice(0, 4);
  const h264 = fourcc === 'avc1' || fourcc === 'avc3';
  const hevc = fourcc === 'hvc1' || fourcc === 'hev1';
  if (!h264 && !hevc && !PRORES.has(fourcc)) {
    const name = CODEC_NAMES[fourcc] ?? fourcc;
    throw new AnalysisError(`Codec ${name} non pris en charge : H.264, HEVC ou ProRes.`);
  }

  const width = size.width;
  const height = size.height;
  if (Math.max(width, height) > MAX_LONG_SIDE || Math.min(width, height) > MAX_SHORT_SIDE) {
    throw new AnalysisError(`Résolution trop élevée (${width}×${height}) : 4K au maximum.`);
  }

  const warnings: string[] = [];
  const trak = file.getTrackById(video.id);
  const stts = trak.mdia.minf.stbl.stts;
  const rate = detectFrameRate(video.timescale, stts.sample_counts, stts.sample_deltas);
  if (rate.variable) {
    warnings.push('Cadence variable détectée : la cadence la plus fréquente a été retenue. Vérifiez-la.');
  }
  const durationSeconds = video.duration / video.timescale;
  const durationFrames = rate.variable ? Math.round((durationSeconds * rate.num) / rate.den) : video.nb_samples;

  const bitRate = Math.round(video.bitrate);
  if (bitRate > HIGH_BITRATE && !PRORES.has(fourcc)) {
    warnings.push(
      `Débit élevé (${Math.round(bitRate / 1e6)} Mb/s) : la lecture en ligne risque de saccader. Visez environ 25 Mb/s.`,
    );
  }

  let start: { frame: number; dropFrame: boolean; source: MediaInfo['timecodeSource'] } | null = await readTmcd(
    file,
    metadata,
    source,
    rate,
    warnings,
  );
  if (!start) {
    const xmp = findXmpStartTimecode(metadata.map((m) => m.bytes));
    if (xmp) {
      const dropFrame = (xmp.dropFrame ?? false) && supportsDropFrame(rate);
      const frame = timecodeToFrames(xmp.timecode, { ...rate, dropFrame });
      if (frame === null) {
        warnings.push(`Timecode XMP illisible (${xmp.timecode}) : 00:00:00:00 retenu.`);
      } else {
        start = { frame, dropFrame, source: 'xmp' };
      }
    }
  }
  start ??= { frame: 0, dropFrame: false, source: 'default' };

  return {
    name: source.name,
    size: source.size,
    codec: video.codec,
    codecLabel: h264 ? h264Label(video.codec) : (CODEC_NAMES[fourcc] ?? fourcc),
    width,
    height,
    fpsNum: rate.num,
    fpsDen: rate.den,
    dropFrame: start.dropFrame,
    variableFrameRate: rate.variable,
    durationSeconds,
    durationFrames,
    startFrame: start.frame,
    timecodeSource: start.source,
    bitRate,
    warnings,
    playable: h264 || hevc,
    container: /\.mov$/i.test(source.name) ? 'mov' : 'mp4',
  };
}

/** Top-level boxes, from their headers only. */
export async function listTopLevelBoxes(source: ByteSource): Promise<TopBox[]> {
  const boxes: TopBox[] = [];
  let offset = 0;
  while (offset < source.size) {
    if (boxes.length > 10_000 || source.size - offset < 8) {
      throw new AnalysisError('Ce fichier n’est pas un MP4 valide.');
    }
    const header = new DataView(await source.read(offset, Math.min(16, source.size - offset)));
    const type = String.fromCharCode(header.getUint8(4), header.getUint8(5), header.getUint8(6), header.getUint8(7));
    if (boxes.length === 0 && type !== 'ftyp') {
      throw new AnalysisError('Ce fichier n’est pas un MP4.');
    }
    let size = header.getUint32(0);
    let headerSize = 8;
    if (size === 1) {
      if (header.byteLength < 16) throw new AnalysisError('Ce fichier n’est pas un MP4 valide.');
      size = Number(header.getBigUint64(8));
      headerSize = 16;
    } else if (size === 0) {
      size = source.size - offset;
    }
    if (!/^[\x20-\x7e]{4}$/.test(type) || size < headerSize) {
      throw new AnalysisError(boxes.length === 0 ? 'Ce fichier n’est pas un MP4.' : 'Ce fichier MP4 est endommagé.');
    }
    if (offset + size > source.size) {
      throw new AnalysisError('Ce fichier MP4 est incomplet (copie interrompue ?).');
    }
    boxes.push({ type, start: offset, size, headerSize });
    offset += size;
  }
  return boxes;
}

/** Most frequent sample duration → frame rate, snapped to a standard rate. */
export function detectFrameRate(
  timescale: number,
  counts: number[],
  deltas: number[],
): { num: number; den: number; dropFrame: false; variable: boolean } {
  const totals = new Map<number, number>();
  let samples = 0;
  deltas.forEach((delta, i) => {
    const count = counts[i] ?? 0;
    totals.set(delta, (totals.get(delta) ?? 0) + count);
    samples += count;
  });
  const [delta, count] = [...totals.entries()].sort((a, b) => b[1] - a[1])[0] ?? [0, 0];
  if (delta <= 0 || timescale <= 0) {
    throw new AnalysisError('Cadence illisible dans ce fichier.');
  }
  // One odd last sample is common; more than that is a variable frame rate.
  const variable = samples - count > 1 && count / samples < 0.98;

  const value = timescale / delta;
  const standard = STANDARD_RATES.find((r) => Math.abs(r.num / r.den - value) / value < 0.0005);
  if (standard) {
    return { num: standard.num, den: standard.den, dropFrame: false, variable };
  }
  const divisor = gcd(timescale, delta);
  return { num: timescale / divisor, den: delta / divisor, dropFrame: false, variable };
}

interface TmcdTrak {
  mdia: { minf: { stbl: { stsd: { entries: { type: string; start: number; size: number }[] }; stco?: { chunk_offsets: number[] }; co64?: { chunk_offsets: number[] } } } };
}

/** QuickTime timecode track: sample entry flags + first sample (frame counter). */
async function readTmcd(
  file: ReturnType<typeof createFile>,
  metadata: { start: number; bytes: Uint8Array }[],
  source: ByteSource,
  rate: { num: number; den: number },
  warnings: string[],
): Promise<{ frame: number; dropFrame: boolean; source: 'tmcd' } | null> {
  const traks = (file.moov?.traks ?? []) as unknown as TmcdTrak[];
  for (const trak of traks) {
    const stbl = trak.mdia.minf.stbl;
    const entry = stbl.stsd.entries[0];
    if (entry?.type !== 'tmcd') continue;

    const holder = metadata.find((m) => entry.start >= m.start && entry.start + entry.size <= m.start + m.bytes.byteLength);
    const offset = (stbl.stco ?? stbl.co64)?.chunk_offsets[0];
    if (!holder || entry.size < 33 || offset === undefined || offset + 4 > source.size) continue;

    // SampleEntry header (8) + reserved (6) + data_reference_index (2) + reserved (4), then flags.
    const raw = new DataView(holder.bytes.buffer, holder.bytes.byteOffset + entry.start - holder.start, entry.size);
    const flags = raw.getUint32(20);
    const framesPerSecond = raw.getUint8(32);
    const dropFrame = (flags & 0x1) === 1 && supportsDropFrame(rate);
    if (framesPerSecond !== nominalFps(rate)) {
      warnings.push(`La piste de timecode est à ${framesPerSecond} i/s, la vidéo à ${nominalFps(rate)} i/s : vérifiez le timecode.`);
    }

    const counter = new DataView(await source.read(offset, 4)).getUint32(0);
    const day = framesPerDay({ ...rate, dropFrame });
    return { frame: counter % day, dropFrame, source: 'tmcd' };
  }
  return null;
}

/** xmpDM:startTimecode from XMP packets in the metadata boxes (Premiere, Resolve, Media Encoder). */
export function findXmpStartTimecode(buffers: Uint8Array[]): { timecode: string; dropFrame: boolean | null } | null {
  const decoder = new TextDecoder('latin1');
  for (const bytes of buffers) {
    const text = decoder.decode(bytes);
    const found = parseXmpStartTimecode(text);
    if (found) return found;
  }
  return null;
}

export function parseXmpStartTimecode(xml: string): { timecode: string; dropFrame: boolean | null } | null {
  // The XMP standard spells it "startTimecode" (Media Encoder); Premiere and Resolve write "startTimeCode".
  const open = /<xmpDM:startTimecode\b/i.exec(xml)?.index ?? -1;
  if (open < 0) return null;
  const rest = xml.slice(open);
  const selfClosing = /^<xmpDM:startTimecode\b[^>]*\/>/i.exec(rest);
  const close = /<\/xmpDM:startTimecode>/i.exec(rest)?.index ?? -1;
  const segment = selfClosing ? selfClosing[0] : rest.slice(0, close < 0 ? 4000 : close);

  const value = /xmpDM:timeValue\s*=\s*"([^"]+)"/.exec(segment)?.[1] ?? /<xmpDM:timeValue>([^<]+)</.exec(segment)?.[1];
  if (!value) return null;
  const format = /xmpDM:timeFormat\s*=\s*"([^"]+)"/.exec(segment)?.[1] ?? /<xmpDM:timeFormat>([^<]+)</.exec(segment)?.[1] ?? '';
  const dropFrame = /NonDrop/i.test(format) ? false : /Drop/i.test(format) ? true : null;
  return { timecode: value.trim(), dropFrame };
}

function h264Label(codec: string): string {
  const match = /^avc[13]\.([0-9a-f]{2})[0-9a-f]{2}([0-9a-f]{2})$/i.exec(codec);
  if (!match) return 'H.264';
  const profile = H264_PROFILES[parseInt(match[1]!, 16)];
  const level = parseInt(match[2]!, 16) / 10;
  return `H.264${profile ? ` ${profile}` : ''} ${level.toFixed(1).replace('.', ',')}`;
}

function gcd(a: number, b: number): number {
  return b === 0 ? a : gcd(b, a % b);
}

/**
 * A ProRes / MXF master with the H.264 file joined to it for playback (§5.7):
 * both must have the same frames. The master keeps its own metadata (ProRes);
 * an MXF master takes the playback file's (cadence, timecode, size), which
 * stay editable afterwards.
 */
export function withPlaybackFile(master: MediaInfo, playback: MediaInfo): MediaInfo {
  if (!playback.playable) {
    throw new AnalysisError('La version de lecture doit être en H.264 ou HEVC (.mp4 ou .mov).');
  }
  if (master.container === 'mxf') {
    return { ...playback, name: master.name, size: master.size, codec: 'mxf', codecLabel: 'MXF', container: 'mxf', playable: false, bitRate: 0 };
  }
  const sameRate = master.fpsNum * playback.fpsDen === playback.fpsNum * master.fpsDen;
  if (!sameRate || Math.abs(master.durationFrames - playback.durationFrames) > 1) {
    throw new AnalysisError(
      `La version de lecture ne correspond pas au master : ${playback.durationFrames} images à ${(playback.fpsNum / playback.fpsDen).toFixed(3).replace(/\.?0+$/, '')} i/s, ` +
        `contre ${master.durationFrames} images à ${(master.fpsNum / master.fpsDen).toFixed(3).replace(/\.?0+$/, '')} i/s. Exportez-la depuis la même séquence.`,
    );
  }
  return master;
}
