#!/usr/bin/env python3
"""local-profile-is-inert — the THIRD instance must be invisible to the other two.

WHY THIS SUITE EXISTS. `domains.py` grew a `local` profile so a developer can run the estate on
their own box: same content, same elements, same `http_host`, reached at declared `localhost:<port>`
doors, signing in against a loopback OIDC provider. Every one of those is a new string in a file
that production also reads.

The claim that makes the profile safe is therefore NOT "the local instance works". It is
**"production and the bench do not move"** — and that claim is the kind that stays true by accident
for months and then quietly stops. So it is a file that fails.

WHAT IS ASSERTED:
  - declaring `local_scheme` / `local_oidc` / `bench_ports` changes NOTHING about `binding('prod')`
    or `binding('bench')`, nor about `expected_settings` for either;
  - the PROD `config.context.php` render is byte-identical whatever the local keys say — now
    it is one line requiring EdgeContext.php on every profile, and no profile's render names a host;
  - every local context's `http_host` still ends in the declared bench zone. That is not cosmetic:
    `12-sso.py` refuses to write oidc settings against a public host, `SsoConfig.php` restates the
    refusal at request time, and `regenerate-seed.sh` refuses to build a seed from a ground outside
    the zone. The local profile survives all three BY CONSTRUCTION, and this is what proves it;
  - every local `site_url` authority is exactly the key `bench_port_hosts()` renders — the door and
    the link cannot disagree, which is the bug this profile was created to fix;
  - an estate declaring no `bench_ports` has NO local profile and `binding('local')` refuses, so an
    estate that never heard of any of this moves nowhere;
  - a nonsense `local_scheme` refuses at import, naming the key.

A known-answer control runs first. Reads only: no database, no web server.

    python3 tests/local-profile-is-inert.py
"""
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]

PASS = 0
FAIL = 0


def check(ok, label, detail=""):
    global PASS, FAIL
    if ok:
        PASS += 1
        print(f"  ok    {label}")
    else:
        FAIL += 1
        print(f"  FAIL  {label}")
        if detail:
            print(f"        {detail}")


def _estate(domains_block):
    return json.dumps({
        "web_context": {"name": "Web", "description": "", "rank": 1, "site_name": "T"},
        "contexts": {"family": {"name": "Family", "description": "", "rank": 2,
                                "site_name": "Family", "protected": True, "home": "home"},
                     "claude": {"name": "Claude", "description": "", "rank": 3,
                                "site_name": "Claude", "protected": True, "home": "home"}},
        "admin_username": "t",
        "users": [], "groups": {}, "system_settings": {},
        "domains": domains_block,
    })


def probe(domains_block, code):
    """Run `code` against a throwaway estate in a SUBPROCESS.

    estate.py caches MODX_ESTATE at import, so re-importing in one process would silently keep the
    FIRST estate and this suite would assert many times about one file while appearing to cover
    many — the mutation that never applied, in test form (the reason is stated the same way in
    bench-scheme-is-declared.py).
    """
    with tempfile.TemporaryDirectory() as d:
        p = Path(d) / "estate.json"
        p.write_text(_estate(domains_block))
        pre = f"import sys, json; sys.path.insert(0, {str(REPO / 'setup')!r}); import domains\n"
        r = subprocess.run([sys.executable, "-c", pre + code], capture_output=True, text=True,
                           env={**os.environ, "MODX_ESTATE": str(p)})
        return r.returncode, r.stdout.strip(), (r.stderr or "").strip()


def render(domains_block, profile):
    with tempfile.TemporaryDirectory() as d:
        p = Path(d) / "estate.json"
        p.write_text(_estate(domains_block))
        r = subprocess.run(
            [sys.executable, str(REPO / "setup" / "config-context.py"), "--profile", profile],
            capture_output=True, text=True, env={**os.environ, "MODX_ESTATE": str(p)})
        return r.returncode, r.stdout, (r.stderr or "").strip()


BARE = {"contexts": ["family", "claude"],
        "prod": {"family": ["home.example.com", "https"],
                 "claude": ["claude.example.net", "https"]},
        "bench_zone": "modx.example", "bench_scheme": "https"}
PORTS = dict(BARE, bench_ports={"family": 9001, "claude": 9002})
FULL = dict(PORTS, local_scheme="http", local_oidc="http://localhost:9003")

print("local-profile-is-inert — the third instance is invisible to the other two\n")

