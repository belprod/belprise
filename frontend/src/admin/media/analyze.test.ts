import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { framesToTimecode } from '../../lib/timecode';
import { AnalysisError, analyzeMaster, analyzeMp4, detectFrameRate, parseXmpStartTimecode, withPlaybackFile } from './analyze';
import { fingerprint } from './fingerprint';
import { bufferSource } from './source';

const fixture = (name: string): Uint8Array => new Uint8Array(readFileSync(`${__dirname}/../../../../tests/fixtures/media/${name}`));
const analyze = (name: string, bytes = fixture(name)) => analyzeMp4(bufferSource(bytes, name));

describe('analyzeMp4 — tmcd timecode track', () => {
  it.each([
    ['tc25-01000000.mp4', 25, 1, false, '01:00:00:00', 50],
    ['tc23976-10000000.mp4', 24000, 1001, false, '10:00:00:00', 48],
    ['tc2997df-01000000.mp4', 30000, 1001, true, '01:00:00;00', 60],
    ['tc2997ndf-00595800.mp4', 30000, 1001, false, '00:59:58:00', 60],
    ['tc5994df-00095900.mp4', 60000, 1001, true, '00:09:59;00', 120],
    ['tc24-23595900.mp4', 24, 1, false, '23:59:59:00', 48],
    ['tc30-00000000.mp4', 30, 1, false, '00:00:00:00', 60],
  ])('%s', async (name, num, den, dropFrame, timecode, frames) => {
    const info = await analyze(name);
    expect(info).toMatchObject({ fpsNum: num, fpsDen: den, dropFrame, timecodeSource: 'tmcd', durationFrames: frames });
    expect(framesToTimecode(info.startFrame, { num, den, dropFrame })).toBe(timecode);
    expect(info).toMatchObject({ width: 320, height: 180, variableFrameRate: false });
    expect(info.codec).toMatch(/^avc1\./);
    expect(info.codecLabel).toMatch(/^H\.264 High/);
    expect(info.warnings).toEqual([]);
  });
});

/** Appends a QuickTime-style udta (©TIM, ©TSC, ©TSZ: written by editing software) at the end of moov. */
function withQuickTimeUdta(bytes: Uint8Array): Uint8Array {
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  let offset = 0;
  while (offset < bytes.byteLength && String.fromCharCode(...bytes.subarray(offset + 4, offset + 8)) !== 'moov') {
    offset += view.getUint32(offset);
  }
  const atom = (type: number[], payload: number[]): number[] => {
    const size = 8 + payload.length;
    return [(size >>> 24) & 255, (size >>> 16) & 255, (size >>> 8) & 255, size & 255, ...type, ...payload];
  };
  const text = (value: string): number[] => [0, value.length, 0, 0, ...new TextEncoder().encode(value)];
  const children = [
    ...atom([0xa9, 0x54, 0x49, 0x4d], text('10:00:00:00')), // ©TIM
    ...atom([0xa9, 0x54, 0x53, 0x43], text('25')), // ©TSC
    ...atom([0xa9, 0x54, 0x53, 0x5a], text('1')), // ©TSZ
  ];
  const udta = atom([0x75, 0x64, 0x74, 0x61], children);
  const moovSize = view.getUint32(offset);
  const out = new Uint8Array(bytes.byteLength + udta.length);
  out.set(bytes.subarray(0, offset + moovSize));
  out.set(udta, offset + moovSize);
  out.set(bytes.subarray(offset + moovSize), offset + moovSize + udta.length);
  new DataView(out.buffer).setUint32(offset, moovSize + udta.length);
  return out;
}

describe('analyzeMp4 — QuickTime metadata atoms', () => {
  it('reads a file whose moov ends with ©TIM / ©TSC / ©TSZ (exports of editing software)', async () => {
    const info = await analyze('tc25-01000000.mp4', withQuickTimeUdta(fixture('tc25-01000000.mp4')));
    expect(info).toMatchObject({ fpsNum: 25, fpsDen: 1, timecodeSource: 'tmcd', durationFrames: 50, width: 320, height: 180 });
  });
});

