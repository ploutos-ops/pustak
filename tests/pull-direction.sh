#!/bin/bash
# pull-direction — a pull FROM production must not be allowed to revert work, and the tool that
# says which way each difference points is drilled here against both arms plus two controls.
#
# Built for the failure of 2026-09-12: one restore produced page 1584 — content created in the
# manager on production that git had never seen — AND three element bodies that were production's
# OLDER copies, which would have deleted the sign-in fix. Both in one `git status`. The only
# thing that stopped the second set was a person reading a diff, twice in one day.
#
# A SCRATCH REPOSITORY, NOT THIS ONE. The subject is git history, so the fixture has to own its own
# history; running against the real tree would make the result depend on whatever is uncommitted
# today, which is the property this file exists to judge rather than to inherit.
set -u
cd "$(dirname "$0")/.."
REPO=$(pwd)
TOOL=$REPO/setup/pull-direction.py
pass=0; fail=0
check() { if [ "$1" = 1 ]; then pass=$((pass+1)); echo "  ok    $2"
          else fail=$((fail+1)); echo "  FAIL  $2"; [ -n "${3:-}" ] && echo "        $3"; fi; }
has()  { case "$1" in *"$2"*) echo 1;; *) echo 0;; esac; }   # never `| grep -q` in a check
hasnt(){ case "$1" in *"$2"*) echo 0;; *) echo 1;; esac; }

command -v git >/dev/null || { echo "REFUSING: no git on this machine."; exit 2; }

T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
git -C "$T" init -q .
git -C "$T" config user.email t@example.invalid
git -C "$T" config user.name 'pull-direction drill'

printf 'the body as production still has it\n' > "$T/element.php"
printf 'a document that has always existed\n'  > "$T/doc.html"
git -C "$T" add -A && git -C "$T" commit -q -m 'the state the ground is on'
OLD=$(git -C "$T" rev-parse --short HEAD)

printf 'the body AFTER the fix nobody has deployed\n' > "$T/element.php"
git -C "$T" add -A && git -C "$T" commit -q -m 'the fix that is committed and undeployed'

echo "pull-direction — which way does each difference point"
echo

# ── 1. the hazard: a pull hands back the body the ground still has ─────────────────────────────
printf 'the body as production still has it\n' > "$T/element.php"
OUT=$(python3 "$TOOL" --repo "$T" 2>&1); RC=$?
check "$([ "$RC" = 1 ] && echo 1 || echo 0)" \
      "a file the ground is BEHIND on exits 1 — a commit gate can use it" "rc=$RC"
check "$(has "$OUT" 'GIT IS AHEAD')" "and says git is ahead, in those words"
check "$(has "$OUT" 'the fix that is committed and undeployed')" \
      "and NAMES the commit whose work the pull would delete" "$OUT"

# ── 2. the ordinary case: content production has and git never had ────────────────────────────
git -C "$T" checkout -q -- element.php
printf 'a page created in the manager on production\n' > "$T/new-1584.html"
OUT=$(python3 "$TOOL" --repo "$T" 2>&1); RC=$?
check "$([ "$RC" = 0 ] && echo 1 || echo 0)" \
      "new content from production exits 0 — this is how a new page enters git" "rc=$RC out=$OUT"
check "$(has "$OUT" 'NEVER HAD')" "and is reported as content git has never had"
check "$(hasnt "$OUT" 'GIT IS AHEAD')" "and is NOT confused with a revert"

# ── 3. both at once, which is what a real restore produces ─────────────────────────────────────
printf 'the body as production still has it\n' > "$T/element.php"
OUT=$(python3 "$TOOL" --repo "$T" 2>&1); RC=$?
check "$([ "$RC" = 1 ] && echo 1 || echo 0)" \
      "one behind + one new in the same tree still exits 1" "rc=$RC"
check "$(has "$OUT" 'new-1584.html')" "  and the new file is still reported as committable"
check "$(has "$OUT" 'element.php')"   "  and the behind file is still named"

# ── 4. CONTROL: an edit of my own is not a revert ──────────────────────────────────────────────
# Without this arm the tool could pass every case above by calling ANY difference a revert.
git -C "$T" checkout -q -- element.php; rm -f "$T/new-1584.html"
printf 'a change I am making right now, that no commit has ever held\n' > "$T/element.php"
OUT=$(python3 "$TOOL" --repo "$T" 2>&1); RC=$?
check "$([ "$RC" = 0 ] && echo 1 || echo 0)" \
      "control: an ordinary edit is not reported as a revert" "rc=$RC out=$OUT"

# ── 5. CONTROL: a clean tree says nothing, and a non-repo refuses rather than passing ──────────
git -C "$T" checkout -q -- element.php
OUT=$(python3 "$TOOL" --repo "$T" 2>&1); RC=$?
check "$([ "$RC" = 0 ] && echo 1 || echo 0)" "control: a clean tree exits 0" "rc=$RC"
check "$(has "$OUT" 'no differences')" "  and says so rather than printing an empty verdict"
OUT=$(python3 "$TOOL" --repo "$T/nope" 2>&1); RC=$?
check "$([ "$RC" = 2 ] && echo 1 || echo 0)" \
      "control: a path that is not a checkout REFUSES (2), never passes (0)" "rc=$RC"

printf '\n%d passed, %d failed\n' "$pass" "$fail"
[ "$fail" = 0 ]
