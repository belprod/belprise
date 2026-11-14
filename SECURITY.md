# Security policy

belprise keeps clients' unreleased videos: security reports are taken seriously and answered quickly.

## Reporting a vulnerability

**Do not open a public issue.** Report privately, either:

- with GitHub's **"Report a vulnerability"** button (Security tab of this repository), or
- by e-mail to **contact@belprise.fr**, with "Sécurité" in the subject.

Please include the affected version, the hosting type (shared hosting, Docker, Apache), the steps to reproduce and the impact you see. Do not include real link keys, tokens or client content; test on your own installation, never on someone else's.

You will get an answer within 5 working days. Once a fix is published, the release notes credit you if you wish.

## Supported versions

Only the latest 3.x release receives security fixes. Installations update with one command (`php app/bin/update.php belprise-x.y.z.zip`) or a new Docker image; `/superadmin` shows when a new version is out.

## Scope

In scope: the application (`app/`, `public/`, `frontend/`), the installer, the update command, the Docker image and the site (`site/`).

Out of scope: the configuration of your own server or hosting (TLS, mail, backups), and vulnerabilities of third-party software that belprise uses unmodified (please report them upstream; see [THIRD_PARTY.md](THIRD_PARTY.md)).
