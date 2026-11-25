#!/usr/bin/env bash
# Passe rapide de Pest : copie le code dans le système de fichiers PROPRE du conteneur `odc-backend`, puis y lance `php artisan test`.
#
# POURQUOI : en dev, `./backend` est monté dans le conteneur depuis le disque Windows (`/mnt/d`, pont 9P) : chaque ouverture de fichier PHP en paie le prix.
# Mesuré le 25/11/2026, même commit, même machine : la suite complète prend 294 à 299 s sur ce montage et 48 s sur une copie interne au conteneur (÷ 6).
# `vendor/` est déjà dans un volume Docker (rapide) ; il est copié UNE fois dans la copie, car l'autoloader de Composer résout le code applicatif à partir du dossier qui
# contient `vendor/` — un lien symbolique le ferait relire depuis le montage lent.
#
# Usage :   bash scripts/test-fast.sh                           toute la suite
#           bash scripts/test-fast.sh tests/Feature/Security    un dossier ou un fichier
#           bash scripts/test-fast.sh --filter=CustomsProvince  tout argument est passé à `php artisan test`
# Variable : ODC_FAST_CONTAINER = nom du conteneur (défaut : odc-backend) ; ODC_FAST_DIR = dossier de la copie dans le conteneur (défaut : /tmp/odc-be).
# Code de sortie : celui de `php artisan test`.
#
# Ce que le script ne fait PAS : il ne modifie jamais le dossier de travail ni la base de dev (les tests tournent sur SQLite en mémoire, `APP_ENV=testing` imposé par
# phpunit.xml), il ne remplace pas la CI (la référence), et il ne copie ni `.git`, ni `node_modules`, ni les caches de configuration / de routes / de vues, ni les journaux.
# La copie vit dans `/tmp` du conteneur : elle disparaît quand le conteneur est recréé, et la passe suivante la refait toute seule.

set -uo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTAINER="${ODC_FAST_CONTAINER:-odc-backend}"
DEST="${ODC_FAST_DIR:-/tmp/odc-be}"

die() { echo "✗ $*" >&2; exit 2; }
secs() { awk -v a="$1" -v b="$2" 'BEGIN{printf "%.1f", b-a}'; }
now() { date +%s.%N; }
dx() { docker exec "$CONTAINER" "$@"; }

# ── garde-fous ──
command -v docker >/dev/null || die "docker est introuvable (Docker Desktop doit être lancé et intégré à WSL)."
[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" = "true" ] \
  || die "le conteneur $CONTAINER ne tourne pas (docker compose -f docker-compose.dev.yml up -d db backend)."
case "$DEST" in
  /tmp/*|/var/tmp/*) ;;
  *) die "ODC_FAST_DIR doit être sous /tmp dans le conteneur (reçu : $DEST) : on ne vide jamais un autre dossier." ;;
esac
[ -f "$SRC/artisan" ] && [ -f "$SRC/composer.lock" ] || die "$SRC n'est pas le dossier du backend (artisan ou composer.lock manquant)."

T_ALL=$(now)

# ── 1. code : on vide la copie (sauf vendor/) puis on y renvoie l'arbre courant — une suppression côté source disparaît donc aussi de la copie ──
t0=$(now)
dx sh -c "mkdir -p '$DEST' && find '$DEST' -mindepth 1 -maxdepth 1 ! -name vendor -exec rm -rf {} +" || die "impossible de préparer $DEST dans le conteneur."
tar -C "$SRC" -cf - \
    --exclude=./vendor --exclude=./node_modules --exclude=./.git \
    --exclude=./storage/logs --exclude=./storage/framework/views --exclude=./storage/framework/cache \
    --exclude=./bootstrap/cache/config.php --exclude='./bootstrap/cache/routes-*.php' --exclude=./bootstrap/cache/events.php \
    . | docker exec -i "$CONTAINER" tar -xf - -C "$DEST" || die "l'envoi du code a échoué."
dx sh -c "cd '$DEST' && mkdir -p storage/logs storage/framework/views storage/framework/cache/data storage/framework/sessions storage/framework/testing bootstrap/cache" || die "dossiers de stockage non créés."
echo "→ code envoyé : $(secs "$t0" "$(now)") s  ($SRC → $CONTAINER:$DEST)"

# ── 2. vendor/ : seulement si composer.lock a changé (ou si la copie est neuve) ──
LOCK_HASH="$(sha256sum "$SRC/composer.lock" | cut -d' ' -f1)"
STAMP="$DEST/vendor/.odc-lock-hash"
if [ "$(dx sh -c "cat '$STAMP' 2>/dev/null" || true)" != "$LOCK_HASH" ]; then
  echo "→ composer.lock nouveau ou copie neuve : copie de vendor/ (une fois)…"
  t0=$(now)
  dx sh -c "rm -rf '$DEST/vendor' && cp -a /var/www/html/vendor '$DEST/vendor' && echo '$LOCK_HASH' > '$STAMP'" || die "la copie de vendor/ a échoué."
  echo "→ vendor/ copié : $(secs "$t0" "$(now)") s"
fi

# ── 3. les tests, dans la copie ──
t0=$(now)
docker exec -w "$DEST" "$CONTAINER" php artisan test "$@"; RC=$?
echo "→ php artisan test : $(secs "$t0" "$(now)") s (code $RC)"
echo "→ passe rapide terminée en $(secs "$T_ALL" "$(now)") s"
exit $RC
