#!/bin/bash
# failures-name-themselves — no user-facing failure in this repository may leave the person
# holding it unable to reach the cause. Ported from the school application.
#
# WHY IT IS HERE AND NOT ONLY THERE. Bob could not enrol his daughter, the screen
# said "Something went wrong. Please try again or contact someone@example.org", and he
# photographed it and mailed the photograph in. The fix was a test that breaks the school application's
# build. The owner asks for something stronger than that: "words do not survive a fresh agent;
# this must not depend on me remembering" — and a guard in ONE of five repositories IS me
# remembering, for the other four.
#
# MODx is the repository where that matters most after the school application, because its snippets talk to
# people: GoogleSSOStart, GoogleSSOCallback and unauthorizedNotice all render sentences into a
# browser. They are correct today — SSO-00..SSO-19, ACC-01..ACC-03, each quoted on screen and
# written to the log. This check is what keeps the next one correct.
#
# STATIC ONLY, deliberately. The school application's version also FORCES every failure its APIs can return
# and reads the log; that half needs a running app and a stub roster. MODx's equivalent would
# need a bench, and there is no bench on a dev box any more — a suite that can only refuse
# is worth less than one that checks what it can. What it can check is that the cause-free
# sentences do not come back, and that is the thing that actually regressed.
#
# The list is LITERAL, not clever. A pattern that tried to judge whether nearby prose "names a
# reason" would either nag about correct code or wave through a new variant, and a check that
# nags gets switched off. Adding a line here is a deliberate act; so is removing one.
set -uo pipefail
cd "$(dirname "$0")/.." || exit 2

PASS=0; FAIL=0
fail() { FAIL=$((FAIL+1)); echo "  FAIL  $1"; [ -n "${2:-}" ] && printf '%s\n' "$2" | sed 's/^/        /'; }
ok()   { PASS=$((PASS+1)); echo "  ok    $1"; }

echo "failures-name-themselves — a refusal this repository shows a person must name itself"
echo

BARE='Something went wrong|something went wrong|An error occurred|an error occurred|Unexpected error|An unexpected error|Oops|We hit a snag|Please try again later\.'

# Comments are exempt. This file and several snippets discuss the offending sentence at length —
# explaining a bad message is not shipping one. PHP and shell comments both stripped; the
# heredocs in setup/ are prose about the estate, not screens, and are covered by the path list
# rather than by cleverness.
# ONE stripper, used by BOTH halves. The code half originally grepped the raw file, so deleting
# ACC-01 from the branch that shows it still passed — the docstring at the top of the same file
# explains ACC-01 at length, and a comment is not a screen. Same class as the check that greps a
# prefix: a check must look at the thing it is making a claim about.
strip() {
  sed -E 's@/\*.*\*/@@g; s@(^|[[:space:]])//.*@@; s@^[[:space:]]*\*.*@@; s@(^|[[:space:]])#.*@@' "$1"
}

scan() {
  local f
  for f in $(find common tests setup -type f \
               \( -name '*.php' -o -name '*.py' -o -name '*.js' -o -name '*.html' \) 2>/dev/null); do
    [ -f "$f" ] || continue
    strip "$f" | grep -nE "$BARE" | sed "s|^|$f:|"
  done
}

hits=$(scan)
if [ -n "$hits" ]; then
  fail "a failure message names no cause" "$hits"
else
  ok "no cause-free failure sentence in common/, tests/ or setup/"
fi

# THE CONTROL, through the SAME function the check uses. A control that runs its own grep
# certifies a pipeline it never executed — that is not hypothetical here, it is what
# tests/no-hostnames.sh did before it was fixed.
probe=$(mktemp common/snippets/_probe-XXXX.php)
printf '<?php return "<p>Something went wrong. Please try again.</p>";\n' > "$probe"
planted=$(scan | grep "$(basename "$probe")" || true)
rm -f "$probe"
if [ -n "$planted" ]; then
  ok "control: the scan does find a planted bare message"
else
  fail "control: the scan MISSED a planted bare message — the result above is meaningless"
fi

# The snippets that DO talk to people must keep quoting a reference the log also carries. This is
# the positive half: the check above says what may not appear, this says what must.
# EVERY code, not a PREFIX. The first version of this loop grepped for `ACC-0`, which is still
# present when ACC-01 is deleted because ACC-02 and ACC-03 remain in the file — so a drill that
# removed the code from the screen passed. Caught by running the drill rather than by reading the
# loop, which is the whole argument for drilling a new check before trusting it.
check_codes() {
  local file="common/snippets/$1"; shift
  if [ ! -f "$file" ]; then
    fail "$file is missing — this check cannot say anything about it"; return
  fi
  local body; body="$(strip "$file")"
  if ! printf '%s' "$body" | grep -qE '\$modx->log|LOG_LEVEL'; then
    fail "$(basename "$file") shows failures and writes nothing to the log"; return
  fi
  local missing=""
  for code in "$@"; do
    printf '%s' "$body" | grep -q "$code" || missing="$missing $code"
  done
  if [ -n "$missing" ]; then
    fail "$(basename "$file") no longer carries:$missing" \
         "a code that leaves the screen leaves the person with nothing to quote"
  else
    ok "$(basename "$file") carries $# code(s) and writes to the log"
  fi
}

check_codes unauthorizedNotice.php ACC-01 ACC-02 ACC-03
check_codes GoogleSSOStart.php     SSO-00
check_codes GoogleSSOCallback.php  SSO-01 SSO-02 SSO-03 SSO-05 SSO-06 SSO-07 SSO-08 SSO-09

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
