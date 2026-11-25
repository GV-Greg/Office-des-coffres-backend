#!/usr/bin/env bash
# Mode maintenance de la production, SANS SSH.
#
# Le déploiement n'a que du FTP : `php artisan down` n'est pas à portée. Or `artisan down` n'écrit
# qu'un fichier JSON, `storage/framework/down`, que le middleware de Laravel lit à chaque requête.
# On le dépose (ou on le retire) donc directement par FTP. `storage/framework/**` est exclu du
# déploiement : le fichier ne risque pas d'être écrasé par un envoi, ni de survivre à un `up`.
#
# Usage : scripts/maintenance.sh down|up|status|payload
#   down     dépose le fichier (idempotent) ;
#   up       le retire (idempotent) ;
#   status   affiche `closed` ou `open` ;
#   payload  imprime le JSON, sans toucher au serveur (testé par Feature/Maintenance).
#
# Variables : FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, MAINTENANCE_SECRET (= le secret du
# bypass : `/{secret}` sur l'API et en-tête X-Maintenance-Bypass pour le site des joueurs).
# `down`, `up` et `status` impriment l'état final (`closed` ou `open`) en dernière ligne.
#
# Le protocole est le FTP simple, comme l'action de déploiement (SamKirkland, `protocol` par
# défaut) : même hébergeur, mêmes identifiants, rien de plus exposé qu'aujourd'hui.
set -euo pipefail

RETRY_SECONDS=300
REMOTE_DIR="back/storage/framework"
REMOTE_FILE="down"

# Le secret entre tel quel dans un JSON et dans une URL : on le restreint plutôt que de l'échapper.
check_secret() {
  if [[ ! "${MAINTENANCE_SECRET:-}" =~ ^[A-Za-z0-9_-]{16,}$ ]]; then
    echo "::error::MAINTENANCE_SECRET absent, ou invalide (16 caractères minimum : lettres, chiffres, - et _)." >&2
    exit 1
  fi
}

payload() {
  check_secret
  printf '{"except":[],"redirect":null,"retry":%d,"refresh":null,"secret":"%s","status":503,"template":null}' \
    "$RETRY_SECONDS" "$MAINTENANCE_SECRET"
}

ftp_setup() {
  : "${FTP_SERVER:?FTP_SERVER manquant}" "${FTP_USERNAME:?FTP_USERNAME manquant}" "${FTP_PASSWORD:?FTP_PASSWORD manquant}"
  CURL=(curl --silent --show-error --fail --max-time 60 --user "${FTP_USERNAME}:${FTP_PASSWORD}")
}

# 0 si le fichier « down » est présent sur le serveur.
is_closed() {
  # Listage capturé d'abord : avec pipefail, un `grep -q` qui sort tôt ferait échouer curl (tube fermé).
  local listing
  listing="$("${CURL[@]}" --list-only "ftp://${FTP_SERVER}/${REMOTE_DIR}/" | tr -d '\r')"
  grep -qx "$REMOTE_FILE" <<< "$listing"
}

case "${1:-}" in
  payload)
    payload
    echo
    ;;
  status)
    ftp_setup
    if is_closed; then echo closed; else echo open; fi
    ;;
  down)
    ftp_setup
    tmp="$(mktemp)"
    trap 'rm -f "$tmp"' EXIT
    payload > "$tmp"
    "${CURL[@]}" -T "$tmp" "ftp://${FTP_SERVER}/${REMOTE_DIR}/${REMOTE_FILE}"
    is_closed || { echo "::error::Le fichier de maintenance n'est pas visible sur le serveur après l'envoi." >&2; exit 1; }
    echo closed
    ;;
  up)
    ftp_setup
    if is_closed; then
      # DELE part avant le listage de la racine, qu'on jette : seule compte la commande.
      "${CURL[@]}" -Q "DELE ${REMOTE_DIR}/${REMOTE_FILE}" "ftp://${FTP_SERVER}/" > /dev/null
    fi
    if is_closed; then echo "::error::Le fichier de maintenance est toujours sur le serveur." >&2; exit 1; fi
    echo open
    ;;
  *)
    echo "Usage : $0 down|up|status|payload" >&2
    exit 2
    ;;
esac
