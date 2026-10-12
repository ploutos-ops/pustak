#!/usr/bin/env python3
"""migrate-estate-keys — MODx's contexts take the estate's tenant keys.

WHY A ONE-SHOT AND NOT `21-rename-context` RUN BY HAND, ONCE PER PAIR. A task dispatched to a ground
carries NO argv and NO environment of the caller's: what runs is the manifest entry's
own command. So the pairs cannot be passed in — and they are not written here either, because WHICH
keys a particular estate is moving is an estate fact and this repository carries no estate
(tests/estate-clean.sh). They are `context_renames` in the estate file, read below. An estate that
declares none makes this a no-op.

WHAT IT DOES, in order, and it stops at the first failure:

  1. `21-rename-context` for each pair. That verb owns the rename — the rows in every table that
     holds a context key, the derived `<ctx>ProtectedFiles` name, the settings photograph that must
     match exactly under the new key, and the refusals. One transaction per rename.
  2. The DOMAIN REBIND, but only where the ground derives its hostnames from the context key.
     Measured 2026-09-22: a bench ground's `http_host` is `<ctx>.<bench_zone>`, so it moves with the
     key and `bench-domains` must re-derive it. A PRODUCTION ground's does NOT — there `http_host`
     is the tenant's public address, a fact about the tenant and not about MODx's key, so nothing
     needs rebinding. The branch is taken from what the ground actually holds, not from a name or
     a flag.

  3. NOT the access converge. `setup/04` must run after this, and it already does: every deploy
     verb that carries this software runs it, snapshot-guarded. Running it here as well would
     converge the access model twice for one change.

IDEMPOTENT. A second run finds both contexts already renamed, says so, and exits 0.

    python3 setup/migrate-estate-keys.py
    python3 setup/migrate-estate-keys.py --dry-run     count everything, write nothing
"""
import os
import subprocess
import sys

import pymysql
from domains import db_config, DOMAINS
from estate import CONTEXT_RENAMES

HERE = os.path.dirname(os.path.abspath(__file__))
DRY = '--dry-run' in sys.argv

PAIRS = CONTEXT_RENAMES


def rename(old, new):
    env = {**os.environ, 'CTX_FROM': old, 'CTX_TO': new}
    cmd = [sys.executable, os.path.join(HERE, '21-rename-context.py')] + (['--dry-run'] if DRY else [])
    r = subprocess.run(cmd, env=env, cwd=HERE)
    return r.returncode


print('migrate-estate-keys: MODx takes the estate tenant keys'
      f"{'  (DRY RUN)' if DRY else ''}")
if not PAIRS:
    print('  this estate declares no context_renames — nothing to do.')
    sys.exit(0)

for old, new in PAIRS:
    print(f'\n── {old} -> {new}')
    rc = rename(old, new)
    if rc != 0:
        sys.exit(f'\nmigrate-estate-keys: {old} -> {new} exited {rc}. STOPPING — nothing after this '
                 f'point has run, and that rename left the ground as it was.')

# ── does this ground derive its hostnames from the context key? ────────────────────────────────
zone = (DOMAINS.get('bench_zone') or '').strip()
conn = pymysql.connect(**db_config())
cur = conn.cursor()
cur.execute("SELECT context_key, value FROM modx_context_setting WHERE `key`='http_host'")
hosts = dict(cur.fetchall())
cur.close()
conn.close()

derived = sorted(c for c, h in hosts.items() if zone and (h or '').endswith('.' + zone))
print()
if not derived:
    print(f'  http_host on this ground is not derived from the context key '
          f'({", ".join(f"{c}={h}" for c, h in sorted(hosts.items())) or "no rows"}) — '
          f'no domain rebind needed.')
else:
    print(f'  http_host IS derived here ({", ".join(derived)} end in .{zone}) — rebinding.')
    if DRY:
        print('  (dry run: bench-domains not run)')
    else:
        r = subprocess.run([sys.executable, os.path.join(HERE, 'bench-domains.py')], cwd=HERE)
        if r.returncode != 0:
            sys.exit(f'\nmigrate-estate-keys: bench-domains exited {r.returncode}. The renames are '
                     f'COMMITTED and the hostnames are not rebound — this ground answers to the old '
                     f'names until that is fixed.')

print('\nmigrate-estate-keys: done. setup/04 (the access converge) must run after this, and the '
      'deploy verb that carried this software runs it.')
