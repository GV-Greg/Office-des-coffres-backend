#!/usr/bin/env bash
# Sonde les routes de l'API listées dans scripts/probe-routes.txt (arbitrée le 25/11/2026).
#
# Usage : bash scripts/probe-routes.sh            (production)
#         PROBE_BASE=http://localhost:8000/api/v1 bash scripts/probe-routes.sh
# Sortie : 0 si toutes les routes répondent le code attendu, 1 sinon (la liste des écarts est aussi dans $GITHUB_OUTPUT si défini : `failed`).
#
# Le déploiement GitHub l'appelle APRÈS l'envoi des fichiers ; il ne peut pas lancer `route:cache` (pas de SSH). Une route ajoutée répond donc 404 jusqu'à
# ce que Greg le lance : l'alerte le dit. Après son `route:cache`, relancer ce script à la main (jamais pendant un déploiement en cours).
set -uo pipefail

BASE="${PROBE_BASE:-https://odc-admin.creacube.be/api/v1}"
ORIGIN="${PROBE_ORIGIN:-https://officedescoffres.creacube.be}"
FILE="${PROBE_FILE:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/probe-routes.txt}"
[ -f "$FILE" ] || { echo "::error::$FILE introuvable"; exit 2; }

FAILED=""
N=0
while read -r method path expected _; do
  case "${method:-}" in ''|'#'*) continue ;; esac
  N=$((N+1))
  code=000
  for attempt in 1 2 3; do
    if [ "$method" = "POST" ]; then
      code=$(curl -sS -X POST -d '{}' -o /dev/null --max-time 15 -w '%{http_code}' -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Origin: $ORIGIN" "$BASE/$path" 2>/dev/null)
    else
      code=$(curl -sS -X "$method" -o /dev/null --max-time 15 -w '%{http_code}' -H 'Accept: application/json' -H "Origin: $ORIGIN" "$BASE/$path" 2>/dev/null)
    fi
    code=${code:-000}
    # O2Switch répond parfois 429 aux rafales, et une connexion peut tomber : on réessaie, on n'alerte pas pour si peu.
    case "$code" in 429|000|502|503) sleep 5 ;; *) break ;; esac
  done
  if [ "$code" = "$expected" ]; then
    echo "OK  $method $path → $code"
  else
    echo "KO  $method $path → $code (attendu $expected)"
    FAILED="${FAILED:+$FAILED, }$method $path ($code au lieu de $expected)"
  fi
  sleep 1
done < "$FILE"

if [ "$N" -eq 0 ]; then echo "::error::aucune route dans $FILE"; exit 2; fi
if [ -n "$FAILED" ]; then
  echo "❌ $FAILED"
  [ -n "${GITHUB_OUTPUT:-}" ] && echo "failed=$FAILED" >> "$GITHUB_OUTPUT"
  exit 1
fi
echo "✅ $N routes répondent le code attendu."
exit 0
