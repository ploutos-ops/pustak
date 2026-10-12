"""The bench-zone derivation, both directions.

Google refuses a callback to a private name, so a context answering in the bench zone cannot use
Google at all; that is the one case where 12-sso.py may choose the OIDC targets for itself. Every
other case stays a decision, and production is never in a bench zone.
"""
import os, sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REPO / 'setup'))
os.environ.setdefault(
    'MODX_ESTATE',
    str(Path.home() / 'gitea/work-products/modx-estate/estate.json'))
import domains

zone = domains.BENCH_ZONE
print('declared bench zone:', zone)
live = {'claude': f'claude.{zone}', 'family': f'family.{zone}',
        # Fictional public hosts on purpose: the software repository carries no estate
        # (estate-clean.sh, which caught the first version of this file naming two real ones).
        'corporate': 'corp.example.com', 'worldview': 'intranet.example.org'}
got = domains.contexts_on_the_bench_zone(live)
assert got == ['claude', 'family'], got
print('ok   bench-zone contexts derived:', got)
assert 'corporate' not in got and 'worldview' not in got
print('ok   public contexts are NOT derived — production cannot be moved this way')
assert domains.contexts_on_the_bench_zone({}) == []
assert domains.contexts_on_the_bench_zone(None) == []
print('ok   nothing live means nothing derived')
assert domains.contexts_on_the_bench_zone({'x': 'notthezone.example.com'}) == []
print('ok   a host outside the zone is not derived')
