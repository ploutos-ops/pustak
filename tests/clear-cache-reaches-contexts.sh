#!/usr/bin/env bash
# clear-cache-reaches-contexts.sh — after clear-cache.php, a changed setting is actually in effect.
#
#     bash tests/clear-cache-reaches-contexts.sh
#
# WHAT IS UNDER TEST. `php clear-cache.php` prints "Cache cleared." Every runbook and every setup
# script in this repo ends with it, and the whole estate's model of "change a setting, clear the
# cache, it takes effect" rests on that sentence being true.
#
# It is not immediately true, and the reason is not MODx. clear-cache.php regenerates MODx's caches
# as PHP FILES; PHP-FPM serves those through opcache, and opcache re-checks a file's timestamp at
# most once every `opcache.revalidate_freq` seconds — 2 on this box. For those two seconds a request
# compiles the OLD file, so a setting that is unquestionably in the database reads as EMPTY.
#
# Measured 2026-07-29, after an hour spent suspecting MODx: database 1704 bytes, the key present in
# `system_settings/config.cache.php`, and a signed-in request reporting length 0. Reproducible from a
# script every time and never by hand, because typing the same commands takes longer than two
# seconds. `clear_cache` in lib.sh waits out that window; this test is what says the wait is enough.
#
# Two wrong answers were written down on the way here, and both came from grepping cache files
# instead of asking a web request what it could see: that `refresh()` skips other contexts (it does
# not — it enumerates every context), and that `context_settings/<ctx>/context.cache.php` is where a
# system setting is read from (it is not — it never holds them at all).
#
# BENCH ONLY. Creates one page and one system setting, removes both.
set -uo pipefail

HOST=${MODX_TEST_HOST:-}   # empty: the context's live http_host, read after lib.sh
IP=${MODX_TEST_IP:-127.0.0.1}
CTX=${MODX_TEST_CTX:-$(python3 "$(dirname "${BASH_SOURCE[0]}")/../setup/estate-get.py" domains.contexts | head -1)}   # default: the estate's first context
[ -n "$CTX" ] || { echo "REFUSING TO RUN: no MODX_TEST_CTX and no context in the estate MODX_ESTATE names" >&2; exit 2; }
REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
ALIAS=cache-probe-page
KEY=test_cache_probe

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
[ -n "$HOST" ] || HOST=$(ctx_host "$CTX") || exit 2

# The scheme and port are DECLARED, not assumed — see lib.sh origin_of(). This REFUSES if the
# ground cannot tell it which, rather than speaking http at an https edge and reporting the 301
# as a failure of the page.
read -r SCHEME PORT < <(host_origin "$HOST")
DOOR=$(host_door "$HOST") || exit 2

require_repo
require_bench

echo "clear-cache.php — a changed setting reaches the contexts"


cleanup() {
  q "DELETE FROM modx_site_content WHERE context_key='$CTX' AND alias='$ALIAS';" >/dev/null 2>&1
  q "DELETE FROM modx_system_settings WHERE \`key\`='$KEY';" >/dev/null 2>&1
  modx_php "$REPO/clear-cache.php" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup

before=BEFORE-$$
after=AFTER-$$
q "INSERT INTO modx_system_settings (\`key\`,value,xtype,namespace,area)
   VALUES ('$KEY','$before','textfield','core','system');" || exit 2
q "INSERT INTO modx_site_content
     (type,pagetitle,longtitle,menutitle,description,alias,uri,uri_override,template,content_type,
      parent,context_key,published,hidemenu,menuindex,searchable,cacheable,richtext,createdby,
      createdon,publishedon,publishedby,content)
   VALUES ('document','cache probe','','','','$ALIAS','$ALIAS',1,0,1,
      0,'$CTX',1,1,0,0,0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),1,'[[++$KEY]]');" || exit 2
clear_cache

# THE CONTROL, and it runs first: the probe must be able to see a value at all, and this request is
# also what warms the context cache that the real assertion then has to defeat.
out=$(curl -sS --resolve "${DOOR%%:*}:$PORT:$IP" -m 10 "$SCHEME://$DOOR/$ALIAS")
check "$(has "$out" "$before")" \
      "a page in the $CTX context can read a system setting" "$(echo "$out" | head -c 200)"

# THE ASSERTION: change it under a warm cache, clear, and the page must show the new value.
q "UPDATE modx_system_settings SET value='$after' WHERE \`key\`='$KEY';"
clear_cache
out=$(curl -sS --resolve "${DOOR%%:*}:$PORT:$IP" -m 10 "$SCHEME://$DOOR/$ALIAS")
check "$(has "$out" "$after")" \
      "after clear-cache.php the NEW value is what the page renders" \
      "still showing the old value: $(echo "$out" | head -c 200)"

# The logs are the one thing a cache clear must never take with it — a MODx with no error log is how
# two earlier defects both hid. Asserted here because this tool is the one that would do it.
# $MODX_ROOT, not /srv/local/modx: the docroot is on hanuman now and /srv/local does
# not exist there, so this assertion would have been checking a path that cannot exist — and its
# second clause ("or there is no cache directory at all") would have made that PASS. A check that
# is green precisely because it is looking at the wrong machine is the worst shape it could take.
check "$([ -d "$MODX_ROOT/core/cache/logs" ] || [ ! -e "$MODX_ROOT/core/cache" ] && echo 1 || echo 0)" \
      "the log directory survives a cache clear" "$MODX_ROOT/core/cache/logs is gone"

summary
