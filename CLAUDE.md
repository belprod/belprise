# belprise — consignes pour Claude Code

> Le produit s'appelle **belprise** (anciennement Belvue, domaine pris). Dans tout ce que voient les utilisateurs : « belprise » (logo « belprise. », « Belprise » en début de phrase). Jusqu'à la 2.x, le code s'appelait `Belvue` ; depuis la V3 il s'appelle aussi `Belprise` (espace de noms, cookies, base `belprise.sqlite`).

Logiciel de revue vidéo de belprod (façon frame.io / Vimeo), **à code source disponible (PolyForm Shield) et auto-hébergé** depuis la V3 : chaque installation a un super-administrateur qui crée ses organisations ; chaque organisation a un seul administrateur et son équipe. Pas de bêta ni de forfaits.
**Source de vérité : [CAHIER_DES_CHARGES.md](CAHIER_DES_CHARGES.md)** (version 3.0). Les points marqués « [À décider] » prennent la proposition indiquée tant qu'ils ne sont pas tranchés. En cas de doute ou de contradiction, le cahier des charges l'emporte ; s'il est muet, demander avant d'inventer.

## Méthode de travail
- On avance **phase par phase** : V1 et V2 sont livrées (plans dans `interne/`, hors dépôt public), **V3 dans [PLAN_V3.md](PLAN_V3.md)**. À la fin de chaque phase :
  - lancer les tests ;
  - faire une démonstration rapide de ce qui marche ;
  - faire un commit Git local ;
  - **s'arrêter** pour validation.
- Ne pas ajouter de fonctionnalité hors périmètre (§9 du cahier des charges), même « pour plus tard ».
- Dépôt public sur **GitHub** (à partir de la phase P4, avec un historique neuf) : **jamais de `git push`, d'étiquette publiée ni de version publiée sans l'accord explicite du responsable**, à chaque fois. L'historique actuel (2.x) reste local et n'est jamais publié.
- Code public : aucun secret, aucune adresse réelle, aucune donnée de client dans le code, les tests ou les données de démonstration.

## Stack imposée
- **PHP ≥ 8.2 compatible** (on développe sous 8.3).
  - Pas de framework.
  - Pas de dépendance Composer en production sans accord. PHPUnit est autorisé en dépendance de développement uniquement.
  - Extensions utilisables : `pdo_sqlite`, `mbstring`, `fileinfo`, `openssl`.
