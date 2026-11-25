#!/usr/bin/env bash
# Enforcement de admin/strategies/docs.md #10 (Docs #6/#7) : AUCUN décompte de tests
# recopié dans la doc (depuis le 25/11/2026), et docs/ARCHITECTURE.md pas trop
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

echo "== Décompte de tests : aucun nombre recopié dans la doc =="

# Piste 5 de admin/echanges/ameliorations-cc (arbitrée par Cowork le 25/11/2026) : le décompte (« 1719 tests verts ») n'est PLUS recopié dans les README — il
# s'affiche à la fin de la suite et dans la CI. Recopié, c'était la source de presque tous les conflits entre PR ouvertes en même temps (deux PR = deux
# chiffres), et une valeur toujours à retoucher. Ce contrôle refuse donc toute réapparition, dans tout .md sauf CHANGELOG.md (l'historique daté d'une PR
# a le droit de dire « 1719 tests » : c'est un fait passé, pas un décompte à tenir à jour).
MATCHES_WITH_LOC=$(grep -rnoE "$COUNT_PATTERN" --include='*.md' --exclude='CHANGELOG.md' --exclude-dir=node_modules --exclude-dir=dist --exclude-dir=vendor . 2>/dev/null || true)

if [ -z "$MATCHES_WITH_LOC" ]; then
  echo "  ok — aucun décompte recopié (il s'affiche à la fin de la suite et dans la CI)"
else
  echo "  ⨯ Un décompte de tests est recopié dans la doc — le retirer (il s'affiche à la fin de la suite) :"
  echo "$MATCHES_WITH_LOC" | sed 's/^/      /'
  FAILED=1
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
