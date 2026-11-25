#!/usr/bin/env bash
# Enforcement de la stratégie de documentation (Docs #6/#7) : un seul décompte de
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

echo ""
echo "== Aucune référence au dossier admin/ dans le code =="

# Piste 3 de admin/echanges/ameliorations-cc (arbitrée par Cowork le 25/11/2026 ; Greg : « mettre des références de briefing dans le code, c'est pas très bon »).
# Le dossier admin/ est HORS du dépôt : un chemin vers lui dans un commentaire ne mène nulle part pour qui clone le dépôt, et il pourrit à chaque archivage
# (plus de 250 références cassées le 25/11/2026). Le code dit son « pourquoi » lui-même ; la doc du dépôt (docs/, README, CHANGELOG) peut renvoyer à admin/.
CODE_REFS=$(grep -rnE 'admin/(echanges|content|modules|strategies|suivi|jeu|archives|exemples)/|admin/(roadmap|registre-traitements|index-briefs)\.md' \
  --include='*.js' --include='*.mjs' --include='*.vue' --include='*.php' --include='*.yml' --include='*.yaml' --include='*.sh' \
  --exclude-dir=node_modules --exclude-dir=dist --exclude-dir=vendor --exclude-dir=storage --exclude-dir=.git --exclude='docs-sync-check.sh' . 2>/dev/null || true)

if [ -z "$CODE_REFS" ]; then
  echo "  ok — aucune référence à admin/ dans le code"
else
  echo "  ⨯ Une référence à admin/ dans le code — l'expliquer dans le commentaire lui-même, ou la mettre dans la doc du dépôt :"
  echo "$CODE_REFS" | sed 's/^/      /' | head -20
  FAILED=1
fi

exit $FAILED
