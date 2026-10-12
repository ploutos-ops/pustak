#!/usr/bin/env bash
# simplesearch-untouched — his grep stays. It is not deprecated, not replaced, and not tidied into
# the estate search that is being built beside it.
#
# THE OWNER, 2026-08-04, ruling, verbatim:
#
#     "it own app, a separate input, you can put it on footer but do nto take away my grep,
#      i have been using it for years."
#
# He is naming a real distinction rather than a preference, and this file exists because the
# distinction is invisible from inside the new work:
#
#     SimpleSearch    HIS GREP. Finds the PAGE that contains a string. Deterministic, exact, no
#                     model, and he knows in advance what it will do. Years of habit sit on it.
#     estate search   answers a QUESTION from passages across pages. Different tool, different
#                     failure mode (it can be wrong in prose), and it does not find a page.
#
# The estate's own rule is that a closed set is code and an open set is AI. Anything that quietly
# routes his grep through the model is a regression — and the shape of that regression is not
# malice, it is tidying: one search box looks better than two, and the second one is the one he
# uses. Words on a ticket do not survive a fresh agent; this does.
#
# WHAT THIS DOES NOT CLAIM. It guards the DECLARATION — the four Search pages still call
# SimpleSearch, the footer still points at the search page, the chunks the calls name still exist.
# It does not prove a query returns results; that is the bench suite's job and it needs a database.
# A structural guard that runs anywhere beats a live one that is skipped.
set -uo pipefail
cd "$(dirname "$0")/.."

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$1"; }
fail() { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; }
# Never `| grep -q` in an assertion — a pipe there is the race that read FAIL on a correct page.
#
# And not `$(grep -c ... || echo 0)` either, which is what this was: `grep -c` PRINTS 0 and EXITS
# non-zero when there is no match, so the fallback appended a second zero and `[ "0
# 0" -gt 0 ]` became a shell error. It printed to stderr and the check passed anyway. A helper that
# errors and returns the right answer is a helper that will one day error and return the wrong one.
has() { if grep -qF -- "$2" "$1" 2>/dev/null; then echo 1; else echo 0; fi; }

# His documents live in the estate repository since phase 3, so this guard reaches across to
# them. It REFUSES rather than passing when it cannot find them: a guard whose subject has moved
# and which reports "ok" is worse than no guard, and "the file is not here" is exactly what this
# test was written to shout about (the GONE branch below).
if [ -z "${MODX_ESTATE:-}" ] || [ ! -f "${MODX_ESTATE:-}" ]; then
  echo "REFUSING TO RUN: MODX_ESTATE is not set (or names nothing) — his Search pages live in the" >&2
  echo "          estate repository now, and this cannot check pages it cannot find." >&2
  exit 2
fi
ESTATE_REPO=$(cd "$(dirname "$MODX_ESTATE")" && pwd)
PAGES=$(ls "$ESTATE_REPO"/seat/documents/*/search-*.html 2>/dev/null)
[ -n "$PAGES" ] || { echo "REFUSING TO RUN: the estate holds no seat/documents/<context>/search-*.html page" >&2; exit 2; }

echo "control — this probe can tell a page that calls SimpleSearch from one that does not"
CTL=$(mktemp); printf 'no search here\n' > "$CTL"
REAL=$ESTATE_REPO/seat/documents/worldview/search-32.html
c_yes=$(has "$REAL" '[[!SimpleSearch'); c_no=$(has "$CTL" '[[!SimpleSearch'); rm -f "$CTL"
if [ "$c_yes" = 1 ] && [ "$c_no" = 0 ]; then
  ok "a real Search page matches and a blank file does not"
else
  fail "the probe cannot distinguish the two (real=$c_yes blank=$c_no)" \
       "Everything below would be meaningless. Fix the probe; do not delete the check."
  echo; echo "$PASS passed, $FAIL failed"; exit 2
fi

echo
echo "the four Search pages still call his grep"
for p in $PAGES; do
  ctx=$(basename "$(dirname "$p")")
  if [ ! -f "$p" ]; then
    fail "$ctx: $p is GONE" "The page itself was removed. This is the strongest form of taking it away."
    continue
  fi
  [ "$(has "$p" '[[!SimpleSearchForm')" = 1 ] \
    && ok "$ctx: the input is still there ([[!SimpleSearchForm)" \
    || fail "$ctx: no SimpleSearchForm — the search BOX is gone" "He types into this."
  [ "$(has "$p" '[[!SimpleSearch?')" = 1 ] \
    && ok "$ctx: the results call is still there ([[!SimpleSearch?)" \
    || fail "$ctx: no SimpleSearch call — the page has an input and nothing behind it" \
            "This is the 2026-07-31 defect exactly: the landing page called nothing and his 'trane' search found nothing."
done

echo
echo "the footer input still points at the search page, not somewhere new"
F=common/chunks/Bootstrap.5.Footer.html
if [ ! -f "$F" ]; then
  fail "$F is gone"
else
  [ "$(has "$F" '[[!SimpleSearchForm?')" = 1 ] \
    && ok "the footer still carries SimpleSearchForm" \
    || fail "the footer search input is gone or no longer SimpleSearch" \
            "He said a link to the new app MAY go in the footer. That is an addition, not a swap."
  [ "$(has "$F" '&landing=`[[++search_page]]`')" = 1 ] \
    && ok "and it still lands on the context's own search_page setting" \
    || fail "the footer form no longer lands on [[++search_page]]" \
            "A changed landing is how the footer box quietly becomes the other tool's input."
fi

echo
echo "the chunks those calls name still exist"
for c in SearchForm SearchResult SearchResults SearchNoResults Bootstrap.5.Searchresult PageLink CurrentPageLink; do
  [ -f "common/chunks/$c.html" ] \
    && ok "common/chunks/$c.html" \
    || fail "common/chunks/$c.html is missing" "A named tpl that does not exist renders empty and reports nothing."
done

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
