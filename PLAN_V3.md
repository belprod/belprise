# belprise V3 — plan d'action

> Statut : **décisions du 7 et du 14 novembre 2026**. P0 et P1 validées ; P2 validée ; P3 validée ; P3b réalisée (15 novembre 2026), en attente de validation.
> Point de départ : version 2.1.1 (commit `ec6249f`). Version visée : **3.0.0**.

## 1. Le changement en une phrase

belprise devient un **logiciel à code source disponible, que chacun installe sur son serveur**.
- Chaque installation a un **super-administrateur**, qui crée autant d'**organisations** que nécessaire.
- Chaque organisation a **un administrateur** et son équipe.
- L'encodage des versions de lecture se fait **dans le navigateur ou sur le serveur**, au choix.
- Le site vitrine présente l'outil, suit les versions et propose les téléchargements.
- Un assistant guide l'installation.
- Une démo publique, sur `demo.belprise.fr`, est remise à zéro toutes les 6 h.

**On repart de zéro** : aucune donnée de la 2.x n'est reprise. L'installation o2switch actuelle sera effacée puis réinstallée en 3.0.

### Ce qui disparaît

- La **bêta** : offre de 100 Go, formulaire de demande d'accès (`/inscription`), table `beta_requests`, e-mail à l'exploitant.
- Les **forfaits nommés** (Découverte, Studio, Pro…). Le super-administrateur fixe librement le **quota** de chaque organisation : un nombre de Go, ou « sans limite ».
- La limite de **10 utilisateurs** par organisation.
- L'**historique des migrations 0001 à 0017** : il est remplacé par un schéma de départ unique pour la 3.0. On ne passe pas de la 2.x à la 3.0 : on réinstalle.
- Ce qui est propre au service hébergé de belprod : textes légaux, cahier des charges de la bêta.

### Ce qui reste (et s'adapte)

- **Super-administrateur** (`/superadmin`) :
  - créé par l'assistant d'installation, avec double authentification obligatoire ;
  - crée les organisations et invite leur administrateur ;
  - fixe leur quota, avec un déclassement sur 30 jours si le quota baisse sous l'espace utilisé ;
  - peut demander la suppression d'une organisation (exécutée à J+30) ;
  - peut réinitialiser l'accès d'un administrateur ;
  - **ne voit aucun contenu**.
- **Organisations cloisonnées** : tout le code de cloisonnement et les tests d'accès sont conservés.
- Projets, vidéos, versions, révisions, liens clients, collections, archivage automatique, envoi découpé avec reprise, lecture HLS, mise à jour en une commande.

### Ce qui est nouveau

- **Un seul administrateur par organisation**, qui gère son équipe (Éditeurs, Lecteurs). Il peut **transférer son rôle** à un membre de l'équipe, et devient alors Éditeur.
- **« Mot de passe oublié ? »** sur l'écran de connexion de l'administration (lien par e-mail, 1 h), en plus de la réinitialisation par l'administrateur, ou par le super-administrateur pour un administrateur.
- **Double authentification** facultative pour les équipes, avec des codes de secours. Une organisation peut la rendre obligatoire. Elle reste obligatoire pour le super-administrateur.
- **Choix de l'encodage**, réglé par le super-administrateur : dans le navigateur, ou sur le serveur si FFmpeg est présent.
- **Avis de nouvelle version** dans `/superadmin`.
- **Démo publique** remise à zéro toutes les 6 h.
- **Publication sur GitHub**, sous licence PolyForm Shield, avec une archive `.zip` et une image Docker à chaque version.

## 2. Décisions

| # | Sujet | Décision |
|---|---|---|
| D1 | Licence | **PolyForm Shield 1.0.0**. Tout le monde, sociétés comprises, peut installer, modifier et partager belprise, y compris pour son travail payé. Il est interdit de le revendre ou d'en faire un produit ou service concurrent. On parle de « code source disponible ». Les bibliothèques gardent leur licence : mediabunny (MPL-2.0), hls.js (Apache-2.0), polices (OFL). |
| D2 | Code | **GitHub**, dépôt public, historique neuf à partir de la 3.0. Envois (`git push`) avec votre accord, à chaque version. |
| D3 | Nom interne | `Belvue` devient **`Belprise`** partout : espace de noms, cookies, base `belprise.sqlite`, clés du navigateur. |
| D4 | Double authentification | Facultative pour les équipes, avec des codes de secours ; réglage « obligatoire » par organisation. Obligatoire pour le super-administrateur. |
| D5 | Mot de passe oublié | Lien sur l'écran de connexion (e-mail, 1 h, usage unique, même réponse que l'adresse existe ou non, demandes limitées), et réinitialisation par l'administrateur ou le super-administrateur. Le super-administrateur lui-même ne passe que par la commande `reset-password.php` sur le serveur. L'e-mail ne remet jamais la double authentification à zéro : code de secours, ou commande `disable-totp.php`. |
| D6 | Encodage sur le serveur | D'abord la version de lecture seulement (720p à 3 Mbit/s). Puis, en P3b, miniatures et métadonnées des ProRes et MXF. |
| D7 | Formats livrés | Archive `.zip` (hébergement mutualisé) et **image Docker publiée** sur `ghcr.io`, avec FFmpeg inclus. |
| D8 | Nouvelle version | Le **serveur** consulte une fois par jour `https://belprise.fr/versions.json`, par la tâche planifiée, puis l'annonce dans `/superadmin`. Aucune requête du navigateur ; désactivable dans la configuration. C'est la seule exception à « aucune requête vers un domaine tiers ». |
| D9 | Langue | Interface en français. Code et documents pour contributeurs en anglais. Guides d'installation en français. |
| D10 | Stockage | Quota libre par organisation (Go, ou sans limite), fixé par le super-administrateur. Alerte à 80 %, envois bloqués à 100 %, déclassement sur 30 jours inchangé. |
| D11 | Installation actuelle | Effacée et réinstallée en 3.0 (P7). |
| D12 | Démo | `demo.belprise.fr` : organisation et clients fictifs, démonstration complète, remise à l'état initial toutes les 6 h. **Aucun envoi de vidéo ni de fichier.** Vidéos tirées des films libres de la Blender Foundation (CC BY), avec le crédit affiché. |
| D13 | Organisations | Plusieurs organisations par installation, créées par le super-administrateur. **Un seul administrateur par organisation.** |
| D14 | Réglage de l'encodage | Un réglage pour toute l'installation, fait par le super-administrateur : c'est son serveur qui travaille. |

