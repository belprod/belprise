#!/usr/bin/env python3
"""Generates the media of the demo data (scripts/dev/demo-data.json).

Development only: FFmpeg is used here to *produce test videos*, posters and
thumbnail sheets on the developer's machine. Belprise itself never runs FFmpeg.

Output (cached between runs, keyed by the version spec): data/demo-cache/
{hash}.mp4, {hash}-poster.jpg, {hash}-sprite.jpg, {hash}.json, and
manifest.json, read by scripts/dev/demo-data.php.
"""
import hashlib
import json
import math
import os
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, 'data', 'demo-cache')

RATES = {
    '23.976': ('24000/1001', 24000, 1001, False),
    '24': ('24', 24, 1, False),
    '25': ('25', 25, 1, False),
    '29.97': ('30000/1001', 30000, 1001, False),
    '29.97DF': ('30000/1001', 30000, 1001, True),
    '30': ('30', 30, 1, False),
    '50': ('50', 50, 1, False),
    '59.94DF': ('60000/1001', 60000, 1001, True),
}
PROFILES = {'Constrained Baseline': '42e0', 'Baseline': '4200', 'Main': '4d00', 'High': '6400'}
TILE_W, TILE_H, COLUMNS, MAX_TILES = 256, 144, 10, 200


def source(name, size, rate, duration):
    w, h = size.split('x')
    return {
        'testsrc': f'testsrc=size={size}:rate={rate}:duration={duration}:decimals=2',
        'testsrc2': f'testsrc2=size={size}:rate={rate}:duration={duration}',
        'smptehdbars': f'smptehdbars=size={size}:rate={rate}:duration={duration}',
        'mandelbrot': f'mandelbrot=size={size}:rate={rate}',
        'gradients': f'gradients=size={size}:rate={rate}:duration={duration}:speed=0.03',
        'life': f'life=size={int(w)//4}x{int(h)//4}:rate={rate}:mold=10:ratio=0.1:life_color=#e8c547:death_color=#3a2a5a,scale={size}:flags=neighbor',
        'cellauto': f'cellauto=size={int(w)//4}x{int(h)//4}:rate={rate}:rule=110,scale={size}:flags=neighbor',
    }[name]


def run(cmd):
    subprocess.run(cmd, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)


def make(job):
    key, version, faststart = job
    spec = {k: version.get(k) for k in ('rate', 'tc', 'duration', 'source', 'size')} | {'faststart': faststart, 'v': 1}
    name = hashlib.sha1(json.dumps(spec, sort_keys=True).encode()).hexdigest()[:16]
    cached = os.path.join(OUT, f'{name}.json')
    if os.path.exists(cached):
        with open(cached, encoding='utf-8') as f:
            entry = json.load(f)
        if 'thumb' not in entry:  # cache made before the 640 px thumbnails
            entry['thumb'] = f'{name}-thumb.jpg'
            run(['ffmpeg', '-y', '-loglevel', 'error', '-i', os.path.join(OUT, entry['poster']),
                 '-vf', "scale='min(640,iw)':-2", '-q:v', '4', os.path.join(OUT, entry['thumb'])])
            with open(cached, 'w', encoding='utf-8') as f:
                json.dump(entry, f)
        return key, entry
    entry = render(name, version, faststart)
    with open(cached, 'w', encoding='utf-8') as f:
        json.dump(entry, f)
    return key, entry


