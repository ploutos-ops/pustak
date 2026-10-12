#!/usr/bin/env bash
# bind-production-domains.sh — put the DECLARED production domains into a running MODx.
#
#     MODX_ESTATE=/path/estate.json MODX_DB_* … bash setup/bind-production-domains.sh <docroot>
#     …                                          bash setup/bind-production-domains.sh <docroot> --check
#
# WHY THIS EXISTS. Binding a context to a domain is TWO acts, and until now only one of them
# had a script: `setup/10-production-domains.py` writes each context's http_host/site_url into the
# database, and `config.context.php` — the docroot file `index.php` reads for a context key — was
# written by hand. (Now that file is one line requiring EdgeContext.php, which takes the
# context from the edge's HTTP_X_TENANT header; it carries no host map.) So the estate's domain declaration had no complete path to
# production, and the half that WAS scripted could not work without the half that was not: a context
# whose http_host is set but which no host maps to resolves to `web` and serves the wrong site, with
# nothing anywhere reporting it. That is the shape of a skipped bind, and it cost days the first time.
#
# Both halves come from the SAME declaration (`estate.json` → `domains.prod`), via
# `setup/domains.py`, which `bench-domains.py` and `verify-domains.py` already share. There is no
# second list to keep in step.
#
# --check is READ-ONLY and safe on a live production box: it asserts the database and the docroot
# file both match the declaration and exits 1 on any drift. Run it after, and run it whenever you
# want to know whether a container is routing what the estate says it should.
#
# WHERE IT RUNS. On the machine that holds the docroot, because writing that file is the point.
# Skunkworks does not take a shell on a production host — this is written to be CALLED BY a deploy
# verb (the `apply-modx-elements.sh` / `ops.sh` pattern), not run by hand from a dev box.
set -uo pipefail

SETUP="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCROOT="${1:-}"
MODE="${2:-apply}"

[ -n "$DOCROOT" ] || { echo "usage: $0 <docroot> [--check]" >&2; exit 2; }
[ -d "$DOCROOT" ] || { echo "REFUSING: '$DOCROOT' is not a directory" >&2; exit 2; }
[ -f "$DOCROOT/index.php" ] || {
  echo "REFUSING: '$DOCROOT' holds no index.php — that is not a MODx docroot, and writing" >&2
  echo "          config.context.php into the wrong directory produces no error at all." >&2; exit 2; }
[ -n "${MODX_ESTATE:-}" ] || {
  echo "REFUSING: MODX_ESTATE is not set — there is nothing to bind FROM. No default: a guessed" >&2
  echo "          declaration would bind somebody else's domains and report success." >&2; exit 2; }

if [ "$MODE" = "--check" ]; then
  echo "checking $DOCROOT against the declared production domains"
  rc=0
  python3 "$SETUP/verify-domains.py" --profile prod   || rc=1
  python3 "$SETUP/config-context.py" --profile prod --check "$DOCROOT" || rc=1
  [ "$rc" = 0 ] && echo "OK — database matches the declaration and config.context.php is the EdgeContext require"
  exit "$rc"
fi

echo "binding production domains from $MODX_ESTATE"

# 1. the database: each context's http_host / site_url / base_url. Self-verifying already.
python3 "$SETUP/10-production-domains.py" || { echo "ABORT: 10-production-domains.py failed" >&2; exit 1; }

# 2. the docroot: config.context.php, the one-line EdgeContext require. Never by hand.
#    Keep the previous one: this file decides which site every request reaches, and a rollback
#    should not require regenerating anything.
if [ -f "$DOCROOT/config.context.php" ]; then
  cp -p "$DOCROOT/config.context.php" "$DOCROOT/config.context.php.prev" \
    && echo "  previous file kept at config.context.php.prev"
fi
python3 "$SETUP/config-context.py" --profile prod --write "$DOCROOT" \
  || { echo "ABORT: could not write config.context.php" >&2; exit 1; }
php -l "$DOCROOT/config.context.php" >/dev/null || {
  echo "ABORT: the generated config.context.php is not valid PHP — restoring the previous file" >&2
  [ -f "$DOCROOT/config.context.php.prev" ] && mv "$DOCROOT/config.context.php.prev" "$DOCROOT/config.context.php"
  exit 1; }

# 3. MODx caches the context settings. A database write alone is invisible until this is dropped —
#    the same trap that made a settings change read as absent for two seconds and that
#    infra hit again today writing kuber.base_url.
rm -rf "$DOCROOT/core/cache/system_settings" "$DOCROOT/core/cache"/config* 2>/dev/null
echo "  settings cache dropped"

# 4. assert what was just done, rather than reporting the exit code of the last command.
echo "verifying"
rc=0
python3 "$SETUP/verify-domains.py" --profile prod   || rc=1
python3 "$SETUP/config-context.py" --profile prod --check "$DOCROOT" || rc=1
if [ "$rc" != 0 ]; then
  echo "ABORT: the bind did not verify — see above. config.context.php.prev holds the previous file." >&2
  exit 1
fi
echo "production domains bound and verified"
