# Contributing to belprise

Thank you for your interest. belprise is source-available under the [PolyForm Shield License 1.0.0](LICENSE): you may use, modify and share it, but not sell it nor build a competing product or service with it. By sending a contribution, you agree that belprod may distribute it under the same license.

## Before you start

- **Security issues**: never in a public issue or pull request — see [SECURITY.md](SECURITY.md).
- **Bugs**: open an issue with the version (`VERSION` file, or `/superadmin`), the hosting (shared hosting, Docker, Apache), what you did, what you expected and what happened. Never paste a link key, an invitation or reset link, a password or a client's video.
- **New features**: open an issue first. belprise deliberately stays small; the scope is set by [CAHIER_DES_CHARGES.md](CAHIER_DES_CHARGES.md), and its §9 lists what is out of scope.

## Rules of the project

They are summed up in [CLAUDE.md](CLAUDE.md) (in French). The essentials:

- **Stack**: PHP ≥ 8.2 without framework and without Composer dependency in production; SQLite through PDO (prepared statements only); Svelte 5 + TypeScript compiled by Vite. The server never runs Node.js.
- **No request to a third-party domain** from the application or the site: no CDN, no web fonts service, no analytics. The only exception is the daily version check of the scheduled task.
- **FFmpeg is optional**: everything must work without it; it only ever runs from the scheduled task.
- **Timecodes** are stored and computed as frame numbers (integers); SMPTE strings only for display and export.
- **Security**: every unauthorized URL returns the same neutral 404; every admin query is limited to the user's organization and checked against the role; never log a password, a link key, a token or a clear IP address.
- **Languages**: code, identifiers and comments in English; everything the user sees in French (the interface says « révision », the code says `comment`).
- **Style**: PSR-12 and `declare(strict_types=1);` for PHP; existing patterns in the front end.

## Development

See the "Développer" section of the [README](README.md). Before a pull request:

```sh
docker compose exec app vendor/bin/phpunit   # PHP tests (also run them with PHP 8.2 if you can)
npm test                                      # front-end tests
npm run check                                 # TypeScript and Svelte
```

A pull request should:

- do one thing, with a clear description of the why;
- add or update tests: access control for every new API route (every role, another organization, the operator, no session), timecode conversions, uploads, deletions with a simulated clock;
- update [CHANGELOG.md](CHANGELOG.md), and the specification or [DEPLOIEMENT.md](DEPLOIEMENT.md) when behaviour or installation changes.
