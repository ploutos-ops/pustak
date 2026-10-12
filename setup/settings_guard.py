#!/usr/bin/env python3
"""settings_guard — a pass that can silently clear a setting must say so, and must not commit it.

Three times in one day a setup pass emptied settings nobody asked it to touch, and
every one was found by a person or an agent stumbling into it rather than by the pass reporting
anything:

    the MODX_SSO_OIDC settings, cleared by an earlier run     -> nobody could sign in
    the outward-facing settings                                -> the same shape, same day
    merged elements dropped by restore_modx_staging            -> the same shape (infra's verb, not this)

infra's own words that morning: **a step that must follow a destructive one is not a note.** ha
filed this rather than let a behavioural tick swallow the durable half, and left the owner
unnamed between infra and skunkworks. The passes are in this repository, so this half is mine.

WHAT IT DOES, and why it is a diff rather than a checklist of important keys.

A list of settings-that-matter is a list somebody has to maintain, and it is wrong the first time
a context gains a setting nobody added to it. This does not have one. It photographs every row in
`modx_context_setting` and `modx_system_settings` before the pass runs, photographs them again
before the commit, and refuses if anything that HAD a value no longer does. It therefore catches
the setting nobody thought to protect — which is exactly the class all three instances came from.

    ADDED       ignored — a pass that creates settings is doing its job
    CHANGED     ignored — 12-sso.py rewrites sso.provider on purpose; that is the point of it
    DISAPPEARED refused — the row is gone, or its value is now empty

REFUSES BY ROLLING BACK, not by printing. pymysql does not autocommit, so at the moment the guard
runs nothing is committed yet: a loss rolls the whole pass back and exits 1, and the estate is
exactly as it was. A guard that reports a loss it has already written is a louder version of the
problem — the operator still has to repair it by hand, which is what happened all three times.

A DELIBERATE REMOVAL IS A WRITTEN ACT. A pass that means to drop a setting declares it:

    guard.allow('context', 'family-alice', 'sso.provider')       # this one may go
    guard.allow('system', None, 'some.retired.key')

Same reasoning as the literal sentence list in tests/failures-name-themselves.sh: a check
that tries to be clever about which losses are fine either nags about correct passes or waves
through a real one, and a check that nags gets switched off.

USE (both shapes; the passes here are module-level scripts, so the second is the one they use):

    with settings_guard.guard(conn, '12-sso') as g:
        ...                          # the pass
        g.allow('context', 'old', 'login_page')

    g = settings_guard.start(conn, '11-add-context')
    ...
    g.commit()                       # compares, then commits — or rolls back and exits 1
"""
import sys

CONTEXT_TABLE = "modx_context_setting"
SYSTEM_TABLE = "modx_system_settings"


def photograph(conn):
    """{(scope, context_key, key): value} for every settings row, both tables.

    Read through the connection the pass is already using, deliberately: a second connection would
    photograph a different transaction and could not see the pass's own uncommitted writes, so a
    loss would be invisible until after the commit — which is the failure this guards against.
    """
    rows = {}
    cur = conn.cursor()
    try:
        cur.execute(f"SELECT context_key, `key`, value FROM {CONTEXT_TABLE}")
        for ctx, key, value in cur.fetchall():
            rows[("context", ctx, key)] = value
        cur.execute(f"SELECT `key`, value FROM {SYSTEM_TABLE}")
        for key, value in cur.fetchall():
            rows[("system", None, key)] = value
    finally:
        cur.close()
    return rows


def losses(before, after, allowed=()):
    """Every key that HAD a value and no longer does. Additions and changes are not losses."""
    allowed = set(allowed)
    out = []
    for ident, was in sorted(before.items(), key=lambda kv: (kv[0][0], kv[0][1] or "", kv[0][2])):
        if ident in allowed:
            continue
        if was is None or str(was).strip() == "":
            continue                                  # it was already empty; losing nothing
        now = after.get(ident, _ABSENT)
        if now is _ABSENT:
            out.append((ident, was, "row deleted"))
        elif now is None or str(now).strip() == "":
            out.append((ident, was, "value emptied"))
    return out


class _Absent:
    def __repr__(self):
        return "<absent>"


_ABSENT = _Absent()


class Guard:
    def __init__(self, conn, label):
        self.conn = conn
        self.label = label
        self.before = photograph(conn)
        self.allowed = set()

    def allow(self, scope, context_key, key):
        """Declare a removal this pass means to make. Unmatched declarations are reported, not
        ignored: a stale allowance is how a guard quietly stops guarding."""
        if scope not in ("context", "system"):
            raise ValueError(f"settings_guard: scope must be 'context' or 'system', not {scope!r}")
        self.allowed.add((scope, context_key if scope == "context" else None, key))

    def check(self):
        after = photograph(self.conn)
        stale = [a for a in self.allowed if a not in self.before]
        gone = losses(self.before, after, self.allowed)
        return gone, stale, len(self.before), len(after)

    def commit(self):
        """Compare, then commit — or roll the whole pass back and exit 1 having written nothing."""
        gone, stale, n_before, n_after = self.check()
        for scope, ctx, key in stale:
            where = f"{ctx}." if ctx else ""
            print(f"  settings_guard: NOTE — declared removal {scope} {where}{key} was not there "
                  f"to remove; the declaration is stale")
        if gone:
            self.conn.rollback()
            print(f"\n  settings_guard: REFUSING to commit {self.label} — "
                  f"{len(gone)} setting(s) that had a value no longer do.\n")
            for (scope, ctx, key), was, how in gone:
                where = f"{ctx}." if ctx else ""
                shown = str(was)
                if len(shown) > 60:
                    shown = shown[:57] + "..."
                print(f"      {scope:7} {where}{key}  ({how})  was: {shown}")
            print(f"\n  Nothing was written: the transaction is rolled back and the estate is as it\n"
                  f"  was before this pass started. A step that must follow a\n"
                  f"  destructive one is not a note. If one of these removals is intended, declare\n"
                  f"  it in the pass: guard.allow('<scope>', '<context>', '<key>').")
            sys.exit(1)
        print(f"  settings_guard: {n_before} setting(s) before, {n_after} after, none lost")
        self.conn.commit()

    # -- context-manager shape, for a pass written as a function ---------------------------------
    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, tb):
        if exc_type is not None:
            self.conn.rollback()
            return False
        self.commit()
        return False


def start(conn, label):
    """Photograph now; the caller ends with g.commit()."""
    return Guard(conn, label)


def guard(conn, label):
    """`with` form: commits on a clean exit, rolls back on an exception or a loss."""
    return Guard(conn, label)
