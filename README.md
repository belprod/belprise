# belprise

**Revue vidéo à l'image près, sur votre propre serveur.** Vous envoyez une vidéo à votre client par un simple lien ; il la regarde dans un lecteur à ses couleurs, pose ses révisions sur un instant ou une plage, valide la version, télécharge le master. Vous gardez toutes les versions d'une vidéo sur une seule page, et exportez les révisions en marqueurs pour DaVinci Resolve.

- **Site** : [belprise.fr](https://belprise.fr) · **Démo** : [demo.belprise.fr](https://demo.belprise.fr/admin)
- **Installer** : [DEPLOIEMENT.md](DEPLOIEMENT.md) (hébergement mutualisé, Docker, serveur Apache)
- **Licence** : [PolyForm Shield 1.0.0](LICENSE) — utilisation, modification et partage libres, y compris pour votre travail payé ; interdit de le revendre ou d'en faire un service concurrent.

> *In English: belprise is a self-hosted, frame-accurate video review tool (French interface). Clients review by link, comment on a frame or a range, approve versions; reviews export as DaVinci Resolve markers. PHP + SQLite, no external service. Source-available under the PolyForm Shield 1.0.0 license. Contributions: see [CONTRIBUTING.md](CONTRIBUTING.md).*

## Ce que fait belprise

- **Revue à l'image près** : timecode SMPTE calé sur celui du master (drop-frame compris), révisions sur un instant ou une plage, statuts À faire / Fait / Refusé, réponses, validation par le client.
- **Versions** V1, V2, V3… d'une même vidéo, chacune avec ses révisions ; export EDL des marqueurs pour Resolve.
- **Liens** sans compte client : espace client, lien de revue, lien de visualisation seule, collections pour les prospects. Mot de passe, expiration, désactivation, régénération.
- **Masters** MP4 / MOV (H.264, HEVC, ProRes) et MXF, envoyés par le navigateur, en morceaux, avec reprise après une coupure.
- **Versions de lecture** 720p en HLS, préparées **dans le navigateur** de l'administrateur ou **sur le serveur** avec FFmpeg, au choix.
- **Organisations** cloisonnées, chacune avec un administrateur, son équipe (Éditeurs, Lecteurs) et son identité visuelle ; un super-administrateur gère les organisations et leurs quotas sans jamais voir leurs contenus.
- **Privé par conception** : aucune page indexée ni lisible par les robots d'IA, aucune requête vers un service tiers, médias servis par des adresses signées qui expirent, double authentification, codes de secours.

## Installer

Trois façons, détaillées dans [DEPLOIEMENT.md](DEPLOIEMENT.md) :

| | |
|---|---|
| **Hébergement mutualisé** (cPanel) | Déposez l'archive de la [dernière version](https://github.com/belprod/belprise/releases), puis ouvrez `https://<adresse>/installation` |
| **Docker** (VPS, NAS) | `docker compose up -d` avec [`docker/compose.yml`](docker/compose.yml) (image `ghcr.io/belprod/belprise`, FFmpeg inclus) |
| **Serveur Apache** | Apache 2.4, PHP 8.2+ avec `pdo_sqlite`, `mbstring`, `fileinfo`, `openssl` |

L'assistant d'installation vérifie le serveur, crée le compte d'exploitation (double authentification obligatoire) et peut ouvrir la première organisation. Les mises à jour se font en une commande (`php app/bin/update.php belprise-x.y.z.zip`), ou par une nouvelle image Docker.

## Développer

PHP 8.2+ sans framework, SQLite (WAL), interface Svelte 5 + TypeScript compilée par Vite. Prérequis : Docker et Node.js LTS.

```sh
npm install && npm run build                          # interface compilée dans public/assets/
docker compose up -d                                  # Apache + PHP sur http://localhost:8080
docker compose exec app composer install              # PHPUnit (tests uniquement)
docker compose exec app php app/bin/init-config.php   # app/config.php avec une clé secrète
open http://localhost:8080/admin                      # premier lancement : création du compte
```

En local, utilisez Chrome, Firefox ou Brave : le cookie de session est toujours `Secure`, ce que Safari refuse sur `http://localhost`.

| Action | Commande |
|---|---|
| Interface avec rechargement à chaud | `npm run dev` |
| Tests PHP | `docker compose exec app vendor/bin/phpunit` |
| Tests front, types | `npm test`, `npm run check` |
| Données de démonstration | `docker compose exec app php app/bin/seed.php` |
| Tâches planifiées | `docker compose exec app php app/bin/cron.php` (`--dry-run` : sans rien faire) |
| Archive et image Docker | `scripts/build-dist.sh`, `scripts/build-image.sh` |
| Site vitrine | http://localhost:8090 après `npm run build`, ou `npm run site` |

Le [cahier des charges](CAHIER_DES_CHARGES.md) décrit tout le comportement attendu ; [CLAUDE.md](CLAUDE.md) résume les règles du projet (sécurité, conventions). L'interface est en français ; le code et ses commentaires sont en anglais.

```
app/          code PHP hors racine web (src/ : espace de noms Belprise\, migrations/, bin/)
public/       racine web : index.php, .htaccess, assets/ (généré)
frontend/     sources Svelte, styles, polices
site/         site vitrine belprise.fr
docker/       image de développement et image publiée (Dockerfile.release)
tests/        tests PHPUnit et fichiers de test (les tests front sont à côté du code)
data/         base SQLite, médias, sessions : créé au premier lancement, hors Git
```

## Contribuer, signaler une faille

- Propositions et corrections : [CONTRIBUTING.md](CONTRIBUTING.md).
- **Faille de sécurité** : jamais dans un ticket public, voir [SECURITY.md](SECURITY.md).
- Historique des versions : [CHANGELOG.md](CHANGELOG.md). Bibliothèques et polices tierces : [THIRD_PARTY.md](THIRD_PARTY.md).

belprise est créé par [belprod](https://belprod.fr).
