#!/bin/bash
# manifest-drift.sh — run the estate's shared CI drift-check (enforcement path (a))
# against this repository's service.json.
#
# WHY A WRAPPER RATHER THAN CALLING THE CHECKER DIRECTLY — two reasons, both about not weakening it.
#
# 1. The shared checker counts a `requires[]` entry as DECLARED only when its `kind` is one of
#    secret|endpoint|flag. That filter is right for the "declared but never read" WARNING, and wrong
#    for the "read but never declared" FAILURE: MODX_ROOT is kind `mount`, MODX_DB_* is kind
#    `db-user`, and both are read from the environment by this repository's tooling. Declared
#    correctly, they are still reported as undeclared drift. Typing them as `secret` to buy a green
#    run would be lying about what they are, so instead this wrapper passes them through --allow —
#    and it DERIVES that list FROM THE MANIFEST rather than hand-typing it. A var that is read and
#    declared nowhere still fails. The moment infra widens the checker's filter, this list becomes
#    redundant on its own and nothing here has to change. Reported to infra on the ticket.
#
# 2. Test-harness variables are not prerequisites of the atom. `KUBER_CRYPTO` points a test at
#    kuber's checkout; `TRAUTH_LOG` is where a probe writes. Declaring them in service.json would
#    assert that a deployment must provide them, which is false. They are listed in the manifest's
#    `$test_fixtures` — visible, owned, and read from there by this script, never typed twice.
#
# Exit 0 = no undeclared reads. Exit 1 = drift, named.

set -u
REPO="$(cd "$(dirname "$0")/.." && pwd)"
MANIFEST="${MANIFEST:-$REPO/service.json}"   # overridable so the check can be drilled against a mutated copy
CHECKER="${MANIFEST_CHECKER:-$HOME/gitea/work-products/config/scripts/service-manifest/check-manifest.py}"

[ -r "$MANIFEST" ] || { echo "FAIL: no manifest at $MANIFEST"; exit 1; }
if [ ! -r "$CHECKER" ]; then
  echo "REFUSED: the estate's shared drift-checker is not readable at $CHECKER."
  echo "This test does not fall back to a local reimplementation — a second copy of the rule is the"
  echo "defect it exists to catch. Set MANIFEST_CHECKER, or fix the checkout."
  exit 1
fi

# Everything this manifest declares, of ANY kind, plus the named test fixtures.
ALLOW="$(python3 - "$MANIFEST" <<'PY'
import json, re, sys
m = json.load(open(sys.argv[1]))
names = set()
def walk(k):
    if isinstance(k, str):
        for p in k.split("+"):
            p = p.strip()
            if re.fullmatch(r"[A-Z_][A-Z0-9_]*", p): names.add(p)
    elif isinstance(k, list):
        for e in k: walk(e)
    elif isinstance(k, dict):
        for v in k.values(): walk(v)
for r in m.get("requires", []): walk(r.get("key"))
names |= set(m.get("$test_fixtures", []))
print(",".join(sorted(names)))
PY
)" || exit 1

# ---------------------------------------------------------------------- control
# Prove the checker can still FAIL with this allowlist in place. A wrapper that widens
# --allow until everything passes is a check that can only go green, which is the exact
# shape of two "guardrails" this repository shipped in July that could never go red.
CTL="$(mktemp -d)"; trap 'rm -rf "$CTL"' EXIT
cp "$MANIFEST" "$CTL/service.json"
# The planted name is ASSEMBLED at runtime and never appears as a literal in this file. It did on
# the first run, and the checker — which scans .sh too — found it in this very script and failed the
# real repository on a fault the control had planted. Correct behaviour by the checker; a bug in the
# probe. Keeping the note: a test that writes a fault must not write it into the tree under test.
# NOT $RANDOM: it is a bash BUILTIN, and the shared drift-checker reads `$RANDOM` as an
# environment variable and reports this very file for using it — a check reporting itself.
# $$ and the epoch are as unique as this needs and neither looks like an env read.
PLANTED="MODX_$$_$(date +%s)_CONTROL"
printf 'import os\nx = os.environ["%s"]\n' "$PLANTED" > "$CTL/planted.py"
if python3 "$CHECKER" --manifest "$CTL/service.json" --root "$CTL" --allow "$ALLOW" >/dev/null 2>&1; then
  echo "FAIL control: a planted undeclared env read went GREEN — this check is measuring nothing."
  exit 1
fi
echo "ok    control: a planted undeclared read still fails with the derived allowlist in place"

echo "ok    allowlist derived from the manifest ($(printf '%s' "$ALLOW" | tr ',' '\n' | wc -l) names), nothing hand-typed"
python3 "$CHECKER" --manifest "$MANIFEST" --root "$REPO" --allow "$ALLOW"
rc=$?
[ $rc -eq 0 ] && echo "PASS — no env var is read in this repository without being declared or named a fixture"
exit $rc
