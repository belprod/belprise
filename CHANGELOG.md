# Versions

Les nouveautés de chaque version de belprise. Les numéros suivent [SemVer](https://semver.org/lang/fr/) : un changement du premier chiffre demande une réinstallation ou une opération manuelle, décrite ici.

## 3.0.0 — en préparation

Nouveau départ : belprise devient un logiciel **à code source disponible, que chacun installe sur son serveur** (licence PolyForm Shield 1.0.0). Une installation 2.x ne se met pas à jour en 3.0 : elle se réinstalle.

- **Installation** : assistant en sept étapes dans le navigateur (vérification du serveur, e-mail de test, compte d'exploitation avec double authentification, première organisation, choix de l'encodage) ; image Docker avec FFmpeg, envoi des e-mails par SMTP et tâche planifiée intégrée.
- **Versions de lecture préparées au choix dans le navigateur ou sur le serveur** (FFmpeg, en arrière-plan, une vidéo à la fois, en priorité basse). Sur le serveur, les masters ProRes et MXF s'envoient sans fichier joint : le serveur lit leurs informations, prépare la version de lecture et fait les miniatures.
- **Organisations** : un seul administrateur par organisation, qui peut transférer son rôle ; quota libre fixé par le super-administrateur (en Go, ou sans limite) ; plus de forfaits ni de limite d'utilisateurs.
- **Comptes** : « Mot de passe oublié ? » sur l'écran de connexion ; dix codes de secours pour la double authentification ; double authentification obligatoire au choix de chaque organisation.
- **Mode démo** (`'demo' => true`) : comptes partagés affichés sur l'écran de connexion, aucun envoi de fichier ni d'e-mail, remise à zéro toutes les 6 heures ; c'est la démo publique demo.belprise.fr.
- **Avis de nouvelle version** dans `/superadmin` : le serveur consulte belprise.fr une fois par jour (désactivable).
- **Correctif** : un vrai fichier ProRes `.mov` n'était pas reconnu dans le navigateur.
- Retrait de la bêta (offre de 100 Go et demande d'accès).

## 2.1.1

- Une seule version de lecture, 720p à 3 Mbit/s.
- Import présenté en deux étapes, « Envoi » puis « Optimisation », avec une seule barre de progression.

## 2.1.0

- Versions de lecture encodées dans le navigateur et lues en HLS (Safari et iPhone en natif, hls.js ailleurs) ; « Qualité originale » pour l'administration.
- Masters `.mov` (H.264, HEVC, ProRes) et `.mxf` ; glisser-déposer de plusieurs masters ; import par dossier supprimé.
- Réinitialisation du mot de passe et de la double authentification par e-mail, à l'initiative de l'administrateur ou de l'exploitant.
- Installateur dans le navigateur et mise à jour en une commande, avec retour en arrière automatique.

## 2.0.1

- Masters exportés par Adobe Media Encoder (atomes non standard, timecode XMP).

## 2.0.0

- Plusieurs organisations cloisonnées, avec utilisateurs, rôles et invitations ; super-administrateur.
- Tableau de bord, archivage et bibliothèque du client, collections pour les prospects, identité visuelle de chaque organisation.
- Quotas, déclassement avec purge à 30 jours, suppression différée d'une organisation.
- Lecture continue, statut « Refusé » des révisions, raccourcis clavier, listes filtrables.

## 1.0.0

- Première version : clients, projets, vidéos et versions ; import des masters ; lecteur au timecode exact ; révisions et validation par le client ; liens partagés ; export EDL ; tableau de bord et recherche.
