#!/usr/bin/env python3
"""
verify-domains — assert that a MODx DB's context settings match their binding.

    setup/verify-domains.py --profile prod     # public domains  (setup/domains.py PROD)
    setup/verify-domains.py --profile bench    # *.modx.<internal zone>    (setup/domains.py BENCH)

Read-only. Exit 0 = every existing context's http_host / site_url / base_url matches; exit 1 =
drift, printed one line per wrong value. Any other failure (DB unreachable, etc.) exits 2, so a
caller can tell "the site is wrong" from "the check could not run".

Why: the seed carries bench hosts by design, and the prod bind used to be a separate hand-run
step (setup/10) that a seed reload could silently skip — prod then resolved on the bench hosts with
nothing complaining. `setup/load-seed.sh` now makes the bind unskippable at load time; this is the
standing assertion for everything AFTER load time — a manual DB copy, a restore, a hand-edited
setting. Safe to run on production (it writes nothing) and cheap enough to schedule.

Env: MODX_DB_HOST / MODX_DB_USER / MODX_DB_PASS / MODX_DB_NAME (dev-box values as defaults).
"""
import argparse, sys, pymysql, domains

ap = argparse.ArgumentParser(description='verify MODx context domain bindings (read-only)')
ap.add_argument('--profile', required=True, choices=sorted(domains.PROFILES),
                help="which binding the DB is expected to match")
ap.add_argument('--quiet', action='store_true', help='print nothing when there is no drift')
args = ap.parse_args()

try:
    conn = pymysql.connect(**domains.db_config())
    cur = conn.cursor()
    bad = domains.drift(cur, args.profile)
    live = domains.live_settings(cur, set(domains.binding(args.profile)))
    cur.close(); conn.close()
except Exception as exc:                       # could not run the check at all — distinct from drift
    print(f'verify-domains: cannot check ({exc.__class__.__name__}: {exc})', file=sys.stderr)
    sys.exit(2)

if bad:
    print(f'verify-domains: {len(bad)} setting(s) do NOT match the {args.profile} binding',
          file=sys.stderr)
    for ctx, key, want, got in bad:
        print(f'  {ctx}.{key}: expected {want!r}, found {got!r}', file=sys.stderr)
    print('  fix: setup/load-seed.sh --profile %s (fresh load) or the profile binder '
          '(setup/10-production-domains.py | setup/bench-domains.py) on an already-loaded DB'
          % args.profile, file=sys.stderr)
    sys.exit(1)

if not args.quiet:
    for ctx in sorted(live):
        print(f"  {ctx} -> {live[ctx].get('site_url')}")
    print(f'verify-domains: OK — matches the {args.profile} binding')