describe('analyzeMp4 — no timecode track', () => {
  it('reads a file whose moov is at the end, and defaults to 00:00:00:00', async () => {
    const info = await analyze('notc50.mp4');
    expect(info).toMatchObject({ fpsNum: 50, fpsDen: 1, startFrame: 0, timecodeSource: 'default', durationFrames: 100 });
    expect(info.durationSeconds).toBeCloseTo(2, 5);
  });

  it('falls back to the XMP start timecode', async () => {
    const xmp = new TextEncoder().encode(
      '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF><rdf:Description>' +
        '<xmpDM:startTimeCode xmpDM:timeFormat="50Timecode" xmpDM:timeValue="10:00:00:00"/>' +
        '</rdf:Description></rdf:RDF></x:xmpmeta>',
    );
    const base = fixture('notc50.mp4');
    // Top-level "uuid" box (XMP UUID: be7acfcb-97a9-42e8-9c71-999491e3afac), as Premiere writes it.
    const uuid = Uint8Array.from([0xbe, 0x7a, 0xcf, 0xcb, 0x97, 0xa9, 0x42, 0xe8, 0x9c, 0x71, 0x99, 0x94, 0x91, 0xe3, 0xaf, 0xac]);
    const box = new Uint8Array(8 + 16 + xmp.length);
    new DataView(box.buffer).setUint32(0, box.length);
    box.set(new TextEncoder().encode('uuid'), 4);
    box.set(uuid, 8);
    box.set(xmp, 24);
    const bytes = new Uint8Array(base.length + box.length);
    bytes.set(base);
    bytes.set(box, base.length);

    const info = await analyze('notc50.mp4', bytes);
    expect(info.timecodeSource).toBe('xmp');
    expect(info.startFrame).toBe(1_800_000);
  });
});

describe('analyzeMp4 — refusals', () => {
  it('accepts HEVC (playable by browsers) and MOV files', async () => {
    const hevc = await analyze('hevc25.mp4');
    expect([hevc.codecLabel, hevc.playable, hevc.container]).toEqual(['HEVC (H.265)', true, 'mp4']);
    const mov = await analyzeMp4(bufferSource(fixture('tc25-01000000.mp4'), 'master.mov'));
    expect([mov.playable, mov.container, mov.durationFrames]).toEqual([true, 'mov', 50]);
  });

  it('refuses other extensions', async () => {
    await expect(analyzeMp4(bufferSource(fixture('tc25-01000000.mp4'), 'master.avi'))).rejects.toThrow('Formats acceptés');
  });

  it('knows an MXF master only by its name and size (metadata from its playback file)', async () => {
    const info = await analyzeMaster(bufferSource(new Uint8Array([6, 14, 43, 52, 0, 0, 0, 0]), 'Plan_A001.mxf'));
    expect([info.container, info.codec, info.playable, info.size]).toEqual(['mxf', 'mxf', false, 8]);
  });

  it('refuses a file that is not an MP4', async () => {
    const error = await analyzeMp4(bufferSource(new TextEncoder().encode('just some text, not a video'), 'x.mp4')).catch((e) => e);
    expect(error).toBeInstanceOf(AnalysisError);
    expect((error as Error).message).toBe('Ce fichier n’est pas un MP4.');
  });

  it('refuses a truncated file', async () => {
    const bytes = fixture('notc50.mp4');
    await expect(analyze('notc50.mp4', bytes.slice(0, bytes.length - 500))).rejects.toThrow('incomplet');
  });
});

describe('detectFrameRate', () => {
  it('reduces and snaps sample durations to standard rates', () => {
    expect(detectFrameRate(24000, [100], [1001])).toMatchObject({ num: 24000, den: 1001, variable: false });
    expect(detectFrameRate(90000, [100], [3754])).toMatchObject({ num: 24000, den: 1001 });
    expect(detectFrameRate(12800, [100], [512])).toMatchObject({ num: 25, den: 1 });
    expect(detectFrameRate(600, [100], [20])).toMatchObject({ num: 30, den: 1 });
  });

  it('ignores one odd last sample but flags a variable rate', () => {
    expect(detectFrameRate(12800, [99, 1], [512, 300]).variable).toBe(false);
    expect(detectFrameRate(12800, [50, 50], [512, 256]).variable).toBe(true);
  });

  it('keeps an unusual rate as an exact fraction', () => {
    expect(detectFrameRate(1000, [10], [80])).toMatchObject({ num: 25, den: 2 });
  });
});

