#!/usr/bin/env bash
# verify-protection-wrong-class.sh — a page in a FILE group must be reported, not counted.
#
#     bash tests/verify-protection-wrong-class.sh
#
# WHAT IS UNDER TEST, and why it is a test rather than a note. On 2026-07-29 two worldview
# documents were members of `worldviewProtectedFiles` — a group for static files. The protection
# happened to be functionally equivalent, so nothing was exposed; what it did do was make
# verify-file-protection.py print `495 protected out of 493`. More members than there are files, and
# the run still exited 0.
#
# That is the shape this whole checker exists to prevent: a count that can be wrong in a direction
# it does not look. It only ever asked "is anything MISSING from the group", never "does the group
# hold something that is not of its kind" — so a membership in the wrong group was invisible to the
# one thing whose job is to see it, and it corrupted both totals while claiming everything matched.
#
# BENCH ONLY. It puts one document into a file group, runs the read-only verifier, and takes it out
# again. It refuses to run anywhere that looks like production.
set -uo pipefail

REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
require_repo
require_bench

echo "verify-file-protection — a membership of the wrong kind is drift"


# The state must be clean BEFORE the defect is introduced, or a pre-existing failure would be
# mistaken for the one being induced. This is the positive control, and it runs first.
if ! python3 "$REPO/setup/verify-file-protection.py" >/dev/null 2>&1; then
  echo "REFUSING TO RUN: the verifier already reports drift on this bench, so it could not" >&2
  echo "distinguish the fault this test induces from the one already there. Run setup/04 first." >&2
  python3 "$REPO/setup/verify-file-protection.py" 2>&1 | tail -12 >&2
  exit 2
fi
check 1 "the bench starts clean — the verifier exits 0"

ctx=$(q "SELECT c.context_key FROM modx_site_content c
         WHERE c.class_key='MODX\\\\Revolution\\\\modDocument' AND c.deleted=0
         GROUP BY c.context_key ORDER BY COUNT(*) DESC LIMIT 1;")
fgid=$(q "SELECT id FROM modx_documentgroup_names WHERE name='${ctx}ProtectedFiles';")
doc=$(q "SELECT id FROM modx_site_content WHERE context_key='$ctx'
         AND class_key='MODX\\\\Revolution\\\\modDocument' AND deleted=0 ORDER BY id LIMIT 1;")
if [ -z "$fgid" ] || [ -z "$doc" ]; then
  echo "REFUSING TO RUN: no ${ctx}ProtectedFiles group or no document to use." >&2; exit 2
fi

cleanup() { q "DELETE FROM modx_document_groups WHERE document=$doc AND document_group=$fgid;" >/dev/null 2>&1; }
trap cleanup EXIT

# The induced fault: exactly what was found on production on 2026-07-29 — a page in a file group.
q "INSERT INTO modx_document_groups (document,document_group) VALUES ($doc,$fgid);" || exit 2

out=$(python3 "$REPO/setup/verify-file-protection.py" 2>&1); rc=$?
check "$([ "$rc" != 0 ] && echo 1 || echo 0)" "a document in a file group makes the verifier exit non-zero" \
      "exit $rc; output: $(echo "$out" | tail -6)"
check "$(hasre "${out,,}" "not.*static|wrong.*group|${ctx,,}protectedfiles")" \
      "...and the report names the group it is wrongly in" "$(echo "$out" | tail -6)"
check "$(has "$out" "$doc")" \
      "...and names the document, so it can be corrected without a hunt" "$(echo "$out" | tail -6)"

cleanup; trap - EXIT
python3 "$REPO/setup/verify-file-protection.py" >/dev/null 2>&1
check "$([ $? = 0 ] && echo 1 || echo 0)" "removing the membership restores a clean run"

# THE MIRROR, because a check that looks one way is what the 2026-07-29 finding was. A static resource in the PAGE
# group is the same fault with the operands swapped, and it lies about the same two totals.
pgid=$(q "SELECT id FROM modx_documentgroup_names WHERE name='allContextProtected';")
stat=$(q "SELECT id FROM modx_site_content WHERE context_key='$ctx'
          AND class_key LIKE '%modStaticResource' AND deleted=0 ORDER BY id LIMIT 1;")
if [ -n "$pgid" ] && [ -n "$stat" ]; then
  cleanup2() { q "DELETE FROM modx_document_groups WHERE document=$stat AND document_group=$pgid;" >/dev/null 2>&1; }
  trap cleanup2 EXIT
  q "INSERT INTO modx_document_groups (document,document_group) VALUES ($stat,$pgid);" || exit 2
  out=$(python3 "$REPO/setup/verify-file-protection.py" 2>&1); rc=$?
  check "$([ "$rc" != 0 ] && echo 1 || echo 0)" "a static resource in the page group is drift too" \
        "exit $rc; $(echo "$out" | tail -4)"
  check "$(has "$out" "$stat")" \
        "...and it too is named" "$(echo "$out" | tail -4)"
  cleanup2; trap - EXIT
else
  check 0 "the mirror case could be set up" "no allContextProtected group or no static resource in $ctx"
fi

summary
