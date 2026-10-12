#!/usr/bin/env bash
# protected-absorb.sh — a manager save of a Protected static resource absorbs the bytes into the
# Storage service; a disk static resource is untouched; and WITHOUT the plugin nothing absorbs.
#
#     bash tests/protected-absorb.sh
#
# WHAT IS UNDER TEST. setup/20-protected-files.py (which rides setup/04 onto every ground) +
# common/plugins/storeProtectedFile: the upload half of protected files. An editor uploads a file into the
# Protected media source and saves a Static Resource pointing at it; the save pushes the bytes to
# the storage door with the ground's own certificate, swaps `content` to the sha1 (which is what
# deliverStorageFile serves from), sets the real content type, and removes the disk copy.
#
# EVERYTHING THE APPLICATION DOES, THIS DRILL DOES AS THE APPLICATION — through $MODX_PHP_RUN
# where the ground declares one (the same convention run-all.sh uses for every php suite):
# the drill file is PLANTED by the container's own php exactly as a manager upload would land it
# (the runner's shell cannot write a www-data 2775 inbox and should not be able to), and the door
# checks present the identity deliverStorageFile presents (the runner's shell cannot read the
# client key and should not be able to). On a dev bench with no MODX_PHP_RUN, plain `php` and the
# agent's own uid do all of it, unchanged.
#
# THE ARMS, and why each exists:
#   green ×2  the absorb happens, from both content shapes the manager writes ('core/protected/f'
#             and bare 'f') — measured as: content is the sha1 OF THESE BYTES (computed here
#             first, never taken from the door), the disk copy is gone, the content type is the
#             real one, and the door answers by-sha1 for the blob.
#   heal      the stale-form revert (resource 1654's defect): a re-save of the old path string
#             with the file long gone comes back as the sha1, from the absorb record.
#   control   the same save on a file NOT in core/protected/ changes NOTHING — the contract is
#             that disk and storage static resources coexist, so the absorb must not be greedy.
#   drilled   the plugin is DISABLED and the same save absorbs nothing. P11: a probe is evidence
#             only once its failure has been SEEN.
#
# WRITES: drill resources (unpublished, removed), drill files (removed), one drill blob per green
# arm in this ground's own (modx, <env>) scope — holds RELEASED at the end by sha1
# (unique bytes per run, so a release can never touch a real file's hold). Bench/staging only.
set -uo pipefail

REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
require_repo
require_bench

PHP_RUN=${MODX_PHP_RUN:-php}
helper() { $PHP_RUN "$REPO/tests/protected-absorb.php" "$MODX_ROOT" "$@"; }

echo "storeProtectedFile — the save absorbs Protected bytes into storage, and only those"

SRC_ID=$(q "SELECT value FROM modx_system_settings WHERE \`key\`='storage.protected_source';")
if [ -z "$SRC_ID" ]; then
  echo "REFUSING: storage.protected_source is not set — run setup/20-protected-files.py (it rides" >&2
  echo "          setup/04 on a converged ground). Nothing has been asserted." >&2
  exit 2
fi
PDIR="$MODX_ROOT/core/protected"
[ -d "$PDIR" ] || { echo "REFUSING: $PDIR does not exist on this ground. Nothing asserted." >&2; exit 2; }

RUN="drill-$$-$(date +%s)"
IDS=""
cleanup() {
  set +u
  [ -n "$IDS" ] && helper cleanup $IDS >/dev/null 2>&1
  for f in "core/protected/$RUN-green.pdf" "core/protected/$RUN-bare.pdf" \
           "core/protected/$RUN-red.pdf" "$RUN-control.pdf"; do
    helper unplant "$f" >/dev/null 2>&1
  done
  q "UPDATE modx_site_plugins SET disabled=0 WHERE name='storeProtectedFile';" >/dev/null 2>&1
  $PHP_RUN "$REPO/clear-cache.php" "$MODX_ROOT" >/dev/null 2>&1
  for s in "${SHA:-}" "${SHA2:-}"; do
    [ -n "$s" ] && helper release "$s" >/dev/null 2>&1
  done
}
trap cleanup EXIT

