"""The two canonical forms of a MODx element/document body — on disk, and in the database.

MODx stores element/document bodies in the DB WITHOUT a trailing newline, while every editor writes
one. `canon()` is the DISK form (CRLF/CR -> LF, exactly one trailing newline; empty stays empty),
`db_body()` is the DATABASE form (the same LF normalisation, no trailing newline). They are
inverses, which is what makes DB -> file -> DB a fixed point: a restore does not report a
newline-only diff on every body, and a redeploy does not report drift on a body nobody touched.

One definition, imported by every writer so they cannot drift:
  * export.py                    (DB elements -> common/)              disk
  * seat/pull.py                 (DB documents -> seat/documents/)  ]  disk   all three live in
  * seat/push.py                 (files -> DB)                      ]         the estate repository
  * seat/hooks/pre-commit        (hand-commit -> canonical)         ]  disk
  * setup/04-access-control.py   (protectOnSave file -> DB)            db
  * setup/verify-file-protection.py (what it expects to find in the DB) db

AND ONE WRITER THAT CANNOT IMPORT THIS FILE. `scripts/apply-modx-elements.sh` in infra's config repository is
shell, and hex-encodes each body with `perl -0777 -ne 's/\\n\\z//'` — exactly `db_body()` for the LF
files this estate commits. It is listed here because a reader of that line needs to know it is
restating a rule owned here, and because the day the two disagreed cost a red deploy verifier: see
`db_body()` below.
"""


def canon(body):
    """The on-disk form: LF line endings, exactly one trailing newline. Empty stays empty."""
    b = (body or '').replace('\r\n', '\n').replace('\r', '\n')
    return b if (b == '' or b.endswith('\n')) else b + '\n'


def db_body(body):
    """The database form: LF line endings, NO trailing newline. The inverse of `canon()`.

    WHY THIS EXISTS AS A FUNCTION RATHER THAN A SLICE AT EACH CALL SITE (found 2026-08-18,
    fixed 2026-08-20). `setup/04-access-control.py` wrote the `protectOnSave` body into
    `modx_site_plugins.plugincode` VERBATIM — file bytes, trailing newline and all — while
    `apply-modx-elements.sh` wrote the same file one byte shorter, and
    `setup/verify-file-protection.py` expected the shorter one. So on any ground whose plugin was
    last written by 04-access-control.py the verifier reported DRIFT of exactly one byte, on work
    that was correct, on every documents deploy. Its remedy line said `re-run
    setup/04-access-control.py` — which is the write that CAUSES the mismatch, so following the
    advice reproduced the failure. A check that cries wolf on correct work is one people learn to
    ignore, and then it protects nothing.

    Only ONE trailing newline is removed, and only at the end. A body that ends in a blank line
    deliberately keeps it; a CRLF body, a manager edit and a stale deploy all still differ, which is
    what the verifier is for.
    """
    b = (body or '').replace('\r\n', '\n').replace('\r', '\n')
    return b[:-1] if b.endswith('\n') else b
