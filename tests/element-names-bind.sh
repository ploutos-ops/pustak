#!/usr/bin/env bash
# element-names-bind.sh — a tracked element file and its database row agree on a NAME.
#
#     bash tests/element-names-bind.sh
#
# WHY. `import.py` matches an element by name: SELECT id WHERE name = %s, UPDATE if found,
# **INSERT if not**. So renaming an element file and deploying does not rename anything — it
# CREATES a second element and leaves every resource pointing at the first. For a template that is
# a silent, total layout break behind a green deploy: 271 documents would keep rendering the old
# row while the repository, the diff and the deploy log all showed the new name.
#
# `export.py` has the mirror of the same hole: it treats file-exists as the manifest, so the
# orphaned row exports as "skipped (not tracked)" and the renamed file is never refreshed. Neither
# direction reports anything.
#
# That trap is the whole reason the rename (page.folder / page.public / page.full, 2026-08-10) was a
# ticket rather than three `git mv`s, and this file is what makes the next rename safe without
# anybody remembering the trap.
#
# WHAT IS ASSERTED, both directions:
#   every tracked element FILE has a row of that exact name   (catches the rename that INSERTs)
#   every document's template id resolves to a row            (catches the orphan it leaves behind)
#
# Read-only. Runs against whatever database the environment names.
set -uo pipefail
REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
require_repo

echo "element names bind — files and rows agree"

# dir -> table, name column. Only the element kinds import.py matches by name.
check_kind() {
  local dir="$1" table="$2" col="$3" ext="$4"
  local n=0
  for f in "$REPO/common/$dir"/*."$ext"; do
    [ -e "$f" ] || continue
    local name; name=$(basename "$f" ".$ext")
    local hit; hit=$(q "SELECT COUNT(*) FROM $table WHERE \`$col\` = '$name';")
    if [ "$hit" = 1 ]; then
      n=$((n+1))
    else
      check 0 "$dir/$name has exactly one row in $table" \
            "found $hit. A file whose name matches no row is INSERTED on the next import, and
        whatever pointed at the old row keeps pointing at it."
    fi
  done
  check "$([ "$n" -gt 0 ] && echo 1 || echo 0)" "$dir: $n file(s) each bind to exactly one row" \
        "no files matched — this check asserted nothing"
}

check_kind templates modx_site_templates    templatename html
check_kind chunks    modx_site_htmlsnippets name        html
check_kind snippets  modx_site_snippets     name        php

# The orphan the INSERT leaves behind: a resource whose template id has no row. `template=0` is
# MODX's own "no template" and is legitimate (static resources and the container skeleton use it).
ORPHANS=$(q "SELECT COUNT(*) FROM modx_site_content c
             LEFT JOIN modx_site_templates t ON t.id = c.template
             WHERE c.template <> 0 AND t.id IS NULL;")
check "$([ "$ORPHANS" = 0 ] && echo 1 || echo 0)" \
      "every document's template id resolves to a row" \
      "$ORPHANS document(s) point at a template that does not exist"

# The control: this probe must be able to SEE a mismatch, or everything above is decoration.
GHOST=$(q "SELECT COUNT(*) FROM modx_site_templates WHERE templatename = 'no.such.template.probe';")
check "$([ "$GHOST" = 0 ] && echo 1 || echo 0)" \
      "control: a name that should not exist does not" "the probe matches anything, so it proves nothing"

summary