# ── controls ───────────────────────────────────────────────────────────────────────────────────
rc, out, err = probe(BARE, "print(json.dumps({'prod': domains.PROD, 'bench': domains.BENCH}))")
check(rc == 0 and out, "control: the harness imports domains against a bare estate", err)
BASELINE = json.loads(out) if rc == 0 and out else {}
check(BASELINE.get("bench", {}).get("family") == ["family.modx.example", "https"],
      "control: the bare estate's bench binding is what it always was", json.dumps(BASELINE))

# ── the other two profiles do not move ─────────────────────────────────────────────────────────
rc, out, err = probe(FULL, "print(json.dumps({'prod': domains.PROD, 'bench': domains.BENCH}))")
check(rc == 0 and json.loads(out or "{}") == BASELINE,
      "declaring the local keys changes NEITHER the prod NOR the bench binding", out or err)

rc, bare_prod, err = render(BARE, "prod")
rc2, full_prod, err2 = render(FULL, "prod")
check(rc == 0 and rc2 == 0 and bare_prod == full_prod,
      "the PROD config.context.php render is BYTE-IDENTICAL with the local keys declared",
      (err or err2) or f"{len(bare_prod)} vs {len(full_prod)} bytes")
check("localhost" not in full_prod,
      "production carries no localhost key — it would be a second unauthenticated door onto it",
      full_prod)

rc, bare_bench, _ = render(PORTS, "bench")
rc2, full_bench, _ = render(FULL, "bench")
check(bare_bench == full_bench,
      "the BENCH render is byte-identical too — local_scheme/local_oidc touch neither profile")

# ── the guards the local profile must survive by construction ──────────────────────────────────
rc, out, err = probe(FULL, "print(json.dumps(domains.expected_settings('local')))")
local = json.loads(out) if rc == 0 and out else {}
check(rc == 0 and local, "the local profile renders expected settings at all", err)
check(all(s["http_host"].endswith(".modx.example") for s in local.values()),
      "every local http_host is still in the declared bench ZONE — this is what keeps 12-sso.py, "
      "SsoConfig.php and regenerate-seed.sh all passing",
      json.dumps({c: s["http_host"] for c, s in local.items()}))

rc, out, err = probe(FULL, "print(json.dumps(domains.bench_port_hosts()))")
doors = json.loads(out) if rc == 0 and out else {}
check(all(local[c]["site_url"] == f"http://{doors[c]}/" for c in local),
      "every local site_url is EXACTLY the door bench_port_hosts renders — the link and the door "
      "cannot disagree",
      json.dumps({c: (local[c]["site_url"], doors.get(c)) for c in local}))

# ── an estate that declares nothing moves nowhere ──────────────────────────────────────────────
rc, out, err = probe(BARE, "domains.binding('local')")
check(rc != 0 and "local" in err,
      "with no bench_ports there is NO local profile and binding('local') REFUSES by name",
      f"rc={rc} {err}")

rc, out, err = probe(dict(FULL, local_scheme="htp"), "print(domains.LOCAL)")
check(rc != 0 and "local_scheme" in err,
      "a local_scheme that is neither http nor https refuses at import, naming the key", err)

# ── the live estate ────────────────────────────────────────────────────────────────────────────
# The render names no host: the context is the edge's header, read by EdgeContext.php.
# Asserted positively (the line is the require) as well as negatively (no host key), because a map
# check alone passes vacuously on a file that carries no map.
EDGE_LINE = "<?php return require MODX_CORE_PATH . 'components/pustak/lib/EdgeContext.php';"
live = os.environ.get("MODX_ESTATE")
if live and os.path.isfile(live):
    for profile in ("prod", "bench", "local"):
        r = subprocess.run([sys.executable, str(REPO / "setup" / "config-context.py"),
                            "--profile", profile], capture_output=True, text=True)
        keys = set(re.findall(r"'([^']+)'\s*=>", r.stdout))
        check(r.returncode == 0 and r.stdout.strip() == EDGE_LINE and not keys,
              f"the LIVE estate's {profile} render is the EdgeContext require and carries no host "
              f"({os.path.basename(live)})", r.stdout.strip() or r.stderr.strip())
else:
    print("  --    live estate not on MODX_ESTATE; the live check did not run")

print(f"\n{PASS} passed, {FAIL} failed")
sys.exit(1 if FAIL else 0)