## 3. Phases

À la fin de chaque phase : tests, démonstration, commit local, puis arrêt pour validation.

### P0 — Cadre (documents seulement)

- Cahier des charges **3.0** : décisions D1 à D14.
- CLAUDE.md :
  - FFmpeg devient facultatif (encodage sur le serveur uniquement, jamais requis) ;
  - dépôt GitHub, avec des envois soumis à accord ;
  - exception D8 ;
  - bêta retirée ;
  - nouveau nom interne.

### P1 — Repartir propre

- **Schéma de départ unique** (`0001_schema.sql`), qui reprend l'état actuel :
  - sans `beta_requests` ;
  - avec un quota en octets (ou sans limite) à la place du forfait ;
  - avec les codes de secours et le réglage de double authentification de l'organisation.
- Renommage `Belvue` → `Belprise` (D3).
- Retirer la bêta : `BetaController`, `BetaRequests`, page `/inscription`, e-mail à l'exploitant.
- Retirer `Plans` :
  - le super-administrateur saisit le quota en Go ou choisit « sans limite » ;
  - le déclassement (`Downgrade`) se déclenche quand le quota baisse sous l'espace utilisé ;
  - plus de limite de 10 utilisateurs.
- **Un administrateur par organisation** :
  - l'invitation propose seulement Éditeur ou Lecteur ;
  - « Transférer le rôle d'administrateur » ;
  - le super-administrateur invite l'administrateur à la création de l'organisation.
