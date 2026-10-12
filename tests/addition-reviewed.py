#!/usr/bin/env python3
"""Our addition to MODx is reviewed against the version this atom ships (rulings P12).

The owner, 2026-10-07: "next time there is an update to the package, this code gets reviewed against
it, in case it needs an update." service.json `addition.reviewed_against` names the version the
addition was last reviewed against; `ships` names the version deployed. This is red while the two
differ, and it is in run-all.sh's --before-apply set, so the deploy verb refuses until the review
(docs/upgrades.md Phase 1 step 14) moves both in one commit.

Reads only service.json beside it. Exit 0 equal, 1 different, 2 a field missing.
"""
import json
import sys
from pathlib import Path

doc = json.loads((Path(__file__).resolve().parent.parent / "service.json").read_text())
try:
    want = doc["addition"]["reviewed_against"]
    comp, reviewed = want["component"], want["version"]
except (KeyError, TypeError) as e:
    print(f"REFUSED: service.json addition.reviewed_against is missing or malformed ({e})")
    sys.exit(2)
shipped = [s.get("version") for s in doc.get("ships", []) if s.get("component") == comp]
if len(shipped) != 1:
    print(f"REFUSED: service.json ships names {comp} {len(shipped)} times, not once")
    sys.exit(2)
if shipped[0] != reviewed:
    print(f"FAIL: ships {comp} {shipped[0]}, but our addition was reviewed against {reviewed} — "
          f"review it (docs/upgrades.md Phase 1 step 14), then move addition.reviewed_against")
    sys.exit(1)
print(f"PASS: our addition is reviewed against {comp} {reviewed}, the version shipped")
