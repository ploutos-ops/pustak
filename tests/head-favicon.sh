#!/usr/bin/env bash
# head-favicon.sh — every context's pages declare the application's mark (Saraswati's veena; a bird before it), and the files it names exist.
#
#     bash tests/head-favicon.sh
#
# WHAT IS UNDER TEST. Until 2026-07-30 every MODx context served no favicon at all: no
# `<link rel="icon">` anywhere in the shared head chunk, so every tab in every context showed a blank
# page icon. The fix is three lines in `Bootstrap.5.Head`, which all three templates include —
# so one chunk covers all four contexts, and one bad edit loses all four at once.
#
# Two things can silently undo it, and each has its own check here:
#
#   1. The links stop rendering — a chunk edit, a template that grows its own head, a context whose
#      pages use neither. Asserted per context over HTTP, not by reading the chunk: reading the chunk
#      proves the chunk, and what the browser gets is the question.
#   2. The URLs stop resolving. cdn is served `immutable` for a year, so these files can never
#      be edited in place — a better master is a NEW dated directory plus an edit here. That makes the
#      dated path load-bearing and a typo in it invisible: the page renders perfectly and the tab is
#      blank. So every href is fetched.
#
# THE HOST TRAP THIS TEST ALSO GUARDS AGAINST. a bench name in the internal zone resolves to one
# bench machine. A plain `curl http://family.<bench zone>/...` from the other silently answers from the OTHER bench — a different
# docroot and a different database — so an edit made here can be "verified" against a box that never
# saw it. Every request below is pinned with --resolve to $IP (127.0.0.1 by default), which is the
# only reason this test says anything about THIS box. Measured 2026-07-30: the pinned request showed
# the three links, the unpinned one showed none, from the same shell.
#
# READ-ONLY. Creates nothing, changes nothing.
set -uo pipefail

IP=${MODX_TEST_IP:-127.0.0.1}
REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
CHUNK=Bootstrap.5.Head

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
require_repo
require_bench

echo "$CHUNK — the favicon reaches every context, and the files it names exist"

# The contexts to check are read from the database, never listed here: a context added later must
# fail this test rather than be quietly outside it. A context qualifies if it serves the `public`
# page (anonymous, no session needed) through a template — a page on template 0 has no <head> at all.
contexts=$(q "SELECT DISTINCT c.context_key
              FROM modx_site_content c
              JOIN modx_context x ON x.key = c.context_key
              WHERE c.alias='public' AND c.deleted=0 AND c.published=1 AND c.template<>0
              ORDER BY c.context_key;")

check "$([ -n "$contexts" ] && echo 1 || echo 0)" \
      "the database names at least one context serving a templated 'public' page" \
      "no context qualified — this test would otherwise pass over an empty list"
[ -n "$contexts" ] || { summary; exit 1; }

# The hrefs come out of the chunk itself rather than being restated, so this test cannot disagree
# with what is deployed. If the chunk names no icon, the loop below has nothing to fetch and the
# per-context assertions are what fail — which is the right way round.
hrefs=$(grep -oE 'rel="(icon|apple-touch-icon)"[^>]*href="[^"]+"|href="[^"]+"[^>]*rel="(icon|apple-touch-icon)"' \
          "$REPO/common/chunks/$CHUNK.html" | grep -oE 'href="[^"]+"' | cut -d'"' -f2 | sort -u)

# The chunk names the CDN by its setting, [[++estate_cdn_url]]; the served page carries the value.
CDN=$(q "SELECT value FROM modx_system_settings WHERE \`key\`='estate_cdn_url';")
[ -n "$CDN" ] || { echo "REFUSING TO RUN: estate_cdn_url is not set on this ground, so the icon addresses cannot be known." >&2; exit 2; }
hrefs=${hrefs//\[\[++estate_cdn_url\]\]/${CDN%/}}

for ctx in $contexts; do
  host=$(ctx_host "$ctx") || exit 2   # the context's LIVE name (staging's are its own)
  read -r scheme port < <(host_origin "$host")
  door=$(host_door "$host") || exit 2
  body=$(curl -sS --resolve "${door%%:*}:$port:$IP" -m 10 "$scheme://$door/public" 2>&1)

  # THE CONTROL, first: this request must be able to see head content at all. Without it, a page that
  # 404s or returns a bare fragment would make every assertion below fail for the wrong reason — or,
  # worse, an inverted match would make it pass.
  check "$(has "$body" '<title>')" \
        "$ctx: /public renders a <head> at all" "${body:0:200}"

  check "$(hasre "$body" 'rel="icon"[^>]*type="image/svg\+xml"')" \
        "$ctx: declares the SVG icon (the only one with the dark-tab rule)" \
        "no svg rel=icon in the served head"
  check "$(hasre "$body" 'rel="icon"[^>]*sizes="32x32"')" \
        "$ctx: declares the 32px PNG fallback" "no 32x32 rel=icon in the served head"
  check "$(has "$body" 'rel="apple-touch-icon"')" \
        "$ctx: declares the apple-touch-icon" "no apple-touch-icon in the served head"

  # Every href the chunk declares must appear in what was served. Rendered output is the subject:
  # a [[++tag]] left unresolved, or a <base href> rewriting a relative path, shows up here and
  # nowhere else.
  for h in $hrefs; do
    check "$(has "$body" "$h")" \
          "$ctx: serves $h" "declared in the chunk, absent from the response"
  done
done

# The files themselves. A 404 here means every tab is blank while every page still renders, so this
# is the assertion that would otherwise be made by eye and never made again.
#
# cdn is outside this box. If it cannot be reached at all, this suite REFUSES rather than reporting
# a green or a red about files it never saw — the distinction run-all.sh preserves.
cdn=$(q "SELECT value FROM modx_system_settings WHERE \`key\`='estate_cdn_url';")
sheet=$(q "SELECT value FROM modx_system_settings WHERE \`key\`='estate.stylesheet';")
[ -n "$cdn" ] && [ -n "$sheet" ] || { echo "REFUSING TO RUN: estate_cdn_url or estate.stylesheet is not set on this ground" >&2; exit 2; }
probe=$(curl -sS -m 10 -o /dev/null -w '%{http_code}' "$cdn/$sheet" 2>&1)
case "$probe" in
  200|301|302|304) ;;
  404) ;;   # the CDN answered: a missing sheet is a FAIL, asserted below, not a refusal
  *) echo "REFUSING TO RUN: the CDN is not reachable (probe said '$probe'), so nothing can" >&2
     echo "be said about the icon files. Not a failure — an unmeasured check." >&2; exit 2 ;;
esac
check "$([ "$probe" = 200 ] && echo 1 || echo 0)" "the site stylesheet $cdn/$sheet -> 200" "got $probe"

for h in $hrefs; do
  read -r code ctype < <(curl -sS -m 15 -o /dev/null -w '%{http_code} %{content_type}' "$h")
  check "$([ "$code" = 200 ] && echo 1 || echo 0)" "$h -> 200" "got $code"
  case "$h" in
    *.svg) want=image/svg+xml ;;
    *.png) want=image/png ;;
    *.ico) want=image/x-icon ;;
    *)     want= ;;
  esac
  [ -n "$want" ] && check "$(case "$ctype" in "$want"*) echo 1 ;; *) echo 0 ;; esac)" \
      "$h is served as $want" "got '$ctype' — a favicon with the wrong type is ignored by browsers"
done

summary
