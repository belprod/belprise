# Third-party software

belprise itself is licensed under the PolyForm Shield License 1.0.0 (see [LICENSE](LICENSE)).
It ships, or is built with, the following third-party software, which keeps its own license.

## Bundled in the web application (`public/assets/`)

The front end is compiled by Vite; these libraries end up, unmodified and minified, in the files served to the browser.

| Library | Version | License | Source |
|---|---|---|---|
| [Svelte](https://svelte.dev) (runtime) | 5.x | MIT | https://github.com/sveltejs/svelte |
| [mediabunny](https://mediabunny.dev) | 1.62.1 | MPL-2.0 | https://github.com/Vanilagy/mediabunny |
| [hls.js](https://github.com/video-dev/hls.js) | 1.7.3 | Apache-2.0 | https://github.com/video-dev/hls.js |
| [mp4box.js](https://github.com/gpac/mp4box.js) | 2.5.x | BSD-3-Clause | https://github.com/gpac/mp4box.js |
| [uqr](https://github.com/unjs/uqr) | 0.1.x | MIT | https://github.com/unjs/uqr |

mediabunny is used unmodified: its source code (MPL-2.0) is available at the address above, for the version given in `package-lock.json`.
Exact versions are pinned in `package-lock.json`.

## Fonts (`frontend/src/fonts/`, served from `public/assets/`)

All under the SIL Open Font License 1.1; the license texts are in [`frontend/src/fonts/licenses/`](frontend/src/fonts/licenses/).

| Font | Copyright |
|---|---|
| Inter | The Inter Project Authors |
| JetBrains Mono | The JetBrains Mono Project Authors |
| Instrument Serif | The Instrument Serif Project Authors |
| Poetsen One | Pablo Impallari and Rodrigo Fuenzalida (Reserved Font Name "Poetsen") |

## Docker image (`ghcr.io/belprod/belprise`)

The image is based on the official `php:8.3-apache` image (Debian) and adds these Debian packages, distributed under their own licenses (GPL, LGPL and others); their source code is available from Debian (https://sources.debian.org):

- **FFmpeg** (`ffmpeg`, with libx264): encoding of the playback versions on the server;
- **msmtp** (`msmtp-mta`): hands PHP's `mail()` to an SMTP server;
- **ca-certificates**.

## Development only (not distributed)

PHPUnit (BSD-3-Clause), Vite, Vitest, svelte-check and TypeScript (MIT, Apache-2.0) are used to build and test belprise; they are not part of the archive nor of the image.
