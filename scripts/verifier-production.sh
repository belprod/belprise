#!/usr/bin/env bash
# Checks a belprise installation from the outside (from any computer, with curl):
#   scripts/verifier-production.sh https://app.belprise.fr https://belprise.fr [https://demo.belprise.fr]
# Read only: GET requests, and on the demo one upload request that must be refused;
# nothing is created. Exit code 1 if a check fails.
set -uo pipefail

APP="${1:-https://app.belprise.fr}"
SITE="${2:-https://belprise.fr}"
DEMO="${3:-}"
FAIL=0

ok()   { printf '[ok]     %s\n' "$1"; }
ko()   { printf '[ÉCHEC]  %s\n' "$1"; FAIL=1; }
info() { printf '[info]   %s\n' "$1"; }
status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
expect() { # label, expected status, curl args…
  local label="$1" want="$2"; shift 2
  local got; got="$(status "$@")"
  [ "$got" = "$want" ] && ok "$label ($got)" || ko "$label : $got au lieu de $want"
}
redirects() { # label, https url
  case "$2" in https://*) ;; *) info "$1 : vérifié seulement en HTTPS"; return ;; esac
  local got; got="$(status "${2/https:/http:}")"
  case "$got" in 301|302|307|308) ok "$1 ($got)" ;; *) ko "$1 : $got (vérifiez que le .htaccess à jour est bien sur le serveur)" ;; esac
}

echo "Application : $APP"
H="$(curl -sI "$APP/admin")"
info "serveur : $(printf '%s' "$H" | grep -i '^server:' | tr -d '\r' | cut -d' ' -f2- || echo inconnu)"
expect 'page de connexion' 200 "$APP/admin"
for h in 'x-robots-tag: .*noindex.*noai' 'content-security-policy: .*default-src' 'referrer-policy: no-referrer' 'x-content-type-options: nosniff' 'x-frame-options: deny'; do
  printf '%s' "$H" | grep -qiE "^$h" && ok "en-tête $h" || ko "en-tête manquant : $h"
done
printf '%s' "$H" | grep -qi '^strict-transport-security' && ok 'HSTS' || info "HSTS absent (à activer : 'hsts' => true, une fois le HTTPS vérifié)"
printf '%s' "$H" | grep -qi '^x-powered-by' && ko 'X-Powered-By visible (expose_php)' || ok 'pas de X-Powered-By'
expect 'robot d’IA refusé' 403 -A 'GPTBot' "$APP/admin"
expect 'moteur de recherche refusé' 403 -A 'Googlebot' "$APP/admin"
curl -s "$APP/robots.txt" | grep -q 'Disallow: /' && ok 'robots.txt : tout est interdit' || ko 'robots.txt de l’application'
expect 'clé de lien inconnue : 404' 404 "$APP/abcdefghij"
expect 'média sans signature : 404' 404 "$APP/media/media/1/1001/v1/master.mp4"
expect 'app/config.php inaccessible' 404 "$APP/app/config.php"
expect 'base de données inaccessible' 404 "$APP/data/belprise.sqlite"
# Hidden files: 404 from belprise, or a 403 from the host for every dot path (o2switch).
# Either way, an existing file must answer exactly like a missing one.
same() { # label, existing path, missing path
  local a b; a="$(status "$APP$2")"; b="$(status "$APP$3")"
  if [ "$a" = "$b" ] && { [ "$a" = 404 ] || [ "$a" = 403 ]; }; then ok "$1 ($a, comme un fichier absent)"; else ko "$1 : $a, contre $b pour un fichier absent"; fi
}
same 'fichier caché inaccessible' /.htaccess /.existe-pas-xyz
same 'manifeste de build inaccessible' /assets/.vite/manifest.json /assets/.existe-pas/x.json
expect 'plus de demande d’accès bêta (3.0)' 404 "$APP/inscription"
curl -s "$APP/api/admin/session" | grep -q '"demo":null' && ok 'pas en mode démo' || ko 'l’application de production est en mode démo'
a="$(curl -s "$APP/abcdefghij")"; b="$(curl -s "$APP/zyxwvutsrq")"
[ "$a" = "$b" ] && ok '404 neutre identique d’une clé à l’autre' || ko '404 différentes selon la clé'
redirects 'HTTP redirigé vers HTTPS' "$APP/admin"

echo
echo "Site vitrine : $SITE"
S="$(curl -sI "$SITE/")"
expect 'accueil' 200 "$SITE/"
printf '%s' "$S" | grep -qi '^x-robots-tag: .*noindex' && ko 'la vitrine envoie noindex' || ok 'vitrine indexable (pas de noindex)'
printf '%s' "$S" | grep -qi '^content-security-policy' && ok 'CSP de la vitrine' || ko 'CSP de la vitrine'
expect 'sitemap.xml' 200 "$SITE/sitemap.xml"
curl -s "$SITE/robots.txt" | grep -q 'GPTBot' && ok 'robots.txt : robots d’IA refusés' || ko 'robots.txt de la vitrine'
expect 'page inconnue : 404' 404 "$SITE/page-inconnue"
for page in telecharger documentation versions; do expect "page $page" 200 "$SITE/$page/"; done
curl -s "$SITE/versions.json" | grep -q '"version"' && ok 'versions.json (avis de nouvelle version)' || ko 'versions.json absent ou illisible'
redirects 'HTTP redirigé vers HTTPS' "$SITE/"

if [ -n "$DEMO" ]; then
  echo
  echo "Démo : $DEMO"
  D="$(curl -sI "$DEMO/admin")"
  expect 'page de connexion' 200 "$DEMO/admin"
  for h in 'x-robots-tag: .*noindex.*noai' 'content-security-policy: .*default-src' 'referrer-policy: no-referrer'; do
    printf '%s' "$D" | grep -qiE "^$h" && ok "en-tête $h" || ko "en-tête manquant : $h"
  done
  J="$(curl -s "$DEMO/api/admin/session")"
  printf '%s' "$J" | grep -q '"demo":{' && ok 'mode démo actif' || ko 'mode démo inactif'
  printf '%s' "$J" | grep -q 'demo.belprise.fr\|"accounts":\[{' && ok 'comptes affichés sur l’écran de connexion' || ko 'comptes de démo absents'
  printf '%s' "$J" | grep -q 'CC BY' && ok 'crédit des films' || ko 'crédit des films absent'
  expect '/superadmin fermé' 404 "$DEMO/superadmin"
  expect 'envoi de fichier refusé' 403 -X POST -H 'Content-Type: application/json' -d '{}' "$DEMO/api/admin/uploads"
  expect 'envoi de logo refusé' 403 -X POST "$DEMO/api/admin/organization/logo"
  expect 'clé de lien inconnue : 404' 404 "$DEMO/abcdefghij"
  redirects 'HTTP redirigé vers HTTPS' "$DEMO/admin"
fi

echo
[ "$FAIL" = 0 ] && echo 'Tout est en ordre.' || echo 'Au moins une vérification a échoué.'
exit "$FAIL"
