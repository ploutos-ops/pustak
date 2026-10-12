#!/usr/bin/env python3
"""element-body-db-form — every writer of an element body stores the SAME bytes.

WHY THIS SUITE EXISTS, and it is the whole story in four numbers. `common/plugins/protectOnSave` is
10182 characters on disk, ending in a newline. Three pieces of code care about it:

    setup/04-access-control.py           WRITES it into modx_site_plugins.plugincode
    scripts/apply-modx-elements.sh       WRITES it into modx_site_plugins.plugincode  (the config repo)
    setup/verify-file-protection.py      READS it back and asserts the ground holds the repo's copy

The deploy verb stored 10181 (`perl -0777 -ne 's/\\n\\z//'`), the verifier expected 10181, and
04-access-control.py stored 10182 — the file bytes, verbatim. So on any ground that had just been
converged by the script whose whole job is to make protection correct, the verifier reported DRIFT
of exactly one byte, on work that was correct, on every documents deploy. Its remedy line read
`re-run setup/04-access-control.py`, which is the write that CAUSES the mismatch: following the
advice reproduced the failure.

Nothing was unprotected and nothing was at risk. That is the point. **A red that means nothing is
worse than no check at all**, because the next red — the one that means a manager edit became a
second master — arrives in a colour people have been trained to ignore.

WHAT IS ASSERTED:
  - `canon()` and `db_body()` are inverses, and `db_body()` is idempotent: storing an
    already-stored body does not shorten it a second time;
  - only ONE trailing newline goes, and only at the end — a deliberate blank last line survives,
    and CRLF is still normalised, so the drifts the verifier exists to catch still fire;
  - NO writer computes the form inline. The defect was three copies of a one-line rule and one of
    them being wrong; a fourth copy is the way it comes back, so the SOURCE of each python writer is
    parsed and the expression that reaches the column must be a `db_body(...)` call. Asserting that
    the file merely CONTAINS the import proved nothing: the original defect, put back, passed it;
  - the shell verb in the config repo agrees byte-for-byte — and its rule is READ OUT OF THE SCRIPT
    rather than copied here, over a fixture chosen to tell near-miss rules apart. Running it over
    the real plugin file alone was not enough: that file ends in exactly one newline, so a greedy
    `s/\n+\z//` is indistinguishable on it and a sabotaged verb passed.

Reads only: no database, no web server, no ground. This is a suite about bytes. The one thing it
cannot see is a config repo that is not checked out beside this one — that is a named SKIP, never
a pass.

    python3 tests/element-body-db-form.py
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REPO))
from canon import canon, db_body   # noqa: E402

PASS = 0
FAIL = 0
SKIP = 0


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


def skip(label, why):
    global SKIP
    SKIP += 1
    print(f"  --    {label}: {why}")


print("element bodies — one rule, one owner, and the writers all obey it")

# ── the round trip that has to be a fixed point ────────────────────────────────────────────────
#
# THE DIRECTION THAT MATTERS IS DB -> DISK -> DB, and it is the only one that is a true inverse.
# It is the loop the estate actually runs: export.py writes a ground's bodies to files, the files
# are committed, and a deploy writes them back. If that loop moved a byte, every export would make
# the next deploy look like a change and the verifier would be red forever — which is the whole
# class of defect this file was written for.
DB_BODIES = {
    "an ordinary body": "<?php\nreturn 1;",
    "one line, no newline anywhere": "<?php return 1;",
    "an interior blank line": "<?php\n\nreturn 1;",
    "empty": "",
}

for label, stored in DB_BODIES.items():
    check(db_body(canon(stored)) == stored,
          f"DB -> disk -> DB returns the same bytes ({label})",
          f"{stored!r} became {db_body(canon(stored))!r}")

check(all(not canon(b).endswith("\n\n") and (canon(b) == "" or canon(b).endswith("\n"))
          for b in DB_BODIES.values()),
      "canon() gives every non-empty body exactly one trailing newline")
check(all(not db_body(canon(b)).endswith("\n") for b in DB_BODIES.values()),
      "db_body() gives back a body with no trailing newline at all")

# ── what each form does, stated as assertions rather than left in a docstring ──────────────────
check(db_body("<?php\nreturn 1;\n") == "<?php\nreturn 1;",
      "the ordinary case: exactly the one trailing newline every editor adds is removed")
check(db_body("<?php\nreturn 1;\n\n") == "<?php\nreturn 1;\n",
      "ONE newline goes, not every one — `.rstrip()` here would eat a body's own blank lines",
      repr(db_body("<?php\nreturn 1;\n\n")))
check(db_body("a\r\nb") == "a\nb" and canon("a\r\nb") == "a\nb\n",
      "CRLF is still normalised in both forms, so a manager edit on Windows still differs")
check(db_body("a\n\nb") == "a\n\nb",
      "an interior blank line is never touched", repr(db_body("a\n\nb")))
check(db_body("") == "" and canon("") == "",
      "empty stays empty in both forms — an empty element is not a newline")

# THE ONE ASYMMETRY, ASSERTED SO IT IS A RECORDED PROPERTY AND NOT A FUTURE BUG REPORT.
# A file ending in a BLANK LINE is not representable in this estate: the DB keeps one fewer
# newline than the file has, and the next export writes the file back one byte shorter. Nothing
# is lost but the blank line, and no element in this repo has one — but a reader who discovers it
# by watching a file change under them deserves to find it named here rather than to call it a
# corruption. disk -> DB -> disk is therefore a fixed point for canonical files ONLY.
check(canon(db_body("a\n\n")) == "a\n",
      "a file's trailing BLANK line does not survive the database — known, and it is the store's "
      "property, not a defect in these two functions")
check(canon(db_body("a\n")) == "a\n",
      "a canonical file — one trailing newline — survives disk -> DB -> disk unchanged")

# ── the two python writers put the body through db_body(), and it is READ OFF THE STATEMENT ────
#
# THIS IS THE CHECK THAT HAD TO BE DRILLED, and the first version of it was worthless: it asserted
# that each file CONTAINS `from canon import db_body`. Putting the original defect back — one
# character, `db_body(fh.read())` -> `fh.read()` — left the import in place and the suite stayed
# green. An assertion about an import is an assertion about nothing; what matters is the VALUE that
# reaches the column. So the source is parsed, the expression assigned to the plugin body is found,
# and it must be a db_body(...) call. The alternative — executing the write — needs a converged
# database, and a suite that can only run on a ground is a suite that does not run.
import ast   # noqa: E402


def assigned_expr(path, target):
    """The source of the last expression assigned to `target` at module level, or None."""
    tree = ast.parse(path.read_text(encoding="utf-8"))
    found = None
    for node in ast.walk(tree):
        if isinstance(node, ast.Assign):
            for t in node.targets:
                if isinstance(t, ast.Name) and t.id == target:
                    found = ast.unparse(node.value)
    return found


def is_db_body_call(expr):
    return expr is not None and re.match(r"^db_body\(", expr.strip()) is not None


expr = assigned_expr(REPO / "setup" / "04-access-control.py", "plugin_code")
check(is_db_body_call(expr),
      "04-access-control.py stores the plugin body through db_body(), not the file bytes",
      f"plugin_code = {expr}")

expr = assigned_expr(REPO / "setup" / "verify-file-protection.py", "expected")
check(is_db_body_call(expr),
      "verify-file-protection.py expects db_body() of the file, so it agrees with both writers",
      f"expected = {expr}")

# And no call site keeps a private copy of the rule — the way a fourth disagreement gets born.
INLINE = re.compile(r"""\[:-1\]|rstrip\(\s*['"]\\n['"]\s*\)|endswith\(\s*['"]\\n['"]\s*\)""")
for rel in ("setup/04-access-control.py", "setup/verify-file-protection.py"):
    src = (REPO / rel).read_text(encoding="utf-8")
    body = "\n".join(ln for ln in src.splitlines() if not ln.lstrip().startswith("#"))
    check("from canon import db_body" in src,
          f"{rel} imports the form from canon.py rather than restating it")
    check(not INLINE.search(body),
          f"{rel} computes no trailing-newline rule of its own",
          "found: " + ", ".join(sorted(set(INLINE.findall(body)))))

check("def db_body" in (REPO / "canon.py").read_text(encoding="utf-8"),
      "canon.py is where the rule lives")

# ── the writer that cannot import canon.py ─────────────────────────────────────────────────────
#
# apply-modx-elements.sh is shell, in the config repo, and hex-encodes each body through perl. It is
# the writer that was RIGHT while the python one was wrong, so it is the one to measure against.
#
# ITS RULE IS READ OUT OF THE SCRIPT, NOT COPIED HERE. The first version of this block ran a perl
# expression typed into this file — which proves that A rule matches db_body() and says nothing
# about the rule the estate actually deploys with. That is the SAME defect this whole suite exists
# for, one repository over: a second copy of one fact, agreeing on the day it was written. Infra
# owns that script; when they change the line, this goes red here rather than silently on a ground.
#
# The config repo is not a dependency of this repository, so its absence is a SKIP with a reason
# and never a pass. It sits beside this checkout in the estate's work tree; no path is typed.
PLUGIN = REPO / "common" / "plugins" / "protectOnSave"
VERB = REPO.parent / "config" / "scripts" / "apply-modx-elements.sh"
LABEL = "apply-modx-elements.sh's own perl rule and canon.db_body() store the SAME bytes"

if not PLUGIN.is_file():
    skip(LABEL, f"{PLUGIN} is not in this checkout")
elif not VERB.is_file():
    skip(LABEL, f"the config repo is not checked out beside this repo ({VERB} absent)")
elif not shutil.which("perl"):
    skip(LABEL, "no perl on this box")
else:
    # The line, as the script carries it:  hex="$(perl -0777 -ne '<EXPR>' "$f")"
    m = re.search(r"""perl\s+-0777\s+-ne\s+'([^']*)'""", VERB.read_text(encoding="utf-8"))
    if not m:
        check(False, LABEL,
              f"no `perl -0777 -ne '...'` body-encoder found in {VERB} — either the verb stopped "
              f"encoding bodies that way, or this check stopped being able to see how it does. "
              f"Both are reasons to look, and neither is a pass.")
    else:
        expr = m.group(1)

        def through_the_verb(raw):
            with tempfile.NamedTemporaryFile("wb", suffix=".probe", delete=False) as fh:
                fh.write(raw)
                path = fh.name
            try:
                r = subprocess.run(["perl", "-0777", "-ne", expr, path],
                                   capture_output=True, text=True)
                return (bytes.fromhex(r.stdout.strip()) if r.returncode == 0 else None,
                        r.stderr.strip())
            finally:
                os.unlink(path)

        # THE FIXTURE HAS TO DISCRIMINATE, and the first one did not. Running the verb's rule over
        # the real plugin file alone passed a SABOTAGED verb: the file ends in exactly one newline,
        # so `s/\n\z//` and a greedy `s/\n+\z//` produce identical bytes on it. A check whose
        # fixture cannot tell the rules apart reports green about a question it never asked — the
        # empty-haystack failure, in the middle of a suite written about a one-byte disagreement.
        #
        # LF ONLY, deliberately: every body in this repository is LF (git commits it that way), and
        # the two writers agree on all of them. They do NOT agree on CRLF — the perl rule strips a
        # newline without normalising, `db_body()` normalises first — and that divergence is stated
        # below as a property rather than asserted away, because a CRLF body arriving here IS drift
        # and the verifier catching it is correct behaviour.
        PROBES = {
            "the real plugin": PLUGIN.read_bytes(),
            "one trailing newline": b"<?php\nreturn 1;\n",
            "a trailing BLANK line — the case a greedy rule gets wrong": b"<?php\nreturn 1;\n\n",
            "three trailing newlines": b"<?php\nreturn 1;\n\n\n",
            "no trailing newline at all": b"<?php\nreturn 1;",
            "interior blank lines, one at the end": b"a\n\nb\n",
            "a single newline": b"\n",
            "empty": b"",
        }
        agreed = True
        for label, raw in PROBES.items():
            got, err = through_the_verb(raw)
            want = db_body(raw.decode("utf-8")).encode("utf-8")
            if got != want:
                agreed = False
                check(False, f"{LABEL} — {label}",
                      f"the verb gave {got!r}, db_body() gives {want!r}. "
                      f"The rule read from the script is `{expr}`. {err}")
        if agreed:
            check(True, f"{LABEL} — {len(PROBES)} bodies chosen to tell the rules apart, "
                        f"read from the script as `{expr}`")

        # The known divergence, named. If this ever starts AGREEING, the verb learned to normalise
        # and this suite should be told so rather than quietly keeping a false warning alive.
        got, _ = through_the_verb(b"a\r\nb\r\n")
        check(got == b"a\r\nb\r" and db_body("a\r\nb\r\n") == "a\nb",
              "KNOWN AND INTENDED: on a CRLF body the two writers differ — the verb strips without "
              "normalising, db_body() normalises first. Every body here is LF; a CRLF one is drift "
              "and the verifier is right to say so",
              f"verb gave {got!r}")

print(f"\n{PASS} passed, {FAIL} failed" + (f", {SKIP} not run" if SKIP else ""))
sys.exit(1 if FAIL else 0)
