#!/usr/bin/env bash
# Enforcement de admin/strategies/docs.md #10 (Docs #6/#7) : un seul décompte de
# tests dans le repo (README.md, source unique), et docs/ARCHITECTURE.md pas trop
# périmée par rapport au code qu'elle décrit.
#
# Usage : ./scripts/docs-sync-check.sh (depuis n'importe où, chemins résolus
# relativement à ce script). Exit 0 si tout est cohérent, 1 sinon.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$ROOT_DIR"

FAILED=0
COUNT_PATTERN='[0-9]+/[0-9]+ (tests? )?verts?|[0-9]+ tests? verts?'

echo "== Décompte de tests : une seule valeur dans tout le repo =="

# Toutes les mentions "N tests verts" / "N/M verts" dans tous les .md du repo (hors
# vendor/node_modules) — pas seulement README.md : docs/TESTS.md a le droit de
# répéter le même chiffre (sa table de temps de référence en dépend), l'enjeu est
# la dérive (deux chiffres différents qui se contredisent), pas la simple présence.
MATCHES_WITH_LOC=$(grep -rnoE "$COUNT_PATTERN" --include='*.md' --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null || true)
MATCHES_ONLY=$(grep -rhoE "$COUNT_PATTERN" --include='*.md' --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null || true)

if [ -z "$MATCHES_ONLY" ]; then
  echo "  ok — aucune mention trouvée"
else
  UNIQUE_NUMS=$(echo "$MATCHES_ONLY" | grep -oE '[0-9]+' | sort -un | uniq)
  UNIQUE_COUNT=$(echo "$UNIQUE_NUMS" | grep -c . || true)
  TOTAL_MENTIONS=$(echo "$MATCHES_ONLY" | grep -c . || true)

  if [ "$UNIQUE_COUNT" -gt 1 ]; then
    echo "  ⨯ Plusieurs décomptes différents trouvés :"
    echo "$MATCHES_WITH_LOC" | sed 's/^/      /'
    FAILED=1
  else
    echo "  ok — une seule valeur ($(echo "$UNIQUE_NUMS" | tr '\n' ' ' | sed 's/ $//')) sur $TOTAL_MENTIONS mention(s)"
  fi
fi

echo ""
echo "== Fraîcheur de docs/ARCHITECTURE.md =="

ARCH_FILE="docs/ARCHITECTURE.md"
if [ ! -f "$ARCH_FILE" ]; then
  echo "  ⚠️ $ARCH_FILE introuvable — vérification ignorée"
else
  # Le header (blockquote Markdown) peut wrapper "Mise à jour :" et la date sur deux
  # lignes distinctes dans le fichier source (même paragraphe visuellement une fois
  # rendu) — on joint les premières lignes avant de chercher, plutôt qu'un grep
  # ligne par ligne qui manquerait le cas où la date est sur la ligne suivante.
  header_text=$(head -10 "$ARCH_FILE" | tr '\n' ' ')
  doc_date_raw=$(echo "$header_text" | grep -oE 'Mise à jour[^0-9]*[0-9]{2}/[0-9]{2}/[0-9]{4}' | grep -oE '[0-9]{2}/[0-9]{2}/[0-9]{4}' | head -1 || true)
  if [ -z "$doc_date_raw" ]; then
    echo "  ⨯ Aucune mention « Mise à jour : DD/MM/YYYY » trouvée dans $ARCH_FILE"
    FAILED=1
  else
    doc_date_iso=$(echo "$doc_date_raw" | awk -F/ '{printf "%s-%s-%s", $3, $2, $1}')
    doc_epoch=$(date -d "$doc_date_iso" +%s 2>/dev/null || echo "")

    # Périmètre du doc = le code applicatif qu'il décrit.
    last_code_date=$(git log -1 --format=%cd --date=short -- app/ routes/ database/ config/ 2>/dev/null || true)

    if [ -z "$doc_epoch" ] || [ -z "$last_code_date" ]; then
      echo "  ⚠️ Date illisible ou pas d'historique git sur app/routes/database/config — vérification ignorée"
    else
      last_code_epoch=$(date -d "$last_code_date" +%s)
      gap_days=$(( (last_code_epoch - doc_epoch) / 86400 ))
      if [ "$gap_days" -gt 30 ]; then
        echo "  ⨯ $ARCH_FILE annonce une mise à jour du $doc_date_raw, mais le code a été modifié"
        echo "    le $last_code_date — écart de $gap_days jours (> 30). À revoir."
        FAILED=1
      else
        echo "  ok — $doc_date_raw, dernier commit code le $last_code_date (écart ${gap_days}j)"
      fi
    fi
  fi
fi

exit $FAILED
