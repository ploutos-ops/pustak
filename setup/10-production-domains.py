#!/usr/bin/env python3
"""
10 — bind each context to its PRODUCTION domain (the context<->domain map).

Run on the production container AFTER loading the DB seed. The seed carries the dev bench hosts
(*.modx on the internal zone); this rewrites each context's http_host / site_url / base_url to the production
domain so host-based context resolution lands correctly.

DO NOT PAIR THIS WITH A SEED LOAD BY HAND — use `setup/load-seed.sh --profile prod`, which loads
the seed, binds, and verifies as ONE operation. A seed load whose bind step was skipped leaves
production resolving on bench hosts with nothing complaining; the one door removes that
possibility. This script stays runnable standalone for re-binding an already-loaded DB.

http_host is the context-resolution key — it must equal the public domain nginx forwards in the
Host header. site_url drives absolute-link generation, so it carries the public scheme: https for
the edge-terminated contexts (infra's vhost owns TLS; MODx still speaks http internally). This
reverses the old "internal HTTP only" note for the public contexts.

The domain facts themselves live in setup/domains.py — one home, shared with bench-domains.py and
verify-domains.py. Idempotent, and self-verifying: it re-reads what it wrote and exits non-zero if
the DB does not match the binding.
"""
import sys, pymysql, domains, settings_guard

PROFILE = 'prod'

conn = pymysql.connect(**domains.db_config()); cur = conn.cursor()

# Photograph every settings row before this pass touches anything, and
# REFUSE THE COMMIT if a setting that had a value no longer does. Three times on
# 2026-08-12 a pass here emptied settings nobody asked it to touch (the MODX_SSO_OIDC
# set, the outward-facing settings, merged elements) and every one was found by somebody
# stumbling into it rather than by the pass saying anything. A loss rolls the whole pass
# back and exits 1, so the estate is as it was; a removal this pass MEANS to make is
# declared with g.allow(scope, context, key). Drilled in tests/settings-guard.py.
g = settings_guard.start(conn, '10-production-domains')

def set_ctx(ctx, key, value):
    cur.execute("SELECT 1 FROM modx_context_setting WHERE context_key=%s AND `key`=%s", (ctx, key))
    if cur.fetchone():
        cur.execute("UPDATE modx_context_setting SET value=%s WHERE context_key=%s AND `key`=%s",
                    (value, ctx, key))
    else:
        cur.execute("INSERT INTO modx_context_setting (context_key,`key`,value,xtype) "
                    "VALUES (%s,%s,%s,'textfield')", (ctx, key, value))

for ctx, settings in domains.expected_settings(PROFILE).items():
    # only touch contexts that actually exist (an older seed predates `claude`)
    if not domains.context_exists(cur, ctx):
        print(f'  {ctx}: absent, skipped')
        continue
    for key, value in settings.items():
        set_ctx(ctx, key, value)
    print(f"  {ctx} -> {settings['site_url']}")

g.commit()

# Self-verify: what was just written must equal the binding. A silent partial write is exactly the
# failure mode of a skipped bind, so this fails loudly instead of printing success.
bad = domains.drift(cur, PROFILE)
cur.close(); conn.close()
if bad:
    for ctx, key, want, got in bad:
        print(f'  DRIFT {ctx}.{key}: expected {want!r}, found {got!r}', file=sys.stderr)
    sys.exit('10-production-domains: the bind did NOT hold — see drift above')

print('Production domains bound + verified. Clear the MODx cache after this.')
