#!/usr/bin/env python3
"""bench-scheme-is-declared — the bench's site_url scheme comes from the estate, not from source.

THE DEFECT. `setup/domains.py` built the bench binding as `(f'{ctx}.{zone}', 'http')` — the zone
declared, the scheme hardcoded. That was true when a bench meant a directory on somebody's
workstation. The bench is now MODx staging on its own host behind nginx with four estate-CA
certificates, and no estate could say so.

WHAT IT LOOKED LIKE ON THE GROUND, 2026-08-13, all four contexts:

    http://claude.<bench zone>/    301 -> https://claude.<bench zone>/
    https://claude.<bench zone>/   302 -> http://claude.<bench zone>/sso-login    <- MODx's own link

The edge catches it and redirects back, so nothing looks broken and every absolute URL MODx
generates carries the wrong scheme. That is the shape that survives for months: a defect whose only
symptom is an extra redirect nobody counts. ha found it by reading, not by anything failing.

WHAT IS ASSERTED — and note that none of it asserts `https`, because the estate decides that:
  - the bench scheme is whatever the estate file says
  - an estate that says NOTHING gets `http`, so no existing estate moves by being upgraded
  - a scheme that is neither `http` nor `https` REFUSES at import, rather than writing nonsense
    into every context's site_url
  - the production map is untouched by any of it — separate profile, separate declaration
  - the host half still comes from `bench_zone` and is not disturbed by the scheme

A known-answer control runs first. Reads only: this stands up no database.

    python3 tests/bench-scheme-is-declared.py
"""
import importlib
import json
import os
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


def bench_for(domains_block):
    """Import setup/domains against a throwaway estate file and return (rc, BENCH, stderr).

    A SUBPROCESS PER CASE, deliberately. `estate.py` reads MODX_ESTATE at import time and caches
    it, so re-importing in one process would silently keep the first estate — and the test would
    then assert four times about one file while appearing to cover four. That is the mutation that
    never applied, in test form.
    """
    with tempfile.TemporaryDirectory() as d:
        p = Path(d) / "estate.json"
        p.write_text(json.dumps({
            "web_context": {"name": "Web", "description": "", "rank": 1, "site_name": "T"},
            "contexts": {"family": {"name": "Family", "description": "", "rank": 2,
                                    "site_name": "Family", "protected": True, "home": "home"},
                         "claude": {"name": "Claude", "description": "", "rank": 3,
                                    "site_name": "Claude", "protected": True, "home": "home"}},
            "admin_username": "t",
            "users": [], "groups": {}, "system_settings": {},
            "domains": domains_block,
        }))
        code = ("import sys, json; sys.path.insert(0, %r); import domains; "
                "print(json.dumps(domains.BENCH))" % str(REPO / "setup"))
        r = subprocess.run([sys.executable, "-c", code], capture_output=True, text=True,
                           env={**os.environ, "MODX_ESTATE": str(p)})
        out = json.loads(r.stdout) if r.returncode == 0 and r.stdout.strip() else None
        return r.returncode, out, (r.stderr or "").strip()


print("bench-scheme-is-declared — the estate decides the bench's scheme\n")

# ── control ────────────────────────────────────────────────────────────────────────────────────
rc, bench, err = bench_for({"contexts": ["family", "claude"], "prod": {},
                            "bench_zone": "modx.example", "bench_scheme": "http"})
check(rc == 0 and bench is not None, "control: the harness can import domains at all", err)
check(bench == {"family": ["family.modx.example", "http"], "claude": ["claude.modx.example", "http"]},
      "control: an http declaration produces an http binding", json.dumps(bench))

# ── the declaration is honoured ────────────────────────────────────────────────────────────────
rc, bench, err = bench_for({"contexts": ["family", "claude"], "prod": {},
                            "bench_zone": "modx.example", "bench_scheme": "https"})
check(rc == 0 and bench == {"family": ["family.modx.example", "https"],
                            "claude": ["claude.modx.example", "https"]},
      "an https declaration produces an https binding", json.dumps(bench) or err)

# ── silence still means http ───────────────────────────────────────────────────────────────────
rc, bench, err = bench_for({"contexts": ["family", "claude"], "prod": {},
                            "bench_zone": "modx.example"})
check(rc == 0 and bench == {"family": ["family.modx.example", "http"],
                            "claude": ["claude.modx.example", "http"]},
      "an estate that declares no scheme still gets http — nothing moves by upgrading",
      json.dumps(bench) or err)

# ── nonsense refuses rather than writing itself into every site_url ────────────────────────────
rc, bench, err = bench_for({"contexts": ["family", "claude"], "prod": {},
                            "bench_zone": "modx.example", "bench_scheme": "htp"})
check(rc != 0, "a scheme that is neither http nor https REFUSES at import", f"rc={rc} {err}")
check("bench_scheme" in err, "the refusal names the key it refused on", err)

# ── the host half is unaffected ────────────────────────────────────────────────────────────────
rc, bench, err = bench_for({"contexts": ["family", "claude"], "prod": {},
                            "bench_zone": "other.zone", "bench_scheme": "https"})
check(rc == 0 and bench == {"family": ["family.other.zone", "https"],
                            "claude": ["claude.other.zone", "https"]},
      "the host still comes from bench_zone and the scheme does not disturb it",
      json.dumps(bench) or err)

# ── production is a different profile and is not touched ───────────────────────────────────────
with tempfile.TemporaryDirectory() as d:
    p = Path(d) / "estate.json"
    p.write_text(json.dumps({
        "web_context": {"name": "Web", "description": "", "rank": 1, "site_name": "T"},
        "contexts": {"family": {"name": "Family", "description": "", "rank": 2,
                                "site_name": "Family", "protected": True, "home": "home"}},
        "admin_username": "t", "users": [], "groups": {},
        "system_settings": {},
        "domains": {"contexts": ["family"], "prod": {"family": ["home.example.com", "https"]},
                    "bench_zone": "modx.example", "bench_scheme": "http"},
    }))
    code = ("import sys, json; sys.path.insert(0, %r); import domains; "
            "print(json.dumps(domains.PROD))" % str(REPO / "setup"))
    r = subprocess.run([sys.executable, "-c", code], capture_output=True, text=True,
                       env={**os.environ, "MODX_ESTATE": str(p)})
    prod = json.loads(r.stdout) if r.returncode == 0 else None
check(prod == {"family": ["home.example.com", "https"]},
      "the production map is untouched — separate profile, separate declaration", str(prod))

# ── and THIS estate, which is the one that was wrong ───────────────────────────────────────────
# Not a fixture: the real declaration, so the fix is proven where it has to hold.
live = os.environ.get("MODX_ESTATE") or str(Path.home() / "gitea/work-products/modx-estate/estate.json")
if Path(live).is_file():
    code = ("import sys, json; sys.path.insert(0, %r); import domains; "
            "print(json.dumps(domains.BENCH))" % str(REPO / "setup"))
    r = subprocess.run([sys.executable, "-c", code], capture_output=True, text=True,
                       env={**os.environ, "MODX_ESTATE": live})
    b = json.loads(r.stdout) if r.returncode == 0 else {}
    check(bool(b) and all(v[1] == "https" for v in b.values()),
          f"the live estate declares its bench https on all {len(b)} contexts", json.dumps(b))
else:
    print(f"  ---   the live estate file is not at {live}; that case was NOT checked")

print(f"\n{PASS} passed, {FAIL} failed")
sys.exit(0 if FAIL == 0 else 1)
