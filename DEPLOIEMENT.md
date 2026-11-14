# Installer belprise

belprise s'installe sur votre propre serveur. Choisissez le cas qui vous correspond :

| Cas | Pour qui | Encodage des versions de lecture | Suivre |
|---|---|---|---|
| **Hébergement mutualisé** (cPanel : o2switch, PlanetHoster, Infomaniak…) | Le plus simple, sans administrer de serveur | Dans le navigateur | [§1](#1-hébergement-mutualisé-cpanel) |
| **Docker** (VPS, NAS Synology / QNAP…) | Un serveur à vous, FFmpeg inclus | Navigateur ou serveur | [§2](#2-docker-vps-nas) |
| **Serveur Apache** (Debian, Ubuntu…) | Vous administrez déjà un serveur web | Navigateur, ou serveur si FFmpeg est installé | [§3](#3-serveur-apache-debian-ubuntu) |

Dans tous les cas, il faut :
- **une adresse dédiée à l'application**, par exemple `video.mon-agence.fr`. Elle n'est jamais indexée par les moteurs de recherche : ne la partagez pas avec un site public ;
- **HTTPS** ;
- **l'envoi d'e-mails** (invitations, mots de passe oubliés) : la fonction `mail()` de PHP de l'hébergeur, ou un serveur SMTP avec Docker ;
- **une tâche planifiée** (archivage automatique, rappels) : un cron chaque heure, déjà intégré à l'image Docker.

L'installation se termine toujours par l'**assistant dans le navigateur** (`https://<adresse>/installation`). Il :
1. vérifie le serveur ;
2. enregistre l'adresse et l'expéditeur des e-mails, avec un e-mail de test ;
3. crée le **compte d'exploitation** (le super-administrateur, avec une double authentification obligatoire) ;
4. peut créer la première organisation et inviter son administrateur ;
5. fait choisir où préparer les **versions de lecture** : dans le navigateur, ou sur le serveur si FFmpeg est présent ;
6. donne la tâche planifiée à mettre en place.

Pour prouver que vous avez la main sur le serveur, il demande un **code de déverrouillage** que le serveur écrit dans `data/installation-code.txt`. Rien n'est enregistré avant la dernière étape.

**L'archive** `belprise-{version}.zip` contient :

```
belprise-3.0.0/
├── app/       code PHP, hors de la racine web
├── public/    racine web de l'application
├── data/      vide : base SQLite, vidéos, sessions (hors de la racine web)
├── docker/    image Docker (Dockerfile.release, compose.yml)
├── site/      site vitrine de belprise.fr (inutile pour votre installation)
└── DEPLOIEMENT.md, VERSION
```

---

## 1. Hébergement mutualisé (cPanel)

L'exemple suivi est **o2switch**, sur lequel belprise est en production ; les menus sont les mêmes chez les autres hébergeurs cPanel.

> **o2switch : utilisez une « lune »** (un sous-compte isolé, gratuit, dans « Mon Univers Web »). Les vidéos de vos clients et la clé secrète de belprise sont ainsi séparées de vos autres sites, et la consommation de belprise (disque, bande passante) se lit à part. Ouvrez ensuite le cPanel de la lune : tout se fait là.

### 1.1 Adresse et HTTPS

1. **Domaines → Domaines configurés** : ajoutez `video.mon-agence.fr` avec la racine `belprise/public`. cPanel crée le dossier vide ; l'étape 1.2 le remplit.
2. Si le domaine n'est pas géré par l'hébergeur, créez chez votre bureau d'enregistrement un enregistrement `A` vers l'adresse IP indiquée dans cPanel (« Shared IP Address »).
3. **Sécurité → Let's Encrypt™ SSL** : émettez le certificat de `video.mon-agence.fr` une fois le DNS propagé.

La redirection de `http://` vers `https://` est faite par `public/.htaccess` : le bouton « Forcer HTTPS » de cPanel n'est pas nécessaire (sur o2switch, il ne s'enregistre pas toujours).

### 1.2 Déposer les fichiers

Avec le **Gestionnaire de fichiers** : déposez l'archive dans votre dossier personnel, faites « Extract », puis renommez `belprise-3.0.0` en `belprise`. Ou dans **Avancé → Terminal** :

```sh
cd ~
unzip -q belprise-3.0.0.zip
mkdir -p belprise && cp -a belprise-3.0.0/. belprise/ && rm -rf belprise-3.0.0
chmod 700 belprise/data
```

> **Fichiers cachés** : le Finder du Mac et certains clients FTP n'envoient pas les fichiers qui commencent par un point. Vérifiez que `public/.htaccess` et `public/.user.ini` sont bien sur le serveur : sans eux, ni la redirection HTTPS ni les règles de sécurité ne s'appliquent. Le Terminal ou l'extraction dans le Gestionnaire de fichiers n'ont pas ce problème.

`app/` et `data/` sont **hors de la racine web** : rien de ce qu'ils contiennent n'est accessible par le navigateur.

### 1.3 PHP

**Logiciel → Sélectionner une version de PHP** :
- **version 8.3** (8.2 au minimum) ;
- **extensions** : `pdo_sqlite`, `sqlite3`, `mbstring`, `fileinfo`, `openssl`, `iconv`, `ctype` (souvent déjà cochées) ;
- **onglet Options** : `memory_limit` 256M, `upload_max_filesize` et `post_max_size` 48M, `max_execution_time` et `max_input_time` 300. Les vidéos sont envoyées en morceaux de 32 Mo.

Dans le Terminal, `php -v` doit afficher la même version. Sinon, utilisez dans les commandes le chemin complet de cette version, par exemple `/opt/alt/php83/usr/bin/php` (`ls /opt/alt/` liste les versions).

### 1.4 L'assistant d'installation

1. Ouvrez `https://video.mon-agence.fr/installation`. Corrigez ce qui est signalé en rouge, puis rechargez.
2. Ouvrez `belprise/data/installation-code.txt` dans le Gestionnaire de fichiers, et recopiez le code.
3. Suivez les sept étapes. À l'étape « Versions de lecture », gardez « Dans le navigateur » (voir §1.7). Pour la double authentification, le code à 6 chiffres se tape sans espace.
4. À la fin, **gardez une copie de `belprise/app/config.php` hors du serveur** (dans un gestionnaire de mots de passe, par exemple) : sa clé secrète protège la double authentification de tous les comptes.

### 1.5 Tâche planifiée

**Avancé → Tâches Cron** : renseignez votre adresse dans « E-mail de notification » (pour recevoir les erreurs), puis ajoutez une tâche **une fois par heure**. La commande exacte est affichée à la fin de l'assistant :

```
0 * * * * php /home/<compte>/belprise/app/bin/cron.php > /dev/null
```

Avec l'encodage sur le serveur, la tâche passe **toutes les 5 minutes** (`*/5 * * * *`) ; un verrou empêche deux passages simultanés. Essai sans rien modifier : `php ~/belprise/app/bin/cron.php --dry-run`.

### 1.6 E-mails (SPF, DKIM, DMARC)

Pour que les invitations n'arrivent pas en indésirables :
1. **E-mail → Email Deliverability** : installez les enregistrements **SPF** et **DKIM** proposés pour votre domaine.
2. Ajoutez un enregistrement **DMARC**, au minimum `_dmarc TXT "v=DMARC1; p=none; rua=mailto:contact@mon-agence.fr"`.
3. L'expéditeur choisi dans l'assistant doit être une adresse de ce domaine. belprise l'utilise aussi comme expéditeur d'enveloppe (`-f`), ce qui aligne SPF et DMARC.
4. Testez : invitez une adresse Gmail, puis « Afficher l'original » : SPF, DKIM et DMARC doivent être à **PASS**.

### 1.7 À ne pas activer pour l'application

- **Caches de pages** (LiteSpeed LsCache, Tiger Cache…) : l'application n'envoie que des réponses privées.
- **X-Sendfile** : laissez `'xsendfile' => false`. Sur o2switch, le serveur web ne sait pas envoyer les fichiers à la place de PHP de façon sûre (vérifié). PHP envoie les vidéos, par morceaux de 8 Mo au plus.
- **ModSecurity** : laissez-le actif. Si un usage normal renvoie une erreur 403 inattendue, désactivez-le pour cette adresse seulement.
- **Encodage sur le serveur** : sur un mutualisé, il est généralement coupé au-delà d'un processeur. Sur o2switch, avec un seul fil, il faut environ 21 min par heure de vidéo. L'encodage dans le navigateur, réglage par défaut, reste préférable. Si vous l'essayez quand même avec un FFmpeg autonome déposé dans votre dossier, indiquez son chemin dans la configuration (`'ffmpeg' => '/home/<compte>/bin/ffmpeg'`, `ffprobe` à côté), gardez 1 fil et mesurez avec `php app/bin/encode-test.php`.

---

## 2. Docker (VPS, NAS)

L'image contient Apache, PHP 8.3, **FFmpeg** (encodage sur le serveur : choisissez-le à l'étape « Versions de lecture » de l'assistant, ou plus tard dans `/superadmin`) et la tâche planifiée, lancée toutes les 5 minutes. Avec l'encodage sur le serveur, les masters ProRes et MXF s'envoient tels quels : le serveur lit leurs informations, prépare la version de lecture et fait les miniatures. Tout ce qui doit survivre à une mise à jour est dans un seul volume : base, vidéos, sessions **et configuration**.

### 2.1 Démarrer

Le fichier `docker/compose.yml` de l'archive :

```yaml
services:
  belprise:
    image: ghcr.io/belprod/belprise:3
    ports:
      - "8080:80"
    volumes:
      - belprise-data:/var/www/belprise/data
    environment:
      BELPRISE_SMTP_HOST: smtp.example.org
      BELPRISE_SMTP_PORT: "587"
      BELPRISE_SMTP_USER: no-reply@example.org
      BELPRISE_SMTP_PASSWORD: change-me
    restart: unless-stopped

volumes:
  belprise-data:
```

```sh
docker compose up -d
docker compose exec belprise cat /var/www/belprise/data/installation-code.txt
```

Ouvrez ensuite `http://<serveur>:8080/installation`, ou l'adresse HTTPS de votre proxy (§2.3).

### 2.2 E-mails

Un conteneur n'a pas de serveur de messagerie : belprise passe par le serveur SMTP de votre messagerie (votre hébergeur de mails, Brevo, Mailjet…). La fonction `mail()` de PHP lui confie les messages par l'intermédiaire de `msmtp`.

| Variable | Valeur |
|---|---|
| `BELPRISE_SMTP_HOST` | serveur SMTP (sans elle, aucun e-mail ne part) |
| `BELPRISE_SMTP_PORT` | `587` par défaut |
| `BELPRISE_SMTP_USER`, `BELPRISE_SMTP_PASSWORD` | identifiants, si le serveur en demande |
| `BELPRISE_SMTP_TLS` | `starttls` (par défaut, port 587), `tls` (port 465) ou `off` |

L'étape 2 de l'assistant envoie un e-mail de test : vérifiez sa réception avant de continuer.

### 2.3 HTTPS et proxy

Placez un proxy HTTPS devant le port 8080 : Caddy, Traefik, Nginx Proxy Manager, ou le proxy inverse du NAS (Synology : « Portail de connexion », QNAP : « Proxy inverse »). Une fois installé, dites à belprise de faire confiance à ce proxy, pour qu'il voie la vraie adresse des visiteurs (limitation des tentatives de connexion) :

```sh
docker compose exec belprise sh -c 'vi /var/www/belprise/data/config.php'
#   'trusted_proxies' => ['172.18.0.1'],   l'adresse du proxy, vue depuis le conteneur
#   'hsts' => true,                        une fois le HTTPS vérifié
```

N'exposez sur Internet que le port 443 du proxy, jamais le port 8080.

### 2.4 Sauvegarde et mise à jour

- **Sauvegarde** : le volume `belprise-data` contient tout. Pour une copie cohérente de la base pendant que belprise tourne :
  ```sh
  docker compose exec -u www-data belprise php -r '$d=new PDO("sqlite:/var/www/belprise/data/belprise.sqlite"); $d->exec("VACUUM INTO \"/var/www/belprise/data/belprise-copie.sqlite\"");'
  ```
- **Mise à jour** : `docker compose pull && docker compose up -d`. Les migrations s'appliquent au démarrage ; la configuration reste dans le volume.

---

## 3. Serveur Apache (Debian, Ubuntu)

Il faut Apache 2.4 avec `mod_rewrite` et `mod_headers`, et PHP 8.2 ou plus avec `pdo_sqlite`, `mbstring`, `fileinfo` et `openssl`. Aucun Composer, aucun Node.js.

```sh
sudo apt install apache2 libapache2-mod-php php-sqlite3 php-mbstring unzip
sudo apt install ffmpeg                       # facultatif : encodage sur le serveur
sudo a2enmod rewrite headers

sudo unzip -q belprise-3.0.0.zip -d /var/www && sudo mv /var/www/belprise-3.0.0 /var/www/belprise
sudo chown -R www-data:www-data /var/www/belprise/data && sudo chmod 770 /var/www/belprise/data
sudo chown www-data /var/www/belprise/app      # l'assistant y écrit app/config.php (reprendre root:root ensuite)

sudo cp /var/www/belprise/docker/apache/belprise.conf /etc/apache2/sites-available/belprise.conf
sudo cp /var/www/belprise/docker/apache/security.conf /etc/apache2/conf-available/zz-belprise-security.conf
sudo a2ensite belprise && sudo a2enconf zz-belprise-security && sudo a2dissite 000-default
sudo systemctl reload apache2
```

- **Hôte virtuel** : `belprise.conf` suppose `/var/www/belprise`. Ajoutez `ServerName video.mon-agence.fr`. `AllowOverride All` est indispensable : les règles de sécurité sont dans `public/.htaccess`.
- **HTTPS** : `sudo apt install certbot python3-certbot-apache && sudo certbot --apache -d video.mon-agence.fr`.
- **Assistant** : ouvrez `https://video.mon-agence.fr/installation`, avec le code de `sudo cat /var/www/belprise/data/installation-code.txt`. Ensuite, rendez `app/` à root : `sudo chown root:root /var/www/belprise/app && sudo chown root:www-data /var/www/belprise/app/config.php && sudo chmod 640 /var/www/belprise/app/config.php`.
- **Tâche planifiée** : `sudo crontab -u www-data -e` → `0 * * * * php /var/www/belprise/app/bin/cron.php > /dev/null` (`*/5 * * * *` avec l'encodage sur le serveur).
- **Encodage sur le serveur** : avec le paquet `ffmpeg`, choisissez-le dans l'assistant ou dans `/superadmin`, puis réglez le nombre de fils selon le serveur ; `php app/bin/encode-test.php` mesure la vitesse.
- **E-mails** : `mail()` passe par le `sendmail` du serveur (Postfix, ou `msmtp-mta` relié à un SMTP).
- **X-Sendfile** (facultatif) : avec `libapache2-mod-xsendfile`, mettez `'xsendfile' => true` ; `XSendFilePath` doit couvrir `data/`.

---

## 4. Après l'installation (tous les cas)

### 4.1 Vérifier

- **Sur le serveur** : `php app/bin/check-env.php` (Docker : `docker compose exec belprise php app/bin/check-env.php`). Aucune ligne `[ÉCHEC]` ; les `[à voir]` sont des recommandations.
- **Depuis l'extérieur** (téléphone en 4G, par exemple) :
  ```sh
  B=https://video.mon-agence.fr
  curl -sI $B/admin | grep -iE 'x-robots-tag|content-security-policy|strict-transport'
  curl -s -o /dev/null -w '%{http_code}\n' -A 'GPTBot' $B/admin      # 403 : robots d'IA refusés
  curl -s -o /dev/null -w '%{http_code}\n' $B/abcdefghij              # 404 : clé inconnue
  curl -s -o /dev/null -w '%{http_code}\n' $B/app/config.php          # 404
  curl -s -o /dev/null -w '%{http_code}\n' $B/data/belprise.sqlite    # 404
  ```
- Une fois le HTTPS vérifié, passez `'hsts' => true` dans la configuration.

### 4.2 Sauvegardes

Sauvegardez `data/` (base **et** vidéos) et la configuration. Pour une copie cohérente de la base chaque nuit, ajoutez une tâche planifiée quotidienne (`30 3 * * *`) :

```
cd /home/<compte>/belprise && mkdir -p data/backups && php -r '$d=new PDO("sqlite:data/belprise.sqlite"); @unlink("data/backups/belprise-".date("N").".sqlite"); $d->exec("VACUUM INTO \"data/backups/belprise-".date("N").".sqlite\"");' > /dev/null
```

Une copie par jour de la semaine est ainsi gardée. Pour restaurer : remplacez `data/belprise.sqlite` par une copie, après avoir supprimé `belprise.sqlite-wal` et `belprise.sqlite-shm`.

### 4.3 Comptes : en cas de perte d'accès

| Situation | Solution |
|---|---|
| Un membre a oublié son mot de passe | « Mot de passe oublié ? » sur l'écran de connexion, ou réinitialisation par l'administrateur (page Équipe) |
| L'administrateur d'une organisation a oublié son mot de passe | « Mot de passe oublié ? », ou réinitialisation par le super-administrateur (`/superadmin`) |
| Téléphone de double authentification perdu | Un code de secours, à l'écran du code ; sinon, réinitialisation par l'administrateur ou le super-administrateur |
| Le super-administrateur a oublié son mot de passe | Sur le serveur : `php app/bin/reset-password.php <e-mail>` |
| En dernier recours, pour un compte d'organisation | `php app/bin/reset-password.php <e-mail>` ou `php app/bin/disable-totp.php <e-mail>` |

Avec Docker, préfixez les commandes par `docker compose exec -u www-data belprise`.

---

## 5. Mise à jour

**Docker** : `docker compose pull && docker compose up -d`.

**Archive** (mutualisé, Apache) : une seule commande, depuis le dossier de l'installation. `data/` et la configuration ne sont jamais touchés.

```sh
cd ~/belprise
php app/bin/update.php ~/belprise-3.0.1.zip          # --check : vérifie l'archive sans rien modifier
```

La commande :
1. vérifie l'archive ;
2. copie la base (`data/avant-maj-<version>.sqlite`) ;
3. remplace `app/`, `public/` et `site/` en gardant la configuration ;
4. applique les migrations et contrôle l'environnement avec le nouveau code.

**Au moindre échec, tout est remis comme avant**, base comprise. L'ancien code reste dans `.avant-maj-<version>/` : supprimez ce dossier quand tout va bien. Rechargez ensuite l'administration (Cmd + Maj + R).

> La 3.0 est un nouveau départ : une installation 2.x ne se met pas à jour en 3.0, elle se réinstalle.

---

## 6. Checklist

- [ ] Adresse dédiée, en HTTPS ; `hsts` activé une fois vérifié.
- [ ] Assistant terminé ; copie de la configuration (clé secrète) **hors du serveur**.
- [ ] Double authentification du super-administrateur testée (déconnexion, reconnexion avec le code).
- [ ] E-mail de test reçu ; SPF, DKIM et DMARC à PASS (mutualisé) ou SMTP vérifié (Docker).
- [ ] Tâche planifiée en place (cron horaire, ou intégrée avec Docker) ; `cron.php --dry-run` essayé.
- [ ] `check-env.php` sans `[ÉCHEC]` ; vérifications depuis l'extérieur faites (§4.1).
- [ ] Sauvegardes de `data/` et de la configuration prévues.
- [ ] Docker : seul le port 443 du proxy est exposé ; `trusted_proxies` renseigné.