# plant <rel> <label> — bytes generated HERE (so their sha1 is computed here), written by the
# application's own php, exactly where a manager upload lands.
plant() {
  printf '%%PDF-1.4 drill %s %s\n%%%%EOF\n' "$RUN" "$2" | helper plant "$1" >/dev/null \
    || { echo "REFUSING: could not plant $1 via '$PHP_RUN' — a statement about this shell/ground." >&2; exit 2; }
}
sha_of() { printf '%%PDF-1.4 drill %s %s\n%%%%EOF\n' "$RUN" "$1" | sha1sum | cut -d' ' -f1; }

# --- green 1: the absorb, content as the browser writes it (prefixed path) ---------------------
plant "core/protected/$RUN-green.pdf" green
SHA=$(sha_of green)
OUT=$(helper save "core/protected/$RUN-green.pdf") || { echo "helper failed: $OUT" >&2; exit 2; }
ID=${OUT#*id=}; ID=${ID%%$'\n'*}; IDS="$IDS $ID"
GOT=${OUT#*content=}; GOT=${GOT%%$'\n'*}
CT=${OUT#*content_type=}; CT=${CT%%$'\n'*}

check "$(has "$GOT" "$SHA")"                       "content 'core/protected/<f>' became the sha1 computed HERE ($SHA)"
check "$([ ! -e "$PDIR/$RUN-green.pdf" ] && echo 1 || echo 0)" "the disk copy is gone"
PDF_CT=$(q "SELECT id FROM modx_content_type WHERE mime_type='application/pdf' LIMIT 1;")
check "$([ "$CT" = "$PDF_CT" ] && echo 1 || echo 0)" "content_type became the real one (PDF, id $PDF_CT)" "got id '$CT'"
DOOR=$(helper door "$SHA")
check "$([ "$DOOR" = 200 ] && echo 1 || echo 0)"    "the door holds the blob (by-sha1 -> 200, the application's own identity)" "got $DOOR"

# --- the stale-form revert heals (resource 1654's defect, 2026-09-06) --------------------------
OUT=$(helper resave "$ID" "core/protected/$RUN-green.pdf") || { echo "helper failed" >&2; exit 2; }
GOT=${OUT#*content=}; GOT=${GOT%%$'\n'*}
check "$(has "$GOT" "$SHA")"                       "a stale re-save of the old path HEALS back to the sha1"

# --- green 2: the same absorb from a bare filename ---------------------------------------------
plant "core/protected/$RUN-bare.pdf" bare
SHA2=$(sha_of bare)
OUT=$(helper save "$RUN-bare.pdf") || { echo "helper failed" >&2; exit 2; }
ID=${OUT#*id=}; ID=${ID%%$'\n'*}; IDS="$IDS $ID"
GOT=${OUT#*content=}; GOT=${GOT%%$'\n'*}
check "$(has "$GOT" "$SHA2")"                      "content '<f>' (bare) absorbs the same way"

# --- control: a static resource whose file is NOT in core/protected/ is untouched --------------
plant "$RUN-control.pdf" control
OUT=$(helper save "$RUN-control.pdf") || { echo "helper failed" >&2; exit 2; }
ID=${OUT#*id=}; ID=${ID%%$'\n'*}; IDS="$IDS $ID"
GOT=${OUT#*content=}; GOT=${GOT%%$'\n'*}
check "$(has "$GOT" "$RUN-control.pdf")"           "a disk static resource keeps its path (both kinds coexist)"
check "$([ -e "$MODX_ROOT/$RUN-control.pdf" ] && echo 1 || echo 0)" "…and keeps its file"

# --- drilled red: without the plugin, nothing absorbs (P11) ------------------------------------
q "UPDATE modx_site_plugins SET disabled=1 WHERE name='storeProtectedFile';"
$PHP_RUN "$REPO/clear-cache.php" "$MODX_ROOT" >/dev/null
plant "core/protected/$RUN-red.pdf" red
OUT=$(helper save "core/protected/$RUN-red.pdf") || { echo "helper failed" >&2; exit 2; }
ID=${OUT#*id=}; ID=${ID%%$'\n'*}; IDS="$IDS $ID"
GOT=${OUT#*content=}; GOT=${GOT%%$'\n'*}
check "$(has "$GOT" "$RUN-red.pdf")"               "plugin disabled -> the same save absorbs NOTHING (the green arm can fail)"
q "UPDATE modx_site_plugins SET disabled=0 WHERE name='storeProtectedFile';"
$PHP_RUN "$REPO/clear-cache.php" "$MODX_ROOT" >/dev/null

summary