- **SQLite** via PDO, mode WAL. Migrations SQL versionnées dans `app/migrations/`, appliquées automatiquement au démarrage.
- **Svelte 5 + Vite + TypeScript** dans `frontend/`, compilé dans `public/assets/`. Le serveur n'exécute jamais Node.
- **FFmpeg facultatif, jamais requis.** Aucun autre binaire externe.
  - Par défaut, l'analyse vidéo (métadonnées via `mp4box.js`, miniatures, planche de miniatures) et l'encodage des versions de lecture (mediabunny) se font **dans le navigateur de l'admin**.
  - FFmpeg / ffprobe ne servent qu'à l'**encodage sur le serveur**, choisi par le super-administrateur quand ils sont présents (inclus dans l'image Docker). Les encodages ne sont lancés que par la tâche planifiée (ou `encode-test.php`), en priorité basse, avec un nombre de fils limité ; jamais pendant une requête HTTP. Seule la détection (`ffmpeg -version`) est permise dans une requête. Sans FFmpeg, tout doit fonctionner.
- **E-mails** : fonction `mail()` de PHP uniquement (aucune bibliothèque), sans image distante ni pixel de suivi.
- **Tâches planifiées** : une seule entrée, `app/bin/cron.php`, lancée par le cron de l'hébergeur, protégée par un verrou.
- **Aucune requête vers un domaine tiers**, ni pour les CDN, ni pour Google Fonts, ni pour des statistiques (application et site vitrine).
  - **Seule exception** : l'avis de nouvelle version. Le serveur (tâche planifiée, une fois par jour au plus) lit `https://belprise.fr/versions.json`, sans rien envoyer d'autre ; jamais depuis le navigateur ; désactivable (`'update_check' => false`).
  - Polices en woff2 dans `public/assets/fonts/`.
  - Bibliothèques empaquetées par Vite.

## Conventions
- **Langues** :
  - code, identifiants et noms de fichiers en anglais ;
  - **interface et messages utilisateur en français** ;
  - commentaires de code en anglais, courts, seulement quand c'est utile.
- **PHP** :
  - `declare(strict_types=1);` ;
  - PSR-12, PSR-4 sous `app/src/` (espace de noms `Belprise\`) ;
  - requêtes PDO préparées uniquement.
- **Arborescence** : `app/` est hors de la racine web, `public/` est la racine web, `data/` reste hors racine web et hors Git.
- **Timecodes** :
  - **toujours stockés et calculés en numéro d'image (entier)** ;
  - conversion SMPTE uniquement à l'affichage et à l'export ;
  - cadence en fraction (`fps_num` / `fps_den`) ;
  - drop-frame 29,97 géré dans le module de conversion.
- **Design** :
  - tokens CSS dans `frontend/src/styles/tokens.css`, reprenant §8 du cahier des charges ;
  - personnalisation client injectée en variables CSS (`--client-bg`, `--client-text`, `--client-accent`) par le PHP dans la coquille HTML ;
  - même principe pour l'identité visuelle de chaque organisation dans l'administration.
- **Vocabulaire** : dans l'interface, on parle de « révision » (pas de « commentaire ») ; le code garde `comment`.

## Sécurité : règles non négociables
- Toute réponse HTTP de l'application porte `X-Robots-Tag` (noindex, noai…), une CSP stricte, `Referrer-Policy: no-referrer`, `X-Content-Type-Options` et `X-Frame-Options`. Seul le site vitrine (hôte distinct) est indexable.
- Toute URL non autorisée (clé fausse, lien désactivé, expiré, média non signé, identifiant d'une autre organisation) renvoie **la même 404 neutre**.
- **Cloisonnement** : chaque requête de l'administration est filtrée par l'organisation de l'utilisateur connecté et vérifiée selon son rôle (Administrateur, Éditeur, Lecteur). Aucune route de contenu n'accepte une session de super-administrateur. **Un seul administrateur par organisation**, vérifié côté serveur.
- **Mode démo** (`'demo' => true`) : le serveur refuse tout envoi de fichier (vidéo, logo) et toute modification des comptes de démo, quel que soit l'appel.
- Suppressions automatiques (archivage, purge, suppression d'organisation) : préavis comme prévu au cahier des charges, journal, mode d'essai sans suppression.
- Les médias sont servis uniquement par PHP, via une URL signée HMAC avec expiration, et gèrent les requêtes Range. `X-Sendfile` est optionnel, activé par la configuration.
- Comptes (utilisateurs, super-administrateur) :
  - mot de passe en Argon2id ;
  - double authentification obligatoire pour le super-administrateur ;
  - jetons d'invitation et de réinitialisation aléatoires, stockés sous forme d'empreinte, à usage unique ;
  - « Mot de passe oublié » : même réponse que l'adresse existe ou non, demandes limitées ; ne désactive jamais la double authentification ;
  - codes de secours de la double authentification stockés sous forme d'empreinte, à usage unique ;
  - session `HttpOnly` / `Secure` / `SameSite=Strict` ;
  - jeton CSRF sur toute requête qui modifie des données ;
  - limitation des tentatives de connexion.
- Les clés de liens sont générées avec `random_int` : 10 lettres minuscules, sans `l` ni `o`.
- Ne jamais logger de mot de passe, de clé de lien, de jeton d'invitation ou d'adresse IP en clair.

## Commandes
| Action | Commande |
|---|---|
| Démarrer Apache + PHP | `docker compose up -d` → http://localhost:8080 |
| Créer la configuration (une fois) | `docker compose exec app php app/bin/init-config.php` |
| Installer PHPUnit (une fois) | `docker compose exec app composer install` |
| Interface avec rechargement à chaud | `npm run dev` |
| Compiler l'interface (application + site vitrine) | `npm run build` (le site reprend `CHANGELOG.md` et `DEPLOIEMENT.md`, et produit `versions.json`) |
| Site vitrine en local | http://localhost:8090 (servi par Apache après `npm run build`) ou `npm run site` (rechargement à chaud) |
| Tests PHP | `docker compose exec app vendor/bin/phpunit` |
| Tests front | `npm test` (Vitest) |
| Vérifier les types | `npm run check` (svelte-check) |
| Données de démo | `docker compose exec app php app/bin/seed.php` |
| Tâches planifiées (archivage automatique…) | `docker compose exec app php app/bin/cron.php` (`--dry-run` : liste sans rien faire) |
| Créer un compte d'exploitation (super-administrateur) | `docker compose exec app php app/bin/create-superadmin.php <e-mail> "<Prénom Nom>"` → `/superadmin` |
| Livrable | `scripts/build-dist.sh` → `dist/belprise-{version}.zip` (guide d'installation : [DEPLOIEMENT.md](DEPLOIEMENT.md)) |
| Image Docker (depuis l'archive) | `scripts/build-image.sh` → `belprise:{version}` (FFmpeg, msmtp, cron intégré ; configuration dans le volume via `BELPRISE_CONFIG`) |
| Vérifier l'environnement d'un serveur | `php app/bin/check-env.php` |
| Vitesse de l'encodage sur le serveur | `php app/bin/encode-test.php` (affichée aussi dans `/superadmin`) |
| Mot de passe oublié (sur le serveur, compte d'organisation ou d'exploitation) | `php app/bin/reset-password.php <e-mail>` |
| Mettre à jour un serveur | `php app/bin/update.php belprise-x.y.z.zip` (`--check` : vérifie l'archive) |
| Installer un serveur neuf | `https://<adresse>/installation` (code dans `data/installation-code.txt` ; Docker : `docker compose exec belprise cat /var/www/belprise/data/installation-code.txt`) |
| Vérifier une installation depuis l'extérieur | `scripts/verifier-production.sh https://app.belprise.fr https://belprise.fr` |

Mettre ce tableau à jour si une commande change.

## Tests obligatoires
- Conversion des timecodes : toutes les cadences du cahier des charges, y compris le drop-frame.
- Génération de l'EDL : comparaison avec un fichier de référence.
- Parsing des URL et des clés.
- Signature et expiration des URL médias.
- Upload découpé : reprise, morceau en double, empreinte invalide.
- Contrôle d'accès de chaque route API (admin / client / visualisation / anonyme), et en V2 pour chaque rôle, pour un utilisateur d'une autre organisation et pour le super-administrateur.
- Quotas, archivage, déclassement et suppression d'organisation : avec une horloge simulée (J0, J+29, J+30), sans jamais supprimer plus que prévu.
- V3 : mot de passe oublié (réponse identique, limitation, lien unique d'une heure), codes de secours, un seul administrateur et transfert du rôle, file d'encodage serveur avec un faux FFmpeg (réussite, échec, coupure, verrou), refus de tout envoi en mode démo, remise à zéro de la démo (horloge simulée).