def render(key, version, faststart):
    rate_expr, num, den, drop = RATES[version['rate']]
    size = version.get('size', '1920x1080')
    duration = version['duration']
    mp4 = os.path.join(OUT, f'{key}.mp4')

    cmd = ['ffmpeg', '-y', '-loglevel', 'error',
           '-f', 'lavfi', '-i', source(version['source'], size, rate_expr, duration),
           '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
           '-t', str(duration), '-map', '0:v', '-map', '1:a',
           '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '30', '-pix_fmt', 'yuv420p', '-profile:v', 'high',
           '-c:a', 'aac', '-b:a', '96k', '-af', 'volume=0.04',
           '-timecode', version['tc']]
    if faststart:
        cmd += ['-movflags', '+faststart']
    run(cmd + [mp4])

    probe = json.loads(subprocess.run(
        ['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries',
         'stream=profile,level,nb_frames,width,height,bit_rate', '-of', 'json', mp4],
        check=True, capture_output=True).stdout)['streams'][0]
    frames = int(probe['nb_frames'])
    seconds = frames * den / num

    poster_time = seconds * 0.1
    poster = os.path.join(OUT, f'{key}-poster.jpg')
    run(['ffmpeg', '-y', '-loglevel', 'error', '-ss', f'{poster_time:.3f}', '-i', mp4, '-frames:v', '1',
         '-vf', "scale='min(1920,iw)':-2", '-q:v', '3', poster])

    thumb = os.path.join(OUT, f'{key}-thumb.jpg')
    run(['ffmpeg', '-y', '-loglevel', 'error', '-i', poster, '-vf', "scale='min(640,iw)':-2", '-q:v', '4', thumb])

    count = min(MAX_TILES, max(1, math.floor(seconds)))
    interval = seconds / count
    columns = min(count, COLUMNS)
    rows = math.ceil(count / COLUMNS)
    sprite = os.path.join(OUT, f'{key}-sprite.jpg')
    run(['ffmpeg', '-y', '-loglevel', 'error', '-ss', f'{interval / 2:.3f}', '-i', mp4,
         '-vf', f'fps=1/{interval:.6f},scale={TILE_W}:{TILE_H}:force_original_aspect_ratio=decrease,'
                f'pad={TILE_W}:{TILE_H}:(ow-iw)/2:(oh-ih)/2:black,tile={columns}x{rows}',
         '-frames:v', '1', '-q:v', '5', sprite])

    return {
        'file': f'{key}.mp4',
        'poster': f'{key}-poster.jpg',
        'thumb': f'{key}-thumb.jpg',
        'sprite': f'{key}-sprite.jpg',
        'posterFrame': round(poster_time * num / den),
        'spriteMeta': {'columns': columns, 'count': count, 'width': TILE_W, 'height': TILE_H, 'interval': interval},
        'meta': {
            'codec': f"avc1.{PROFILES.get(probe['profile'], '6400')}{int(probe['level']):02x}",
            'width': int(probe['width']), 'height': int(probe['height']),
            'fpsNum': num, 'fpsDen': den, 'dropFrame': drop,
            'startTimecode': version['tc'], 'durationFrames': frames,
            'bitRate': int(probe.get('bit_rate', 0)), 'timecodeSource': 'tmcd',
        },
    }


def main():
    with open(os.path.join(ROOT, 'scripts', 'dev', 'demo-data.json'), encoding='utf-8') as f:
        data = json.load(f)
    os.makedirs(OUT, exist_ok=True)

    jobs = []
    for ci, client in enumerate(data['clients']):
        for pi, project in enumerate(client['projects']):
            for vi, video in enumerate(project['videos']):
                for ri, version in enumerate(video['versions']):
                    jobs.append((f'c{ci}p{pi}v{vi}r{ri}', version, len(jobs) % 2 == 0))

    manifest = {}
    with ThreadPoolExecutor(max_workers=4) as pool:
        for i, (key, entry) in enumerate(pool.map(make, jobs), 1):
            manifest[key] = entry
            print(f'  {i}/{len(jobs)} {key} {entry["meta"]["width"]}x{entry["meta"]["height"]} '
                  f'{entry["meta"]["fpsNum"]}/{entry["meta"]["fpsDen"]} {entry["meta"]["durationFrames"]} images', flush=True)

    with open(os.path.join(OUT, 'manifest.json'), 'w', encoding='utf-8') as f:
        json.dump(manifest, f, indent=1)


if __name__ == '__main__':
    try:
        main()
    except subprocess.CalledProcessError as e:
        sys.exit(f'ffmpeg a échoué : {e.stderr.decode(errors="replace")}')
