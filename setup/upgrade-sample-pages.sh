#!/usr/bin/env bash
# upgrade-sample-pages.sh <outdir> — the "three real pages" of docs/upgrades.md Phase 1 step 2.
#
# Every content page now requires sign-in, so an anonymous curl gets a 302 and a before/after diff
# of it proves nothing. This signs in through mock-oidc exactly as tests/auth-end-to-end.sh does,
# as the bench's first active allContextMember, and saves:
#   family/greece        a plain content page
#   corporate/shubha     a tableRenderer page
#   family/construction  a chunk-heavy page (cards and sections)
# plus each context's public sso-login page. Run it before and after, then diff the directories:
# only MODx's render-time footer (total time / query time / query count) should differ.
#
# BENCH ONLY: it signs in as a real person, which only mock-oidc allows.
set -uo pipefail
OUT=${1:?usage: upgrade-sample-pages.sh <outdir>}; mkdir -p "$OUT"
REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
. "$REPO/tests/lib.sh"
require_bench
require_mock_idp
IP=${MODX_TEST_IP:-127.0.0.1}
reader=$(q "SELECT p.email FROM modx_user_attributes p JOIN modx_users u ON u.id=p.internalKey
            JOIN modx_member_groups g ON g.member=u.id JOIN modx_membergroup_names n ON n.id=g.user_group
            WHERE u.active=1 AND n.name='allContextMember' ORDER BY u.id LIMIT 1;")
[ -n "$reader" ] || { echo "REFUSING: no active allContextMember user on this bench" >&2; exit 2; }

fetch() {  # <alias>
  # The context is the page's own, read from the database: a context key typed here went stale
  # the day the estate renamed its contexts, and ctx_host then refused on a key nothing holds.
  local ctx
  ctx=$(q "SELECT DISTINCT context_key FROM modx_site_content WHERE alias='$1' AND deleted=0;")
  [ -n "$ctx" ] && [ "$(printf '%s\n' "$ctx" | wc -l)" -eq 1 ] || {
    echo "REFUSING: page alias '$1' must be in exactly one context on this bench; found: ${ctx:-none}" >&2; exit 2; }
  set -- "$ctx" "$1"
  local host door jar="$OUT/jar-$1" SCHEME PORT
  host=$(ctx_host "$1") || exit 2   # the context's live name, from the database
  read -r SCHEME PORT < <(host_origin "$host")
  door=$(ctx_door "$1") || exit 2   # where to knock: the name, or a port door (lib.sh ctx_door)
  local C=(curl -sS --resolve "${door%%:*}:$PORT:$IP" -b "$jar" -c "$jar" -m 20)
  if [ ! -s "$jar" ]; then
    local loc form qval cb
    loc=$("${C[@]}" -o /dev/null -w '%{redirect_url}' "$SCHEME://$door/sso-login?sso=go")
    form=$("${C[@]}" "$loc")
    qval=$(printf '%s' "$form" | grep -o 'name="q" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
    qval=${qval//&amp;/&}
    cb=$("${C[@]}" -o /dev/null -w '%{redirect_url}' -X POST \
         --data-urlencode "q=$qval" --data-urlencode "email=$reader" "${loc%%\?*}")
    "${C[@]}" -o /dev/null "$cb"
    curl -sS --resolve "${door%%:*}:$PORT:$IP" -m 20 -o "$OUT/$1-sso-login.html" \
         -w "$1/sso-login %{http_code} %{size_download}\n" "$SCHEME://$door/sso-login"
  fi
  "${C[@]}" -o "$OUT/$1-$2.html" -w "$1/$2 %{http_code} %{size_download}\n" "$SCHEME://$door/$2"
}
fetch greece
fetch shubha
fetch construction
rm -f "$OUT"/jar-*