- **Mot de passe oublié** sur l'écran de connexion, et **codes de secours** de la double authentification. La double authentification peut être rendue obligatoire pour l'organisation.
- Tests :
  - retirer `BetaTest` ;
  - adapter `SuperAdminTest` et `DowngradeTest` (quota libre) ;
  - ajouter : 404 sur `/inscription`, un seul administrateur, transfert du rôle, mot de passe oublié (réponse identique, limitation, lien unique d'une heure), codes de secours, double authentification obligatoire.

### P2 — Assistant d'installation et formats livrés

- L'assistant actuel est repris, avec son code de déverrouillage dans `data/` et la configuration écrite à la fin seulement. Ses étapes :
  1. vérification de l'environnement, avec des conseils par hébergeur ;
  2. adresse de l'application et expéditeur des e-mails, avec un e-mail de test ;
  3. super-administrateur : e-mail, mot de passe, double authentification ;
  4. première organisation (facultatif) : nom, quota, e-mail de son administrateur, qui reçoit une invitation ;
  5. encodage : navigateur, ou serveur si FFmpeg est détecté (P3) ;
  6. résumé, avec la ligne de tâche planifiée prête à copier.
- `check-env.php` signale FFmpeg (présence, version).
- **Image Docker** : Apache, PHP 8.3, FFmpeg et cron intégré, avec un `docker-compose.yml` d'exemple. L'assistant s'ouvre au premier démarrage.
- Guides d'installation : hébergement mutualisé (o2switch en exemple), VPS ou NAS avec Docker, serveur Apache classique.

### P3 — Encodage au choix

- Réglage dans `/superadmin` : « Dans le navigateur » (par défaut) ou « Sur le serveur ».
  - « Sur le serveur » n'est proposé que si FFmpeg est trouvé.
  - Un bouton d'essai mesure la vitesse et donne une estimation par heure de vidéo.
- **Sur le serveur** :
  - file d'attente en base, traitée par `cron.php` (verrou), une vidéo à la fois ;
  - priorité basse ; nombre de fils réglable (1 par défaut : o2switch coupe au-delà) ;
  - reprise après coupure ;
  - mêmes fichiers HLS que le navigateur.
- **Interface** :
  - dans le navigateur : « Envoi » puis « Optimisation — laissez cette page ouverte » ;
  - sur le serveur : « Envoi » puis « Optimisation sur le serveur — vous pouvez fermer cette page », avec l'avancement dans la liste des versions.
- ProRes et MXF encodés sur le serveur, sans fichier joint.
- Tests : commande FFmpeg, file d'attente (reprise, verrou), faux FFmpeg (réussite, échec, coupure).
- **P3b** : miniatures, planche de miniatures et métadonnées produites sur le serveur.

### P4 — Publication sur GitHub

- **Séance guidée Git et GitHub** : créer le dépôt, s'authentifier depuis le Mac, premier envoi, publier une version, recevoir un signalement.
- `LICENSE` (PolyForm Shield 1.0.0), `THIRD_PARTY.md`, mentions dans `package.json` et `composer.json`.
- Retirer ce qui est propre à belprod :
  - données de démonstration neutres ;
  - textes légaux transformés en modèles ;
  - adresses `@belprod.fr` retirées du code et des tests.
- **Historique Git neuf** : l'historique actuel (adresses, recettes réelles) reste sur votre Mac.
- `README.md`, `CONTRIBUTING.md`, `SECURITY.md`, `CHANGELOG.md`.
- **GitHub Actions**, à chaque étiquette `v3.x.y` : tests, archive `.zip` et empreinte SHA-256, image Docker, notes de version, `versions.json`.
- Avis de nouvelle version dans `/superadmin` (D8).

### P5 — Site vitrine du projet

- Pages :
  - présentation ;
  - **versions** (depuis `CHANGELOG.md`) ;
  - **téléchargements** (archive, empreinte, image Docker, versions précédentes) ;
  - documentation (installation, mise à jour, FAQ) ;
  - liens vers GitHub et vers la démo ;
  - mentions légales.
- `versions.json` publié.
- Offre bêta et demande d'accès retirées.
- Aucune requête vers un domaine tiers.

### P6 — Démo `demo.belprise.fr`

- Mode démo dans la configuration (`'demo' => true`).
- **Jeu de démonstration** :
  - une organisation fictive, 3 ou 4 clients ;
  - projets à plusieurs versions, révisions, statuts, collection ;
  - liens clients actifs ;
  - comptes administrateur, éditeur et lecteur de démo.
- **Vidéos** : extraits des films Blender préparés **sur le Mac** par un script (FFmpeg utilisé comme outil de développement), avec leurs versions de lecture déjà encodées. Crédit CC BY affiché.
- **Remise à zéro toutes les 6 h** :
  - `cron.php` restaure un instantané (base et médias) ;
  - bandeau « Démo — remise à zéro dans 2 h 14 » ;
  - identifiants affichés sur l'écran de connexion.
- **Garde-fous** :
  - aucun e-mail envoyé ;
  - comptes de démo non modifiables (mot de passe, e-mail, double authentification) ;
  - `/superadmin` fermé ;
  - **aucun envoi de vidéo possible** : boutons et glisser-déposer masqués, et l'API d'envoi refuse toute demande. On peut créer des clients, des projets ou des vidéos, réviser, répondre, valider, changer des statuts, mais pas importer de fichier ;
  - envoi de logos refusé lui aussi (seules les images du jeu de démonstration existent).
- Installation sur o2switch : sous-domaine, dossier séparé, sa propre tâche planifiée.

### P7 — Recette et mise en service

- Installations neuves sur hébergement mutualisé (copie d'essai) et sur Docker avec encodage serveur, avec un parcours complet dans chaque cas.
- Effacement de l'installation o2switch 2.x et réinstallation en 3.0. Une archive de l'ancien dossier est gardée sur le Mac jusqu'à validation.
- Démo en ligne, site vitrine à jour.
- Revue de sécurité, `verifier-production.sh` adapté (application, démo, vitrine).
- Publication : dépôt public, 3.0.0, image Docker.

## 4. Risques

- **Administrateur unique.** S'il perd l'accès, il passe par « Mot de passe oublié », un code de secours, le super-administrateur ou une commande sur le serveur. Il ne reste jamais bloqué, mais il faut le dire clairement.
- **Mot de passe oublié par e-mail.** La sécurité du compte dépend de la boîte e-mail : d'où la limitation des demandes et la réponse identique.
- **Démo publique.** N'importe qui peut y écrire des textes, mais jamais envoyer de fichier : le disque et la bande passante d'o2switch ne servent qu'aux vidéos du jeu de démonstration. Tout est effacé toutes les 6 h.
- **Encodage serveur en mutualisé.** Sur o2switch, environ 21 min par heure de vidéo avec un seul fil. L'estimation est affichée dans le réglage.
- **Code public.** Une faille devient visible de tous : `SECURITY.md`, revue de sécurité, correctifs rapides.
- **PolyForm Shield** n'est pas une licence « open source » au sens de l'OSI : c'est voulu, et il faut le dire.
- **Périmètre** : aucune nouvelle fonction de revue pendant la V3.

## 5. Ordre

P0 → P1 → P2 → P3 → P4 → P5 → P6 → P7

- P3 peut avancer en parallèle de P2, sauf l'étape 5 de l'assistant.
- P4 attend votre compte GitHub.