describe('parseXmpStartTimecode', () => {
  it('reads attribute and element forms, and the drop-frame format', () => {
    // Adobe Media Encoder: "startTimecode" (lowercase c, as in the XMP standard), attributes on several lines.
    expect(
      parseXmpStartTimecode('<xmpDM:startTimecode\n    xmpDM:timeFormat="25Timecode"\n    xmpDM:timeValue="00:07:59:15"/>\n<xmpDM:altTimecode xmpDM:timeValue="00:00:00:00"/>'),
    ).toEqual({ timecode: '00:07:59:15', dropFrame: null });
    expect(parseXmpStartTimecode('<xmpDM:startTimeCode xmpDM:timeFormat="2997DropTimecode" xmpDM:timeValue="01:00:00;00"/>')).toEqual({
      timecode: '01:00:00;00',
      dropFrame: true,
    });
    expect(
      parseXmpStartTimecode(
        '<xmpDM:startTimeCode rdf:parseType="Resource"><xmpDM:timeFormat>2997NonDropTimecode</xmpDM:timeFormat>' +
          '<xmpDM:timeValue>00:59:58:00</xmpDM:timeValue></xmpDM:startTimeCode>',
      ),
    ).toEqual({ timecode: '00:59:58:00', dropFrame: false });
    expect(parseXmpStartTimecode('<xmpDM:altTimecode xmpDM:timeValue="01:00:00:00"/>')).toBeNull();
  });
});

describe('fingerprint', () => {
  it('matches the PHP formula (UploadsController::fingerprint)', async () => {
    const abc = createHash('sha256').update('abc').digest('hex');
    const expected = createHash('sha256').update(`Fichier é.mp4\n3\n${abc}\n${abc}`, 'utf8').digest('hex');
    expect(await fingerprint(bufferSource(new TextEncoder().encode('abc'), 'Fichier é.mp4'))).toBe(expected);
  });
});

describe('ProRes', () => {
  it('reads a real ProRes .mov: size, frame rate, duration and start timecode (mp4box lists it as metadata)', async () => {
    const info = await analyze('prores-tc25-01000000.mov');
    expect([info.codec, info.codecLabel, info.width, info.height, info.fpsNum, info.fpsDen, info.durationFrames, info.playable, info.container]).toEqual([
      'apch', 'ProRes 422 HQ', 64, 64, 25, 1, 5, false, 'mov',
    ]);
    expect([info.startFrame, info.timecodeSource]).toEqual([90000, 'tmcd']);
  });
});

describe('withPlaybackFile', () => {
  it('keeps a ProRes master, takes the MXF metadata from its playback file, refuses a mismatch', async () => {
    const playback = await analyze('tc25-01000000.mp4');
    const prores = { ...playback, codec: 'apch', codecLabel: 'ProRes 422 HQ', playable: false, container: 'mov' as const, name: 'M.mov' };
    expect(withPlaybackFile(prores, playback)).toBe(prores);

    const mxf = await analyzeMaster(bufferSource(new Uint8Array([6, 14, 43, 52]), 'Plan.mxf'));
    const merged = withPlaybackFile(mxf, playback);
    expect([merged.codec, merged.container, merged.fpsNum, merged.durationFrames, merged.startFrame, merged.name]).toEqual(['mxf', 'mxf', 25, 50, playback.startFrame, 'Plan.mxf']);

    expect(() => withPlaybackFile({ ...prores, durationFrames: 60 }, playback)).toThrow('ne correspond pas au master : 50 images à 25 i/s, contre 60 images à 25 i/s');
    expect(() => withPlaybackFile(prores, { ...playback, playable: false })).toThrow('H.264 ou HEVC');
  });
});
