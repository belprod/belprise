# La démo publique

`demo.belprise.fr` est une installation ordinaire de belprise, en **mode démo** (cahier des charges §5.16) :

- trois comptes partagés (administrateur, éditeur, lecteur), affichés sur l'écran de connexion, mot de passe `belprise-demo` ;
- **aucun envoi de fichier** (vidéo, version de lecture, image, logo) : le serveur refuse ces appels, même directs, et l'interface masque les boutons et le glisser-déposer ;
- aucun e-mail envoyé ; comptes, équipe et organisation non modifiables (mot de passe, e-mail, double authentification, rôles, invitations, transfert, suppression) ;
- `/superadmin` fermé ;
- **remise à zéro toutes les 6 heures** (0 h, 6 h, 12 h, 18 h, heure de Paris) par la tâche planifiée : la base et les fichiers reviennent à l'instantané, et les dates sont avancées pour que la démo paraisse toujours récente. Un bandeau indique le temps restant.

Tout le reste fonctionne : réviser, répondre, valider, changer des statuts, créer des clients, projets, vidéos et collections, gérer les liens, exporter l'EDL.

Les vidéos sont des extraits des films libres de la Blender Foundation (Big Buck Bunny, Sintel, Tears of Steel), sous licence CC BY 3.0. Le crédit est affiché sur l'écran de connexion et dans le bandeau. Clients, personnes et textes du jeu de démonstration sont fictifs.

## Construire le jeu de démonstration (sur le Mac)

FFmpeg et PHP 8.2 ou plus sont nécessaires sur le Mac (`brew install ffmpeg php`).

1. Préparer les extraits : `scripts/demo-media.sh`. Le script télécharge une fois les films (environ 2 Go, dans `dist/demo/sources`), puis écrit les douze masters dans `dist/demo/extraits`.
2. Créer une configuration de démo, par exemple `dist/demo/config.php` :

   ```php
   <?php
   return [
       'data_dir' => __DIR__ . '/data',
       'secret' => '…64 caractères aléatoires (openssl rand -hex 32)…',
       'demo' => true,
       'update_check' => false,
       'app_url' => 'https://demo.belprise.fr',
   ];
   ```

3. Construire : `BELPRISE_CONFIG=$PWD/dist/demo/config.php php app/bin/demo.php build`. Le script crée l'organisation fictive, les comptes, les clients, les versions, les révisions et les liens, prépare les versions de lecture et les miniatures avec FFmpeg, puis prend l'instantané (`data/demo-snapshot/`). Il affiche les liens partagés.

Pour voir le résultat en local : `BELPRISE_CONFIG=$PWD/dist/demo/config.php php -S 127.0.0.1:8092 -t public scripts/demo-router.php`, après `npm run build` (le petit routeur sert les fichiers de `public/` et passe le reste à l'application).

## Mettre la démo en ligne (o2switch)

1. Créer le sous-domaine `demo.belprise.fr` dans cPanel, avec son propre dossier, et son certificat.
2. Y décompresser l'archive `belprise-x.y.z.zip` comme pour une installation neuve (voir [DEPLOIEMENT.md](../DEPLOIEMENT.md)), **sans lancer l'assistant d'installation** : placer directement `app/config.php` (la configuration ci-dessus, avec le `data_dir` du serveur et un nouveau secret) et copier tout le dossier `data/` construit sur le Mac (base, médias, logos et `demo-snapshot/`).
3. Tâche planifiée du sous-domaine, toutes les 10 minutes pour que la remise à zéro tombe à l'heure :
   `*/10 * * * * php /home/<compte>/demo.belprise.fr/app/bin/cron.php`
   En mode démo, `cron.php` ne fait que la remise à zéro, quand elle est due.
4. Vérifier : l'écran de connexion montre les trois comptes, `/superadmin` renvoie la page 404, et `php app/bin/demo.php reset` remet tout en place immédiatement.

Après une mise à jour de belprise, l'instantané reçoit les nouvelles migrations à la remise à zéro suivante. Pour changer le jeu de démonstration, le modifier dans la démo puis lancer `php app/bin/demo.php snapshot` : c'est le nouvel état de référence.
