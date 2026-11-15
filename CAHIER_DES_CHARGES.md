# belprise — Cahier des charges

> Logiciel de revue vidéo créé par **belprod**, à code source disponible, que chacun installe sur son serveur.
> Version 3.0 du cahier des charges — 14 novembre 2026 — statut : **brouillon, à valider**
> 3.0 (produit 3.0) : belprise devient un **logiciel auto-hébergé, publié sur GitHub** sous licence PolyForm Shield (§5.17) ; la bêta et les forfaits nommés disparaissent (quota libre par organisation, §5.12) ; **un seul administrateur par organisation** (§2.2) ; **« Mot de passe oublié ? »** en libre-service et codes de secours de la double authentification (§5.10) ; **encodage des versions de lecture dans le navigateur ou sur le serveur** (FFmpeg facultatif, §5.7 bis) ; assistant d'installation et image Docker (§7.2) ; site vitrine du projet (§5.15) ; démo publique remise à zéro toutes les 6 h (§5.16). Nouveau départ : pas de reprise des données 2.x. Plan : [PLAN_V3.md](PLAN_V3.md).
> 2.3 (produit 2.1.1) : **une seule version de lecture, 720p à 3 Mbit/s**, toujours encodée dans le navigateur (essai d'encodage sur le serveur o2switch : limité à un processeur par compte, abandonné) ; import présenté en deux étapes, « Envoi » puis « Optimisation », avec une seule barre de progression (§5.7 bis).
> 2.2 (produit 2.1) : import par dossier `inbox/` supprimé ; envoi par glisser-déposer ; **versions de lecture 720p / 1080p encodées dans le navigateur et lues en HLS** (§5.7 bis) ; `.mov` H.264 / HEVC acceptés, ProRes et MXF comme masters avec une version de lecture jointe ; réinitialisation du mot de passe et de la double authentification par e-mail, à l'initiative de l'administrateur ou de l'exploitant (§5.10) ; mise à jour en une commande et installateur dans le navigateur (§7.2).
> 2.1 : le produit s'appelle **belprise** (le domaine belvue était pris) ; ouverture en **bêta** : une seule offre gratuite de 100 Go, sur demande, organisations ouvertes à la main (§5.15).
> En 3.0, le code prend aussi le nom `Belprise` (espace de noms, cookies, base) ; jusqu'à la 2.x, il s'appelait `Belvue`.
> V1 livrée en 1.0.0, V2 jusqu'à la 2.1.1 (plans et recettes internes, non publiés).
>
> Les points marqués **[À décider]** ne sont pas encore tranchés : la proposition indiquée sert de valeur par défaut tant qu'il n'y a pas de réponse. Leur liste est au §11.

---

## 1. Contexte et objectifs

belprod (agence audiovisuelle, Paris) produit des vidéos pour ses clients. Belprise permet de :

- **diffuser** une vidéo à un client par simple lien, dans un lecteur soigné à ses couleurs ;
- **recueillir des révisions précises**, posées sur un instant ou une plage de la vidéo (à la manière de frame.io), et **y répondre** ;
- **gérer les versions** successives (V1, V2, V3…) d'une même vidéo sur une seule page ;
- **faire valider** une version par le client ;
- **livrer le master** original en téléchargement ;
- **présenter une sélection de références** à un prospect (Collections) ;
- **conserver une bibliothèque** des masters validés, consultable par chaque client.

En V2, belprise est devenu un service à plusieurs **organisations** (agence, vidéaste, service vidéo d'une entreprise), chacune avec son espace cloisonné et ses utilisateurs.

**En V3, belprise devient un logiciel que chacun installe sur son propre serveur** (hébergement mutualisé, VPS, NAS). Une vidéo demande beaucoup de bande passante et de calcul : chaque structure héberge donc ses propres vidéos. Celui qui installe belprise en est l'**exploitant** (super-administrateur, §5.11) : il crée autant d'organisations que nécessaire, par exemple une seule pour son agence, ou une par service. Le code est publié sur GitHub (§5.17), présenté sur le site vitrine (§5.15) et démontré en ligne (§5.16).

Principes directeurs :

- **Privé** : aucune page de l'application n'est indexable, aucun service tiers, liens impossibles à deviner. Seul le site vitrine est public.
- **Cloisonné** : une organisation ne voit jamais les données d'une autre ; l'administrateur de la plateforme ne voit pas les contenus.
- **Léger et réactif** : pas de framework lourd, ni de dépendance serveur exotique.
- **Épuré** : peu d'éléments par écran, hiérarchie claire, interface sombre par défaut.
- **Portable** : PHP + SQLite, installable par un assistant dans le navigateur sur un hébergement mutualisé, un Apache, ou par une image Docker sur un VPS ou un NAS.
- **Auto-hébergé** : les données restent sur le serveur de l'exploitant ; belprise ne contacte aucun service, sauf l'avis de nouvelle version (§6), désactivable.

---

## 2. Rôles et accès

### 2.1 Plateforme

| Accès | Qui | Comment | Peut… |
|---|---|---|---|
| **Super-administrateur** | L'exploitant de l'installation | Adresse de connexion dédiée, mot de passe + double authentification **obligatoire** | Gérer les organisations, leur quota et leur suppression différée, régler l'encodage (§5.11). **Ne voit ni ne modifie aucun contenu** |
| **Utilisateur d'une organisation** | Les membres d'une organisation | `/admin`, e-mail + mot de passe, double authentification facultative | Selon son rôle (§2.2), uniquement dans son organisation |

### 2.2 Rôles dans une organisation

| Action | Administrateur | Éditeur | Lecteur |
|---|---|---|---|
| Consulter clients, projets, vidéos, révisions, collections | ✓ | ✓ | ✓ |
| Créer, modifier, archiver ; importer des versions ; répondre aux révisions ; gérer les liens et les collections | ✓ | ✓ | — |
| Supprimer définitivement (client, projet, vidéo, version) | ✓ | — | — |
| Inviter, modifier, retirer des utilisateurs ; réinitialiser leur accès | ✓ | — | — |
| Réglages de l'organisation (nom, logo, couleurs, double authentification obligatoire) | ✓ | — | — |
| Transférer le rôle d'administrateur | ✓ | — | — |

- Un utilisateur appartient à **une seule organisation**.
- **Un seul administrateur par organisation** (3.0). Il est invité par le super-administrateur à la création de l'organisation. Il peut **transférer son rôle** à un membre de l'équipe : ce membre devient administrateur, et lui devient Éditeur. Il ne peut ni se retirer ni être retiré.
- Pas de limite du nombre d'utilisateurs (la limite de 10 de la V2 disparaît en 3.0).
- **Invitation par e-mail** : l'administrateur saisit l'e-mail et le rôle (Éditeur ou Lecteur) ; la personne reçoit un lien à usage unique, valable 7 jours, pour créer son compte (nom et mot de passe).

### 2.3 Accès sans compte (inchangé par rapport à la V1, sauf Collection)

| Accès | Qui | Comment | Peut… |
|---|---|---|---|
| **Espace client** | Le client et ses collaborateurs | Lien secret propre à chaque client | Voir ses projets, ses vidéos en cours et sa **bibliothèque** de vidéos archivées, puis tout ce que permet le lien Client |
| **Lien Client (vidéo)** | Le client | Lien secret propre à chaque vidéo | Passer d'une version à l'autre, lire et poster des révisions, répondre, valider une version, télécharger le master |
| **Lien Visualisation (vidéo)** | N'importe qui ayant le lien | Lien secret propre à chaque vidéo, distinct du lien Client | Regarder la vidéo, rien d'autre |
| **Lien Collection** | Un prospect | Lien secret propre à chaque collection | Regarder les vidéos sélectionnées, rien d'autre |

- **Pas de compte client.** Pour réviser, la personne saisit simplement son nom, que le navigateur mémorise.
- **Options de chaque lien** : mot de passe facultatif, date d'expiration facultative, désactivation, **régénération** (nouvelle clé, l'ancienne devient invalide).

---

## 3. Organisation des contenus

```
Organisation   (quota, utilisateurs, identité visuelle)
 ├── Client
 │    └── Projet
 │         └── Vidéo  (n° court, ex. 1042 — tags libres)
 │              └── Version  (V1, V2, V3…)
 │                   └── Révisions (et leurs réponses)
 └── Collection  (sélection ordonnée de vidéos de l'organisation)
```

- **Tags libres** sur les vidéos, avec autocomplétion à partir des tags de l'organisation.
- **Numéro de vidéo** : entier court attribué automatiquement de façon croissante, **propre à chaque organisation**, à partir de 1001. Deux organisations peuvent donc avoir chacune une vidéo n° 1042 ; leurs liens restent distincts par la clé.
- **Données existantes** : la 3.0 repart de zéro (nouvelle installation) ; les données d'une 2.x ne sont pas reprises.

---

## 4. Adresses (URL)

**Domaines**

| Adresse | Rôle | Indexation |
|---|---|---|
| `belprise.fr` | Site vitrine du projet (§5.15) | Indexé |
| `demo.belprise.fr` | Démo publique (§5.16) | Jamais indexé |
| `app.belprise.fr` | Installation de belprod (exemple : chaque exploitant choisit son adresse) | Jamais indexé (§6) |

L'application doit avoir son propre hôte, distinct de tout site indexable : elle ne doit jamais être trouvée par les moteurs de recherche.

**Pages de l'application**

| Page | Format | Exemple |
|---|---|---|
| Espace client | `/{clé}` | `/kfmxqtrwpz` |
| Collection | `/{clé}` (même forme, type reconnu par le serveur) | `/hqzrwmbtxe` |
| Vidéo — lien Client | `/v/{n°}{clé}` | `/v/1042kfmxqtrwpz` |
| Vidéo — lien Visualisation | `/v/{n°}{clé}` (clé différente) | `/v/1042hbnwzcxqre` |
| Administration des organisations | `/admin` | |
| Super-administrateur | `/superadmin` | |
| Invitation | `/invitation/{jeton}` | |
| Réinitialisation (lien reçu par e-mail) | `/reinitialisation/{jeton}` | |
| Assistant d'installation (avant configuration seulement) | `/installation` | |

- **Clé** : 10 lettres minuscules tirées au hasard de façon cryptographiquement sûre, sans `l` ni `o`. Le serveur sépare le numéro de la clé à la première lettre.
- **Aucun nom de client, d'organisation ni d'entreprise dans les URL.** Les préfixes `admin`, `superadmin`, `invitation`, `reinitialisation`, `installation`, `v`, `api`, `media` et `assets` sont réservés.
- **Toute URL invalide renvoie une même page 404 neutre**, y compris un identifiant appartenant à une autre organisation.

---

## 5. Fonctionnalités

### 5.1 Page de revue (lien Client)

**En-tête**
- Logo du client.
- Fil d'Ariane : Client / Projet / Vidéo.
- Boutons **Télécharger le master** et **Valider cette version**.

**Titre**
- Titre de la vidéo, n° (ex. `// N° 1042`).
- **Sélecteur de versions** (V1, V2, V3…). La version la plus récente est sélectionnée par défaut.
- Étiquette du statut de la version, date d'envoi.
- Métadonnées techniques : résolution, cadence, timecode de départ, poids.

**Lecteur**
- Vidéo HTML5 avec sauts dans la vidéo (requêtes HTTP Range).
- Timecode **SMPTE** `HH:MM:SS:FF`, calé sur le timecode de départ et la cadence réelle du fichier.
- Lecture/pause, image précédente/suivante, vitesse (0,5× / 1× / 1,5× / 2×), volume, plein écran.
- **Barre de lecture** : un clic ou un glissé **pendant la lecture** reprend la lecture au nouvel endroit ; en pause, la vidéo reste en pause.
- **Timeline avec marqueurs de révisions** :
  - point pour un instant, barre pour une plage ;
  - jaune « À faire », vert « Fait », rouge « Refusé » ;
  - au survol : bulle avec l'auteur et un extrait ; au clic : saut au timecode et mise en évidence de la révision.
- Survol de la timeline : vignette d'aperçu, tirée de la planche de miniatures.
- Pendant la lecture, la révision correspondant au timecode courant s'affiche en surimpression discrète.
- **Raccourcis clavier** (désactivés dans un champ de saisie), rappelés sous le formulaire par des **touches dessinées**, masquées sur écran tactile :

| Touche | Action |
|---|---|
| Espace | Lecture / pause |
| J / K / L | Lecture arrière / pause / lecture avant (appuis répétés : vitesse ×2) |
| ← / → | Image précédente / suivante |
| Maj + ← / → | Recul / avance d'une seconde |
| I / O | Point d'entrée / de sortie d'une plage |
| C | Placer le curseur dans le champ de révision, sur le timecode courant |
| F | Plein écran |

**Panneau des révisions**
- Liste triée par timecode, avec le nombre de révisions et des filtres **Tous / À faire / Fait / Refusé** (ce dernier n'apparaît que s'il y a des révisions refusées).
- Chaque révision affiche l'initiale et le nom de l'auteur, le timecode (ou la plage `IN → OUT`) cliquable, le texte, son statut et ses réponses (celles de l'organisation portent sa signature).
- Le client peut **répondre** (réponses sur un seul niveau).
- **Formulaire** : choix Instant / Plage ; IN pré-rempli au timecode courant, OUT à la touche O ou au bouton ; texte ; « En tant que {nom} » modifiable ; bouton Envoyer ; la vidéo se met en pause dès qu'on écrit.
- Au **premier envoi**, une fenêtre demande le nom de la personne et le mémorise dans le navigateur.
- Un auteur peut **modifier ou supprimer ses propres révisions** (jeton propre au navigateur, dans `localStorage`).
- Mise à jour automatique toutes les 30 s environ.

**Validation**
- « Valider cette version » ouvre une confirmation, puis le statut passe à **Validé**, avec le nom et la date.
- Un utilisateur de l'organisation (Éditeur ou Administrateur) peut annuler une validation.

### 5.2 Lien Visualisation

- Affiche **la dernière version validée** ou, à défaut, **la dernière version publiée**.
- La page ne contient que le lecteur, le titre, le logo et les couleurs du client, et la signature belprise.
- Pas de sélecteur de versions, pas de révisions, pas de téléchargement.

### 5.3 Espace client et bibliothèque

- Accueil personnalisé : logo, texte d'accueil, couleurs du client.
- Recherche dans ses vidéos.
- Projets classés du plus récent au plus ancien. Chaque projet présente une grille de cartes vidéo : miniature, durée, n°, titre, version courante, statut, date.
- Un clic sur une carte ouvre la page de revue (5.1).
- **Bibliothèque** : les vidéos **archivées** restent visibles dans l'espace client, dans une section à part. Elles n'offrent que la **lecture** de la version conservée (§5.13), sans révision ni historique, et son **téléchargement si la vidéo l'autorise** (le réglage « téléchargement autorisé » de la vidéo s'applique tel quel).

### 5.4 Versions et statuts

| Statut | Couleur | Signification |
|---|---|---|
| **En cours** | Bleu `#4d8dff` | Version importée, **non publiée**, invisible pour le client |
| **À valider** | Violet `#a970ff` | Version publiée, en attente de révisions |
| **Modifications demandées** | Jaune `#ffd60a` | Le client a posté au moins une révision |
| **Validé** | Vert `#2ee59d` | Version validée par le client (ou par l'organisation) |

- « À valider » passe à « Modifications demandées » à la première révision client sur cette version ; n'importe quel statut passe à « Validé » quand le client valide.
- Un Éditeur ou un Administrateur peut **forcer n'importe quel statut** et **publier ou dépublier** une version.
- **Chaque version a ses propres révisions.**
- Le statut d'une vidéo, affiché dans les listes, est celui de sa dernière version publiée.

### 5.5 Révisions : statuts À faire / Fait / Refusé

- Chaque révision principale a un statut **À faire** (par défaut), **Fait** ou **Refusé**. Les réponses n'ont pas de statut.
- **Refusé** : la modification demandée n'est techniquement pas possible. Refuser **exige une explication**, publiée en réponse à la révision dans la même action. **[À décider — Q1]** (proposé et réalisé : obligatoire).
- **Seuls les Éditeurs et Administrateurs** changent ce statut. Le client le voit.
- Une révision refusée peut être remise « À faire ».

### 5.6 Export EDL

- Sur chaque version : export des révisions en **marqueurs EDL CMX3600**, fichier `{n°}_{titre}_V{x}_marqueurs.edl`, pour l'**import de marqueurs de DaVinci Resolve**.
- Chaque marqueur porte le timecode source, une durée (plage) et le texte `{Auteur} : {révision}`, avec la couleur du statut : jaune « À faire », vert « Fait », **rouge « Refusé »**. **[À décider — Q2]** (proposé et réalisé : rouge, exporté).
- Filtre : toutes les révisions / seulement « À faire ».
- Premiere Pro n'importe pas de marqueurs EDL : un export dédié reste hors périmètre (§9).

### 5.7 Administration d'une organisation

**Menu de gauche** : logo de l'organisation en haut ; en bas, la signature « **belprise. · propulsé par belprod** » avec un lien vers belprod.fr.

**Tableau de bord** (page d'accueil)
- Compteurs : **clients**, **projets** et **vidéos** (hors archives) ; stockage utilisé / quota ; révisions à faire.
- **Accès rapide aux derniers clients, projets et vidéos ayant reçu une activité** : nouvelle version, nouvelle révision ou réponse, validation, changement de statut. Chaque ligne dit ce qui s'est passé (« V3 importée », « 2 révisions de Claire ») et mène à la bonne page.
- Les révisions non lues restent accessibles dans la page **Révisions** (ancienne liste « Retours à traiter », avec ses filtres), avec un badge du nombre de non-lues dans le menu.

**Recherche et filtres** (vidéos et révisions) : texte libre (titre, n°, client, projet, tag) ; filtres client, projet, tag, statut, plage de dates.

**Listes de choix filtrables** : toute liste de choix d'un client, d'un projet, d'une vidéo, d'un tag ou d'un fichier se filtre en tapant (accents et casse ignorés, plusieurs mots possibles, groupement par client, pilotage au clavier).

**Clients** — création, modification, archivage, suppression (confirmation renforcée)
- Nom, logo (SVG/PNG/WebP), couleur de fond, de texte, d'accent, texte d'accueil.
- Avertissement si le contraste texte/fond est insuffisant (WCAG AA).
- Lien Espace client : copier, mot de passe, expiration, désactiver, régénérer.

**Projets** — création, modification, archivage, suppression ; rattachés à un client.

**Vidéos**
- Création : titre, projet, tags, téléchargement autorisé (oui par défaut).
- Liens Client et Visualisation : copier, mot de passe, expiration, désactiver, régénérer.
- Page de revue côté organisation : même interface que le client, avec en plus les réponses signées, les statuts À faire / Fait / Refusé, la suppression de révisions, le statut de la version, la publication, l'export EDL.
- **Archivage** (§5.13).

**Versions** — ajout (numéro suivant automatique), statut « En cours » à l'import puis publication manuelle, suppression avec confirmation.

**Import des masters** — par le navigateur uniquement (le dossier d'import `inbox/` de la V1 est supprimé en 2.2)
- **Upload découpé** en morceaux d'environ 32 Mo écrits à leur position, avec reprise automatique ou en resélectionnant le fichier, empreinte vérifiée pour chaque morceau, progression, débit et temps restant. Fichiers de plusieurs dizaines de Go.
- **Glisser-déposer** d'un ou plusieurs fichiers sur la liste des vidéos ou sur un projet : chaque fichier crée sa vidéo (titre repris du nom du fichier) et s'envoie dans une file d'attente ; sur la fiche d'une vidéo, le fichier devient sa version suivante.

Un import est **refusé** s'il ferait dépasser le quota de l'organisation (§5.12).

**Analyse automatique** (dans le navigateur ; sur le serveur pour les ProRes et MXF quand l'encodage serveur est actif, §5.7 bis) : métadonnées par `mp4box.js` (durée, résolution, codec, cadence exacte, timecode de départ lu dans la piste `tmcd`, sinon XMP, sinon `00:00:00:00`, toujours modifiables) ; couverture, vignette et planche de miniatures. En 4K au maximum.

**Formats acceptés**
| Master | Lecture |
|---|---|
| `.mp4` / `.mov` en H.264 ou HEVC | Version de lecture encodée automatiquement, dans le navigateur ou sur le serveur (§5.7 bis) |
| `.mov` en ProRes | Accepté comme master (téléchargeable). **Encodage dans le navigateur** : une version de lecture en H.264 (`.mp4` / `.mov`, même durée et même cadence) est jointe à l'envoi et sert de source. **Encodage sur le serveur** : rien à joindre, le serveur fait aussi les images |
| `.mxf` | Comme le ProRes. Dans le navigateur, les métadonnées (cadence, timecode, durée) sont reprises de la version de lecture jointe ; sur le serveur, elles sont lues par `ffprobe`. Elles restent modifiables |

### 5.7 bis Versions de lecture (proxy) et HLS

- La revue ne lit plus le master : chaque version a une **version de lecture**, **720p à 3 Mbit/s** (H.264 ; à la hauteur du master s'il est plus petit ; même cadence et mêmes images que le master, donc même timecode à l'image près ; son AAC repris du master quand c'est possible).
- **Deux modes d'encodage**, choisis par le super-administrateur pour toute l'installation (§5.11) :
  - **dans le navigateur** (par défaut) : WebCodecs, juste après l'envoi du master, sans aucun calcul sur le serveur. L'admin voit une seule barre de progression et deux étapes, « Envoi » puis « Optimisation » avec « Laissez cette page ouverte jusqu'à la fin » ;
  - **sur le serveur**, proposé seulement si **FFmpeg** est présent (inclus dans l'image Docker) : après l'envoi, la version entre dans une **file d'attente** traitée par la tâche planifiée (§7.1), une vidéo à la fois, en priorité basse, avec un nombre de fils réglable (1 par défaut ; un hébergement mutualisé coupe souvent au-delà d'un processeur). L'admin voit « Envoi » puis « Optimisation sur le serveur — vous pouvez fermer cette page », et l'avancement dans la liste des versions. Un encodage interrompu recommence au passage suivant (3 essais, puis « Optimisation impossible » avec la raison et un bouton « Réessayer »). Le réglage affiche la vitesse mesurée au dernier encodage, ou par la commande `php app/bin/encode-test.php` sur le serveur (par exemple environ 21 min par heure de vidéo sur o2switch, avec un seul fil). La tâche planifiée passe alors toutes les 5 minutes.
- Dans les deux modes, les fichiers techniques (HLS) n'apparaissent jamais. Un bouton « Générer la version de lecture » relance l'encodage après coup (versions anciennes, onglet fermé trop tôt, changement de mode).
- Sur le serveur, un **ProRes ou un MXF s'envoie sans fichier joint** : la tâche planifiée lit d'abord les métadonnées que le navigateur ne sait pas lire (MXF : dimensions, cadence, durée, timecode de départ, par `ffprobe`), encode la version de lecture, puis fait la couverture, la vignette et la planche de miniatures quand la version n'en a pas (mêmes dimensions et même disposition que dans le navigateur). Un fichier H.264 joint reste possible, pour avoir la couverture tout de suite. Les outils d'images du navigateur (« Couverture… », « Régénérer les miniatures ») ne sont proposés que pour les masters qu'il sait lire.
- **Lecture HLS** : un fichier MP4 fragmenté ; le serveur produit à la volée des listes de lecture HLS à plages d'octets (`EXT-X-BYTERANGE`) dont chaque adresse est signée et expire, comme les médias. Lecture native sur Safari et iPhone, par hls.js (empaqueté) ailleurs.
- Tant que la version de lecture n'existe pas, le lecteur lit le master. Dans l'administration, un choix « Qualité originale » lit le master (vérification de l'étalonnage) ; les clients n'ont pas ce choix.
- Les réponses partielles (requêtes Range ouvertes) sont limitées à quelques Mo : un déplacement dans la vidéo ne déclenche jamais la lecture du fichier entier sur le serveur.

**Réglages de l'organisation** (Administrateur)
- Nom et **logo** de l'organisation, **couleurs de l'interface d'administration** (accent, fond clair ou sombre), avec contrôle du contraste.
- **Utilisateurs** : liste, invitations en attente, rôles (Éditeur, Lecteur), retrait, réinitialisation de l'accès, **transfert du rôle d'administrateur**.
- **Double authentification obligatoire** pour toute l'équipe (case à cocher) : un membre qui ne l'a pas encore activée est invité à le faire à sa prochaine connexion.
- Quota, stockage utilisé, et journal des actions du super-administrateur concernant l'organisation.

**Mon compte** (chaque utilisateur) : nom, mot de passe, double authentification (TOTP) avec **10 codes de secours** à usage unique, montrés une seule fois à l'activation et régénérables.

### 5.8 Personnalisation par client

S'applique à l'Espace client, au lien Client et au lien Visualisation de ses vidéos : logo, couleur de fond, de texte, d'accent, texte d'accueil. Les couleurs de statut et la signature « **belprise.** · propulsé par belprod » en bas de page ne sont **pas** personnalisables.

### 5.9 Collections (portfolio)

Usage : réunir des vidéos existantes de l'organisation pour un prospect (par exemple des reportages pour répondre à un appel d'offres) et partager une page soignée par un lien.

- **Création** (Éditeur, Administrateur) : titre, texte d'introduction, **sélection de vidéos** de l'organisation (liste filtrable, filtre par tags), ordre par glisser-déposer.
- **Version montrée** : la dernière version validée de chaque vidéo ou, à défaut, la dernière version publiée. Une vidéo non encore validée peut donc entrer dans une collection ; une vidéo sans version publiée n'est jamais montrée.
- **Habillage** : couleurs et logo propres à la collection (par exemple ceux du prospect), ou ceux de l'organisation.
- **Lien Collection** : mêmes protections que les autres liens (désactivation, expiration, mot de passe facultatif, régénération, 404 neutre, non indexé).
- **Page publique** : titre, introduction, grille de vignettes (titre, durée), lecteur au clic. **Lecture seule** : ni révision ni téléchargement.

### 5.10 Organisations et utilisateurs

- Une organisation a : un nom, un quota de stockage (§5.12), une identité visuelle, un administrateur et son équipe, ses clients, projets, vidéos et collections.
- **Création** : par le super-administrateur uniquement (nom, quota, e-mail de l'administrateur, qui reçoit une invitation). Pas d'inscription libre.
- **Suppression d'une organisation et de toutes ses données** (droit à l'effacement) :
  - demandée par **un administrateur de l'organisation**, dans ses réglages, avec confirmation renforcée (saisie du nom de l'organisation et du mot de passe), **ou par le super-administrateur** (§5.11) ;
  - dans les deux cas, **exécution différée de 30 jours** : les administrateurs de l'organisation sont prévenus par e-mail immédiatement, puis à J-7 et J-1 ;
  - pendant ce délai, l'organisation garde l'accès pour récupérer ses masters ; les nouveaux envois sont bloqués ; les liens partagés (espace client, lien Client, Visualisation, Collection) **restent actifs** ;
  - la demande peut être **annulée** pendant le délai : par l'administrateur de l'organisation s'il en est l'auteur ; une suppression demandée par le super-administrateur ne peut être annulée **que par le super-administrateur** ;
  - à J+30, toutes les données (base et fichiers) sont effacées par la tâche planifiée, l'opération est journalisée et un e-mail de confirmation est envoyé.
- **Mot de passe oublié** (3.0) :
  - **en libre-service** : un lien « Mot de passe oublié ? » sur l'écran de connexion de l'administration demande l'e-mail. La réponse est **toujours la même**, que l'adresse existe ou non ; les demandes sont limitées (par adresse et par réseau). Si le compte existe, il reçoit un lien de nouveau mot de passe ;
  - **par un tiers** : l'administrateur pour un membre de son équipe, le super-administrateur pour un administrateur. Ils peuvent réinitialiser le mot de passe ou la double authentification ; celui qui déclenche ne voit jamais le lien ;
  - le lien est envoyé **à la personne concernée**, **valable 1 heure, à usage unique** (jeton aléatoire stocké sous forme d'empreinte) ; une nouvelle demande annule la précédente ;
  - le libre-service **ne remet jamais à zéro la double authentification** : on se connecte avec un **code de secours**, ou on la fait réinitialiser par l'administrateur ou le super-administrateur ;
  - les sessions ouvertes de la personne se ferment ; chaque réinitialisation est inscrite au journal de l'organisation ;
  - en dépannage, `php app/bin/reset-password.php <e-mail>` et `php app/bin/disable-totp.php <e-mail>` sur le serveur. Le super-administrateur n'a pas de libre-service : il passe par ces commandes.

### 5.11 Super-administrateur

- **Compte distinct**, créé par l'assistant d'installation (§7.2), ou en ligne de commande, rattaché à aucune organisation. Double authentification obligatoire.
- **Voit** : la liste des organisations, leur quota, leur stockage utilisé, leur nombre d'utilisateurs, leurs dates de création et de dernière activité ; l'espace libre du disque ; la version installée et l'**avis de nouvelle version** (§6).
- **Peut** : créer une organisation et inviter son administrateur ; **changer le quota** (un nombre de Go, ou sans limite) ; un quota abaissé sous l'espace utilisé déclenche le **déclassement** (§5.12) ; **demander la suppression** d'une organisation, exécutée seulement **30 jours plus tard** (§5.10), et l'annuler pendant ce délai ; **réinitialiser l'accès** d'un administrateur (§5.10) ; régler l'**encodage des versions de lecture** (navigateur ou serveur, nombre de fils, vitesse mesurée, §5.7 bis).
- **Ne peut pas** : voir, modifier ou supprimer clients, projets, vidéos, révisions, liens ou collections ; se connecter à la place d'un utilisateur ; **supprimer une organisation immédiatement**.
- Côté serveur, **aucune route de contenu n'accepte une session de super-administrateur**. En cas de vol de ses accès, rien ne peut être exploré, et rien ne peut être détruit sans un préavis de 30 jours, annoncé par e-mail aux organisations concernées.
- Chaque action du super-administrateur est **journalisée** et visible par l'organisation concernée.

### 5.12 Quotas et déclassement

**Quota** (3.0) : fixé librement par le super-administrateur pour chaque organisation, en Go, ou **sans limite** (l'espace libre du disque reste la seule borne). Les forfaits nommés de la V2 disparaissent. Clients et utilisateurs illimités.

**Quota**
- Comptent : masters, couvertures, vignettes, planches, logos, envois en cours.
- Jauge utilisé / quota dans l'administration ; alerte à 80 % ; **nouveaux envois bloqués à 100 %** ; un envoi qui ferait dépasser le quota est refusé dès le départ avec l'espace restant.

**Déclassement**
1. Le super-administrateur abaisse le quota d'une organisation **sous l'espace qu'elle utilise**.
2. **E-mail immédiat** à l'administrateur de l'organisation : nouveau quota, espace à libérer, **date de purge à J+30**, marche à suivre pour récupérer les masters.
3. Pendant 30 jours : nouveaux envois bloqués ; consultation, téléchargement et suppression possibles ; bandeau d'échéance ; rappels par e-mail à J-7 et J-1.
4. **À J+30**, si le stockage dépasse toujours le quota, Belprise **supprime des vidéos entières** jusqu'à repasser sous le quota, puis envoie un e-mail récapitulatif. Ordre de suppression :
   - d'abord les **vidéos archivées** (bibliothèque client), puis les autres ;
   - dans chaque groupe, de la **dernière activité la plus ancienne** à la plus récente (activité au sens du §5.13 : nouvelle version ou nouvelle révision ; à défaut, date de création).
5. Si le quota est relevé au-dessus de l'espace utilisé avant l'échéance, ou si l'organisation libère assez d'espace, la purge est annulée.

La purge ne supprime jamais plus que le nécessaire, est journalisée, et dispose d'un mode d'essai qui liste sans supprimer.

**Paiement** : aucun (§9). belprise ne facture rien ; un exploitant qui héberge d'autres structures s'organise comme il l'entend, dans les limites de la licence (§5.17).

### 5.13 Archivage des vidéos

**Archiver une vidéo** (Éditeur, Administrateur, ou automatiquement) :
- conserve **une seule version** : la **dernière version validée** ou, s'il n'y en a pas, la **dernière version publiée** (master, couverture, vignette, planche) ;
- une vidéo sans aucune version publiée ne peut pas être archivée (rien à montrer au client) ;
- **supprime définitivement les autres versions et toutes les révisions**, pour libérer l'espace disque ;
- archivage manuel : affiche avant confirmation la version conservée, l'espace libéré et ce qui sera supprimé (action irréversible) ;
- la vidéo passe dans la **bibliothèque** de l'espace client (§5.3) ;
- chaque archivage est inscrit dans le journal de l'organisation.

**Archivage automatique**
- Une vidéo **validée** sans aucune activité depuis **6 mois** est archivée automatiquement par une tâche quotidienne.
- **Activité** = une nouvelle version ou une nouvelle révision (une réponse à une révision compte comme une révision). Une consultation, un téléchargement, une validation ou un changement de statut ne relancent pas le délai.
- **Sans préavis** : ni e-mail ni bandeau avant l'archivage. Les vidéos archivées automatiquement apparaissent ensuite dans le journal et dans l'activité du tableau de bord.

### 5.14 E-mails

- Envoyés par le serveur de l'hébergeur (fonction `mail()` de PHP, sans dépendance externe), au nom de belprise, avec l'expéditeur choisi à l'installation.
- Messages : invitation, mot de passe oublié et réinitialisation, déclassement et rappels, purge effectuée, suppression d'organisation (demande, rappels, exécution), e-mail de test de l'assistant d'installation.
- En français, texte et HTML simple, **sans image distante ni pixel de suivi**.
- Domaine d'envoi configuré avec SPF et DKIM.

### 5.15 Site vitrine du projet (belprise.fr)

- **Accueil** : présentation de l'outil et de ses fonctions clés (revue à l'image près, révisions sur instant ou plage, export EDL, espace client aux couleurs du client, collections, versions de lecture HLS, auto-hébergement sans pistage), liens vers la **démo** (§5.16) et vers le **dépôt GitHub**.
- **Versions** : liste des versions et de leurs nouveautés, générée à la compilation depuis `CHANGELOG.md` (une version marquée « en préparation » n'est pas proposée au téléchargement ; seules les versions 3.x sont publiques).
- **Téléchargements** : archive `.zip` de la dernière version avec son empreinte SHA-256, versions précédentes, commande pour l'image Docker.
- **Documentation** : le guide d'installation (`DEPLOIEMENT.md`, mutualisé, Docker, Apache, mise à jour), et des modèles de mentions légales et de politique de confidentialité pour les exploitants (`docs/modeles/`).
- **`versions.json`** : la dernière version et son adresse de téléchargement, lus une fois par jour par les installations (§6).
- **Mentions légales** et licence. Plus d'offre ni de demande d'accès (la bêta et la page `/inscription` disparaissent en 3.0).
- Mêmes règles « aucun service tiers » que l'application (polices hébergées, pas de statistiques externes). Contrairement à l'application, la vitrine est **indexable**.

### 5.16 Démo publique (demo.belprise.fr)

- Une installation en **mode démo** (`'demo' => true` dans la configuration) : une organisation fictive, 3 ou 4 clients, des projets à plusieurs versions avec révisions et statuts, une collection, des liens clients actifs.
- **Vidéos** : extraits des films libres de la Blender Foundation (Big Buck Bunny, Sintel, Tears of Steel ; licence CC BY), préparés à l'avance avec leurs versions de lecture. Le crédit est affiché dans la démo et sur le site.
- **Accès** : comptes de démo (administrateur, éditeur, lecteur) dont les identifiants sont affichés sur l'écran de connexion. `/superadmin` est fermé.
- **Remise à l'état initial toutes les 6 h** par la tâche planifiée (restauration d'un instantané de la base et des fichiers). Un bandeau indique le temps restant avant la prochaine remise à zéro.
- **Garde-fous** :
  - **aucun envoi de fichier** : ni vidéo, ni logo. Les boutons et le glisser-déposer sont masqués, et le serveur refuse tout envoi ;
  - aucun e-mail envoyé ;
  - mot de passe, e-mail et double authentification des comptes de démo non modifiables ;
  - transfert du rôle d'administrateur et suppression de l'organisation impossibles.
- Tout le reste fonctionne : créer des clients, projets et vidéos, réviser, répondre, valider, changer des statuts, gérer des liens et des collections, exporter l'EDL.

### 5.17 Licence et publication

- Licence **PolyForm Shield 1.0.0** : chacun peut installer, utiliser, modifier et partager belprise, y compris une société pour son travail payé. Il est interdit de le revendre ou d'en tirer un produit ou service concurrent. Ce n'est pas une licence « open source » au sens de l'OSI : on parle de code source disponible.
- Les bibliothèques empaquetées gardent leur licence (mediabunny MPL-2.0, hls.js Apache-2.0, polices OFL) ; leur liste est dans `THIRD_PARTY.md`.
- Code publié sur **GitHub**, avec un historique neuf à partir de la 3.0. Chaque version (étiquette `v3.x.y`) produit automatiquement : tests, archive `.zip` et empreinte, image Docker (`ghcr.io`), notes de version, `versions.json`.
- Documents : `README.md`, `CONTRIBUTING.md`, `SECURITY.md` (signaler une faille en privé), `CHANGELOG.md`.

---

## 6. Confidentialité et sécurité

**Invisibilité pour les robots et les IA** (application, pas la vitrine)
- `robots.txt` avec `Disallow: /` pour tous les robots, plus des entrées explicites pour les robots d'IA connus (GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-Web, anthropic-ai, CCBot, Google-Extended, PerplexityBot, Bytespider, Applebot-Extended, Meta-ExternalAgent, Amazonbot, etc.).
- En-tête `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai` sur **toutes** les réponses, plus la balise `<meta name="robots">` équivalente.
- Blocage par user-agent dans `.htaccess` (réponse 403) et vérification équivalente côté PHP.
- Aucun sitemap, aucune balise Open Graph ni Twitter, aucun aperçu de lien riche.

**Aucun service tiers** (application et vitrine)
- Polices hébergées sur le serveur ; aucune bibliothèque chargée depuis un CDN ; aucune statistique d'audience ; `Referrer-Policy: no-referrer`.
- **Seule exception (3.0) : l'avis de nouvelle version.** Le **serveur**, par la tâche planifiée et une fois par jour au plus, lit `https://belprise.fr/versions.json`, sans rien envoyer d'autre que la requête elle-même. Aucun navigateur ne contacte belprise.fr. Désactivable dans la configuration (`'update_check' => false`).

**En-têtes de sécurité**
- Content-Security-Policy stricte (`default-src 'self'`), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Permissions-Policy` restrictive, HSTS une fois le HTTPS en place.

**Cloisonnement des organisations**
- Toute requête de l'administration est limitée à l'organisation de l'utilisateur connecté et aux droits de son rôle.
- Un identifiant d'une autre organisation donne la **même 404 neutre** qu'un identifiant inexistant.
- Les médias sont rangés par organisation ; une URL signée ne vaut que pour son fichier.
- Les routes de contenu refusent les sessions du super-administrateur.
- Des tests d'accès couvrent **chaque route** de l'API, pour chaque rôle, pour un utilisateur d'une autre organisation, pour le super-administrateur et sans session.
- Le mode démo (§5.16) refuse côté serveur tout envoi de fichier et toute modification des comptes de démo.

**Accès aux médias**
- Fichiers vidéo, miniatures, logos et base de données **hors du dossier public**.
- Chaque média est servi par PHP via une **URL signée** (HMAC, expiration de quelques heures), avec prise en charge des requêtes Range ; délégation possible à `mod_xsendfile` ou à l'équivalent du serveur web.

**Comptes**
- Mots de passe en Argon2id ; session `HttpOnly`, `Secure`, `SameSite=Strict` ; jeton CSRF sur tout envoi ; limitation des tentatives avec un délai croissant.
- Codes de secours de la double authentification : aléatoires, stockés sous forme d'empreinte, à usage unique.
- « Mot de passe oublié » : réponse identique que l'adresse existe ou non, demandes limitées, jeton à usage unique valable 1 heure.
- Changer de mot de passe ou de double authentification ferme les autres sessions de l'utilisateur.
- Invitations : jeton aléatoire stocké sous forme d'empreinte, à usage unique, valable 7 jours.

**Liens protégés par mot de passe** : formulaire neutre, mêmes protections contre les tentatives répétées, cookie limité au lien.

**Suppressions automatiques** (archivage, purge) : confirmation ou préavis, journal, mode d'essai, tests de cohérence fichiers / base.

**Données personnelles (RGPD)**
- Clients : seuls le nom saisi et le texte des révisions sont stockés.
- Utilisateurs des organisations : nom, e-mail, mot de passe (empreinte), double authentification (chiffrée).
- Pas d'IP en clair : une empreinte salée est gardée uniquement pour limiter les abus.
- Données hébergées chez l'hébergeur choisi par l'exploitant. Des **modèles** de mentions légales et de politique de confidentialité sont fournis, à compléter par chaque exploitant.

**Journaux** : jamais de mot de passe, de clé de lien, de jeton d'invitation ni d'adresse IP en clair.

---

## 7. Architecture technique

### 7.1 Choix techniques

| Couche | Choix | Pourquoi |
|---|---|---|
| Serveur | **PHP ≥ 8.2** (développé en 8.3), sans framework (petit routeur maison) | Tourne partout (mutualisé, Apache, conteneur) |
| Base de données | **SQLite** (mode WAL) via PDO | Un seul fichier ; suffisant tant que le trafic reste modéré. À réévaluer lors d'un passage sur serveur dédié |
| Interface | **Svelte 5 + Vite**, compilé en HTML/CSS/JS statiques | Léger ; le serveur n'exécute jamais Node |
| Analyse vidéo | **mp4box.js** + `<video>` / `<canvas>` dans le navigateur ; `ffprobe` sur le serveur si l'encodage serveur est actif | FFmpeg n'est jamais requis |
| Versions de lecture | **mediabunny** (WebCodecs) dans le navigateur, ou **FFmpeg** sur le serveur (facultatif, réglé par le super-administrateur) ; lecture HLS native ou **hls.js** | Choix selon les ressources du serveur |
| E-mails | `mail()` de PHP via le serveur SMTP de l'hébergeur | Aucune dépendance |
| Tâches planifiées | `php app/bin/cron.php`, lancé par le cron de l'hébergeur (toutes les 5 min conseillé avec l'encodage serveur, sinon chaque heure), protégé par un verrou | Archivage automatique, rappels, purges, nettoyages, file d'encodage, avis de nouvelle version, remise à zéro de la démo |
| Polices | Inter, JetBrains Mono, Instrument Serif, Poetsen One, en woff2 hébergés | Confidentialité, rapidité |

- **Dépendances PHP** : aucune en production (TOTP, HMAC, routeur, e-mails écrits à la main). PHPUnit en développement uniquement.
- **Architecture** : PHP sert la coquille HTML de chaque page, avec la personnalisation (organisation ou client) injectée en variables CSS ; l'interface Svelte dialogue avec une **API JSON** (`/api/...`).

### 7.2 Hébergement, installation et mises à jour

- **Où installer belprise** (au choix de l'exploitant) :
  - **hébergement mutualisé** (o2switch en exemple, vérifié en production en 2.x) : archive `.zip`, encodage dans le navigateur conseillé ;
  - **VPS ou NAS avec Docker** : image publiée sur `ghcr.io`, avec FFmpeg inclus, un `docker-compose.yml` d'exemple et la tâche planifiée intégrée ;
  - **serveur Apache 2.4** avec PHP ≥ 8.2 et `pdo_sqlite`, `mbstring`, `fileinfo`, `openssl`.
- **Assistant d'installation** dans le navigateur (`/installation`). Il ne s'ouvre que sans `app/config.php`, et seulement après saisie d'un **code de déverrouillage** que le serveur écrit dans un fichier hors du web (preuve qu'on a la main sur le serveur). La configuration n'est écrite qu'à la dernière étape. Étapes :
  1. vérification de l'environnement, avec des conseils selon l'hébergeur ;
  2. adresse de l'application et expéditeur des e-mails, avec un e-mail de test ;
  3. super-administrateur : e-mail, mot de passe ;
  4. double authentification du super-administrateur ;
  5. première organisation (facultatif) : nom, quota, e-mail de son administrateur (invitation) ;
  6. encodage des versions de lecture : navigateur, ou serveur si FFmpeg est détecté ;
  7. résumé, avec la ligne de tâche planifiée prête à copier (toutes les heures, ou toutes les 5 minutes avec l'encodage sur le serveur).
- **Mise à jour en une commande** (à partir de la 3.0) : `php app/bin/update.php belprise-x.y.z.zip`, avec sauvegarde de la base, remplacement du code en gardant la configuration, contrôle de l'environnement et retour en arrière automatique en cas d'échec. Avec Docker : nouvelle image, les migrations s'appliquent au démarrage.
- La 3.0 ne reprend pas les installations 2.x : on réinstalle.

### 7.3 Arborescence du dépôt (indicative)

```
belprise/
├── CAHIER_DES_CHARGES.md
├── CLAUDE.md                 # consignes pour Claude Code
├── PLAN_V3.md                # phases de la V3
├── README.md, CHANGELOG.md, LICENSE, THIRD_PARTY.md, CONTRIBUTING.md, SECURITY.md
├── DEPLOIEMENT.md            # guides d'installation
├── docker/                   # image Apache + PHP + FFmpeg (production et développement)
├── app/
│   ├── src/                  # Router, Controllers, Services, Repositories
│   ├── migrations/           # SQL versionné
│   ├── bin/                  # cron.php, update.php, check-env.php, dépannage des comptes…
│   └── config.php
├── public/                   # racine web de l'application
├── site/                     # site vitrine du projet (racine web de belprise.fr)
├── frontend/                 # sources Svelte
├── scripts/build-dist.sh
└── data/                     # non versionné, hors racine web
    ├── belprise.sqlite
    ├── media/{organisation}/{client}/{n°}/v{x}/…
    ├── logos/
    ├── uploads/
```

### 7.4 Modèle de données (indicatif)

- `organizations` (id, nom, quota_octets NULL = sans limite, couleurs, logo, totp_obligatoire, déclassée_le, purge_le, dates)
- `users` (id, organization_id, email UNIQUE, nom, mot_de_passe_hash, rôle `admin|editor|viewer` — un seul `admin` par organisation, totp, dernière_connexion, dates)
- `recovery_codes` (id, user_id, code_hash, utilisé_le)
- `account_resets` (id, compte, jeton_hash, type, expire_le, utilisé_le)
- `encode_jobs` (id, version_id, état, avancement, tentatives, dates) — encodage sur le serveur
- `invitations` (id, organization_id, email, rôle, jeton_hash, expire_le, utilisée_le)
- `superadmins` (id, email, mot_de_passe_hash, totp)
- `audit_log` (id, organization_id, acteur, action, détails, date)
- `activity` (id, organization_id, type, client_id, project_id, video_id, version_id, date)
- `clients` (id, **organization_id**, nom, logo, couleurs, texte_accueil, archivé, dates)
- `projects` (id, client_id, nom, archivé, dates)
- `videos` (id, numero, project_id, titre, telechargement_autorise, archivée_le, dates)
- `tags`, `video_tags`
- `versions` (id, video_id, numero, statut, publiée, fichier, nom_original, taille, durée, fps_num, fps_den, drop_frame, tc_depart, largeur, hauteur, codec, couverture, vignette, planche, validée_par, validée_le, dates)
- `collections` (id, organization_id, titre, introduction, couleurs, logo, dates), `collection_videos` (collection_id, video_id, position)
- `links` (id, type `space|client|view|collection`, cible_id, clé UNIQUE, mot_de_passe_hash, expire_le, actif, dates)
- `comments` — les révisions (id, version_id, parent_id, auteur_nom, auteur_jeton_hash, est_admin, frame_in, frame_out NULL, texte, statut `todo|done|refused`, lu, dates, supprimé_le)
- `uploads`, `login_attempts`, `settings` (dont le mode d'encodage et la dernière version connue)

Les timecodes sont **stockés en numéro d'image** (entier) et convertis à l'affichage.

### 7.5 Environnement de développement

- macOS, Docker (Colima) pour Apache et PHP comme en production, Node.js LTS pour Vite.
- `docker compose up` → `http://localhost:8080` ; `npm run dev` pour le rechargement à chaud.
- Données de démonstration : plusieurs organisations, clients, vidéos lisibles et révisions (neutres, sans lien avec de vrais clients).
- **Dépôt GitHub public** (§5.17) ; les envois (`git push`) se font avec l'accord du responsable du projet.
- **Livraison** : `scripts/build-dist.sh` produit `dist/belprise-{version}.zip` ; GitHub Actions produit aussi l'image Docker à chaque version ; les migrations s'appliquent au premier chargement.

---

## 8. Charte graphique

| Élément | Valeur |
|---|---|
| Nom / logo | **belprise.** — police Poetsen One, point en rouge belprod `#ce3c4a` |
| Favicon | **bp.** sur fond sombre `#141416`, point rouge belprod |
| Signature | « belprise. · propulsé par belprod », avec lien vers belprod.fr (bas de page public et bas du menu d'administration) |
| Fond | `#0a0a0b` |
| Surface / surface haute | `#0f0f11` / `#141416` / `#18181b` |
| Bordures | `#232327` / `#303035` |
| Texte / secondaire | `#fafafa` / `#9a9aa0` |
| Rouge belprod (marque) | `#ce3c4a` |
| Accent interface (par défaut, personnalisable par organisation) | `#e8515f` — texte noir sur les boutons |
| Statut En cours | `#4d8dff` |
| Statut À valider | `#a970ff` |
| Modifications demandées / À faire | `#ffd60a` |
| Validé / Fait | `#2ee59d` |
| Révision refusée | `#ff6b6b` |
| Titres | Instrument Serif (italique pour la mise en valeur) |
| Interface | Inter 400 / 500 / 600 |
| Timecodes, n°, libellés techniques | JetBrains Mono — libellés précédés de `//` ; raccourcis en touches dessinées (`kbd`) |
| Rayons | 6 px / 10 px / 12 px ; boutons en pilule (999 px) |

- **Accessibilité** : contraste AA minimum (vérifié aussi pour les couleurs choisies par les organisations et les clients) ; zones cliquables ≥ 44 px ; navigation complète au clavier ; libellés ARIA ; `prefers-reduced-motion` respecté.
- **Responsive** : pages client, collections et vitrine parfaitement utilisables sur téléphone. L'administration est pensée pour un ordinateur et reste consultable sur tablette.

---

## 9. Hors périmètre de la V3

- Filigrane ; plusieurs qualités de lecture (une seule, 720p à 3 Mbit/s).
- Masters `.mkv` / VP9.
- Paiement en ligne, facturation, inscription libre d'une organisation.
- Plusieurs administrateurs dans une même organisation.
- Appartenance d'un utilisateur à plusieurs organisations.
- Reprise des données d'une installation 2.x.
- Mise à jour depuis l'interface web (elle se fait en une commande, ou par une nouvelle image Docker).
- Envoi de fichiers dans la démo publique.
- Dessin et annotation sur l'image.
- Comparaison de versions côte à côte.
- Statistiques de visionnage.
- Export de marqueurs pour Premiere (XML ou CSV), export PDF des révisions.
- Interface en plusieurs langues (français uniquement).
- Notifications par e-mail des nouvelles révisions (seuls les e-mails du §5.14 sont prévus).

---

## 10. Critères d'acceptation

### 10.1 Critères V1 (toujours valables)

1. Un master MP4 de **50 Go** s'importe par le navigateur, reprend après une coupure réseau et apparaît comme nouvelle version avec miniature, planche de miniatures, cadence et timecode de départ corrects.
2. ~~Un fichier déposé dans `inbox/` s'importe depuis l'administration, sans copie.~~ Remplacé en 2.2 : plusieurs fichiers glissés sur un projet créent leurs vidéos et s'envoient à la suite ; un master d'une heure se lit de façon fluide sur un iPhone en 4G (versions de lecture en HLS).
3. Le timecode affiché dans le lecteur correspond **à l'image près** à celui de la timeline de montage d'origine (essais en 25 et 23,976 i/s).
4. Un client révise un instant et une plage depuis un téléphone ; l'organisation voit la révision, répond, la passe en « Fait » ; le client voit la réponse.
5. L'EDL exporté s'importe dans DaVinci Resolve avec les marqueurs placés au bon timecode.
6. Le lien Visualisation n'affiche que la dernière version validée (ou, à défaut, la dernière publiée) et n'expose ni révisions, ni versions, ni téléchargement.
7. Une URL de vidéo modifiée d'un caractère, un lien désactivé ou expiré, et un accès direct au fichier vidéo sans signature renvoient tous la même 404 neutre.
8. `curl -A "GPTBot"` sur n'importe quelle URL de l'application renvoie une erreur 403 ; toutes les réponses de l'application portent l'en-tête `X-Robots-Tag` ; le navigateur ne fait aucune requête vers un domaine tiers.
9. L'archive `dist/` fonctionne, sans autre modification que le fichier de configuration, sur un Apache classique avec PHP 8.2, dans le conteneur Docker et sur l'hébergement retenu.
10. Lighthouse (pages client, collections, vitrine) : Performance ≥ 90, Accessibilité ≥ 95.

### 10.2 Critères V2

11. Deux organisations ne voient jamais leurs données respectives : chaque route de l'API, essayée avec un identifiant de l'autre organisation, renvoie la 404 neutre (tests automatiques).
12. Chaque rôle (Administrateur, Éditeur, Lecteur) ne peut faire que ce que prévoit le §2.2 (tests automatiques par route).
13. Une session de super-administrateur est traitée comme un visiteur anonyme sur toute route de contenu (401 sur l'API, écran de connexion sur les pages) ; le super-administrateur peut changer un quota, déclasser et demander la suppression d'une organisation, qui n'est exécutée qu'à J+30 après e-mail aux administrateurs, et annulable d'ici là (test avec horloge simulée).
14. Une invitation reçue par e-mail permet de créer un compte, une seule fois, pendant 7 jours.
15. Un envoi qui ferait dépasser le quota est refusé avant le premier octet, avec l'espace restant.
16. Un déclassement envoie l'e-mail, bloque les envois, et à J+30 supprime les vidéos les plus anciennes jusqu'à repasser sous le quota, sans rien supprimer de plus (test avec horloge simulée).
17. L'archivage d'une vidéo ne garde que sa dernière version validée (ou, à défaut, publiée), supprime le reste (fichiers et révisions), et la vidéo reste lisible dans la bibliothèque de l'espace client, téléchargeable si elle l'autorise. Une vidéo validée sans nouvelle version ni révision depuis 6 mois est archivée automatiquement (test avec horloge simulée).
18. Une collection de vidéos s'ouvre sur téléphone par son lien, sans révision ni téléchargement, et obéit aux mêmes règles de lien que les autres (404 neutre, non indexée).
19. Une révision refusée l'est avec une explication, visible par le client, en rouge dans la timeline et l'EDL.
20. Le site vitrine est indexable et l'application ne l'est pas.

### 10.3 Critères V3

21. Sur un serveur neuf, l'assistant d'installation mène, sans ligne de commande hors lecture du code de déverrouillage, à une installation qui fonctionne : super-administrateur avec double authentification, première organisation, invitation de son administrateur reçue par e-mail.
22. L'image Docker démarre sur un VPS ou un NAS, ouvre l'assistant, et encode sur le serveur la version de lecture d'un master ProRes sans fichier joint.
23. Avec l'encodage sur le serveur, l'admin peut fermer la page après l'envoi : la version de lecture est prête plus tard ; un encodage coupé en cours reprend au passage suivant de la tâche planifiée (tests avec un faux FFmpeg).
24. « Mot de passe oublié ? » répond la même chose pour une adresse connue ou inconnue, limite les demandes, et le lien reçu ne sert qu'une fois, pendant 1 heure ; il ne désactive jamais la double authentification, qu'un code de secours permet de franchir une seule fois.
25. Une organisation n'a jamais plus d'un administrateur ; le transfert du rôle fait de l'ancien administrateur un Éditeur (tests automatiques).
26. La démo se remet dans son état initial toutes les 6 h, et refuse tout envoi de fichier même par un appel direct à l'API.
27. Une nouvelle version publiée sur belprise.fr est annoncée dans `/superadmin` le lendemain au plus tard ; aucun navigateur ne contacte belprise.fr ; avec `'update_check' => false`, le serveur non plus.
28. L'archive publiée sur GitHub s'installe sur un Apache classique et sur l'hébergement mutualisé de référence, et son empreinte SHA-256 correspond à celle du site vitrine.

---

## 11. Points ouverts

**Décisions** (numérotation du plan de la V2 ; les propositions servent de valeur par défaut)

| # | Question | Proposition |
|---|---|---|
| Q1 | Refus : explication obligatoire ? | Oui (réalisé) |
| Q2 | EDL : exporter les refus en rouge ? | Oui (réalisé) |
| Q3 | Qu'est-ce qu'une « activité » pour les 6 mois ? | **Décidé** : nouvelle version ou nouvelle révision |
| Q4 | Prévenir avant un archivage automatique ? | **Décidé** : non |
| Q5 | Archiver une vidéo sans version validée ? | **Décidé** : oui, en gardant la dernière version publiée |
| Q6 | Téléchargement dans la bibliothèque ? | **Décidé** : selon le réglage de la vidéo |
| Q7 | Collection : à défaut de version validée, la dernière publiée ? | **Décidé** : oui |
| Q8 | Collection : téléchargement ? | **Décidé** : non, lecture seule |
| Q9 | Collection : vidéos non validées admises ? | **Décidé** : oui, avec la dernière version publiée |
| Q10 | Numérotation des vidéos | **Décidé** : propre à chaque organisation |
| Q11 | Utilisateurs du forfait gratuit | **Révisé (3.0)** : plus de forfaits ni de limite d'utilisateurs |
| Q12 | Qui supprime une organisation ? | **Décidé** : son administrateur ou le super-administrateur, exécution à J+30 |
| Q12b | Pendant le délai de suppression : liens publics actifs ? Qui annule une suppression demandée par le super-administrateur ? | **Décidé** : liens actifs ; seul le super-administrateur peut l'annuler |
| Q13 | Inscription gratuite libre dès l'ouverture ? | **Révisé (3.0)** : non — logiciel auto-hébergé, organisations créées par le super-administrateur ; la bêta disparaît |
| Q14 | Purge : « plus ancienne » selon la création ou la dernière activité ? | **Décidé** : dernière activité |
| Q15 | Purge : ordre des vidéos archivées ? | **Décidé** : archivées en premier |
| Q16 | Domaines ? | **Révisé (3.0)** : `belprise.fr` (vitrine du projet), `demo.belprise.fr` (démo), `app.belprise.fr` (installation de belprod) |

Les décisions de la V3 (D1 à D14 : licence, GitHub, nom interne, double authentification, mot de passe oublié, encodage, formats, avis de version, langue, quota, nouveau départ, démo, organisations, réglage de l'encodage) sont détaillées dans [PLAN_V3.md](PLAN_V3.md).

**Autres points**
- Validation du format EDL dans Resolve par un essai réel (critère 5).
- Modèles de textes légaux (mentions, confidentialité) fournis aux exploitants ; textes du site vitrine et de la démo.
- Compte GitHub du projet (P4).
- Sous-domaine `demo.belprise.fr` sur o2switch (P6).
