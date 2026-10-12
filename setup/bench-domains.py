#!/usr/bin/env python3
"""
bench-domains — bind each context to its BENCH domain (*.modx on the internal zone). The inverse of
setup/10-production-domains.py.

Why this exists: `restore_modx_dev` pulls the PROD DB into the bench raw, so it also
copies prod's `http_host` / `site_url` context settings (whatever the estate declares). Left as
is, previewed bench pages self-link to the production hosts (the page `<base href>` and every
generated link point off-box). This rewrites each context back to `<key>.modx.<internal zone>` so the
bench previews itself.

RUN THIS ON THE BENCH AFTER EVERY `restore_modx_dev`. Bench-only — never run on prod (prod uses
setup/10). A bench SEED load does not need it run by hand: `setup/load-seed.sh --profile bench`
binds and verifies as part of the load. Idempotent. Clear the MODx cache after.

The domain facts live in setup/domains.py (one home, shared with 10-production-domains.py and
verify-domains.py). Self-verifying: re-reads what it wrote and exits non-zero on drift.

Also does the two post-restore hygiene fixups infra left to skunkworks (both bench-only):
  - publishes each context's error_page / unauthorized_page resource (a prod dump can carry them
    unpublished, which the manager dashboard nags about and which breaks the 401/404 responses);
  - removes the MODx installer dir ($MODX_ROOT/setup) if present (the manager's standing security
    warning — the bench never runs the web wizard, it reconstitutes from the git seed).
"""
import argparse
import os
import sys, sys, shutil, pymysql, domains, settings_guard

# WHICH NON-PRODUCTION PROFILE THIS BIND IS FOR. Default 'bench', so every existing caller — and
# every pair of hands that has ever typed this — moves nowhere. 'local' binds the third instance:
# same http_host, site_url on the declared port door (setup/domains.py, LOCAL).
#
# 'prod' is deliberately NOT a choice. 10-production-domains.py owns that binding, and everything
# below this line is bench hygiene — it deletes $MODX_ROOT/setup and republishes error pages.
_ap = argparse.ArgumentParser(description=__doc__.split('\n')[1] if __doc__ else '')
_ap.add_argument('--profile', default='bench', choices=('bench', 'local'))
PROFILE = _ap.parse_args().profile
# No default: /srv/local/modx was the dev bench and it stopped existing when the bench
# became staging (2026-08-14). A guessed docroot addresses a MODx nobody runs.
MODX_ROOT = os.environ.get('MODX_ROOT') or sys.exit(
    'REFUSING: MODX_ROOT is not set — no docroot to bind domains in. Nothing written.')

conn = pymysql.connect(**domains.db_config()); cur = conn.cursor()

# Photograph every settings row before this pass touches anything, and
# REFUSE THE COMMIT if a setting that had a value no longer does. Three times on
# 2026-08-12 a pass here emptied settings nobody asked it to touch (the MODX_SSO_OIDC
# set, the outward-facing settings, merged elements) and every one was found by somebody
# stumbling into it rather than by the pass saying anything. A loss rolls the whole pass
# back and exits 1, so the estate is as it was; a removal this pass MEANS to make is
# declared with g.allow(scope, context, key). Drilled in tests/settings-guard.py.
g = settings_guard.start(conn, f'bench-domains:{PROFILE}')

def set_ctx(ctx, key, value):
    cur.execute("SELECT 1 FROM modx_context_setting WHERE context_key=%s AND `key`=%s", (ctx, key))
    if cur.fetchone():
        cur.execute("UPDATE modx_context_setting SET value=%s WHERE context_key=%s AND `key`=%s",
                    (value, ctx, key))
    else:
        cur.execute("INSERT INTO modx_context_setting (context_key,`key`,value,xtype) "
                    "VALUES (%s,%s,%s,'textfield')", (ctx, key, value))

for ctx, settings in domains.expected_settings(PROFILE).items():
    # only touch contexts that actually exist (claude may not be present on an older pull)
    if not domains.context_exists(cur, ctx):
        print(f'  {ctx}: absent, skipped')
        continue
    for key, value in settings.items():
        set_ctx(ctx, key, value)
    print(f"  {ctx} -> {settings['http_host']}")

# Hygiene 1: publish each context's error_page / unauthorized_page resource. A prod dump
# can carry these unpublished; the manager dashboard nags and the 401/404 handlers need them live.
cur.execute("""
    UPDATE modx_site_content SET published=1, deleted=0
    WHERE id IN (
        SELECT value FROM modx_context_setting
        WHERE `key` IN ('error_page', 'unauthorized_page') AND value REGEXP '^[0-9]+$'
    )
""")
if cur.rowcount:
    print(f'  published {cur.rowcount} error/unauthorized page(s)')

# Hygiene 3 was here: re-empty `container_suffix`, which a prod restore brought back as '/'.
# REMOVED 2026-07-27 — infra applied the same fix to production (13 URIs updated, setting now ''), so a
# restore carries the right value and re-asserting it here would only hide a future drift. The one
# home for the fact is setup/02-system-settings.py; nothing re-asserts it after a restore, by design.

g.commit()

bad = domains.drift(cur, PROFILE)
cur.close(); conn.close()
if bad:
    for ctx, key, want, got in bad:
        print(f'  DRIFT {ctx}.{key}: expected {want!r}, found {got!r}', file=sys.stderr)
    sys.exit('bench-domains: the bind did NOT hold — see drift above')

# Hygiene 2: drop the MODx installer dir — the manager's standing security warning. The
# bench never runs the web wizard (it reconstitutes from the git seed), so setup/ is pure noise.
setup_dir = os.path.join(MODX_ROOT, 'setup')
if os.path.isdir(setup_dir):
    shutil.rmtree(setup_dir, ignore_errors=True)
    print(f'  removed installer dir {setup_dir}' if not os.path.isdir(setup_dir)
          else f'  WARN: could not remove {setup_dir} (permissions?) — remove by hand')

print('Bench domains bound + verified, post-restore hygiene done. Clear the MODx cache after this.')
