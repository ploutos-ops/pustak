#!/usr/bin/env python3
"""21 — rename a context key everywhere it is stored.

WHY THIS IS A VERB AND NOT A HAND-RUN UPDATE. A MODx context key is a PRIMARY KEY that is COPIED
into every row belonging to it, in a dozen tables, by string. There is no foreign key to cascade
and no single place to change. One change renamed two of them — `family` -> `family-alice` and
`claude` -> `platform`, so MODx keys on the estate's tenant key — and the estate will
rename others as tenants come and go. A rename done by hand is a rename that misses a table on a
ground nobody checks.

WHAT IT TOUCHES, and note that the list is DERIVED rather than typed:

  * `modx_context`.`key` — the row itself.
  * every `context_key` column in the schema, found from information_schema. Typed lists go stale
    the first time MODx adds a table; the count is printed per table so a table that unexpectedly
    holds nothing is visible rather than assumed.
  * `modx_access_context`.`target` — the same fact under a different column name, which is exactly
    why it is named here explicitly: a derivation keyed on the column NAME cannot see it.
  * `modx_documentgroup_names`.`name` for the per-context file group `<ctx>ProtectedFiles`. That
    name is DERIVED from the context key by `access_model.FILE_GROUPS`, so a rename that moved only
    the rows would leave `setup/04` to CREATE the new group and strand the old one holding every
    file — two groups over one set of documents, one of them granted to nobody. Measured on the
    bench 2026-09-22 when exactly that happened: `familyProtectedFiles` and
    `family-aliceProtectedFiles`, 472 members each, after a suite run converged a half-renamed
    ground. The rename carries the derived name so `04` finds it already correct.

WHAT IT REFUSES:
  * CTX_FROM absent and CTX_TO present — reported as already renamed, exit 0, nothing written.
  * CTX_FROM absent and CTX_TO absent — there is no such context; exit 1.
  * CTX_TO already present beside CTX_FROM — a merge, not a rename; exit 1 rather than silently
    fold two contexts into one.
  * a CTX_TO that is not a legal context key — the same shape `11-add-context` enforces.
  * ANY settings drift. Every context_setting row is photographed before and after; the after must
    equal the before with the key renamed, exactly — no row lost, none gained, no value changed.
    A difference rolls the whole rename back. This is stricter than settings_guard's loss check,
    which cannot express a rename: to it, every row of the old context has been deleted.

Everything happens in ONE transaction. A failure anywhere leaves the ground as it was.

    MODX_ROOT="$MODX_ROOT" CTX_FROM=family CTX_TO=family-alice python3 setup/21-rename-context.py
    ... --dry-run                     count every row it would move and write nothing
"""
import os
import re
import sys

import pymysql
from domains import db_config

DRY = '--dry-run' in sys.argv

CTX_FROM = (os.environ.get('CTX_FROM') or '').strip()
CTX_TO = (os.environ.get('CTX_TO') or '').strip()
if not CTX_FROM or not CTX_TO:
    sys.exit('21-rename-context: CTX_FROM and CTX_TO are both required. Nothing written.')
if CTX_FROM == CTX_TO:
    sys.exit(f'21-rename-context: CTX_FROM and CTX_TO are both {CTX_FROM!r}. Nothing to do.')

# The same shape a context key may take anywhere else in this software. A key that cannot be a
# hostname label cannot be a bench name, and `EdgeContext.php` would refuse it at the door.
if not re.fullmatch(r'[a-z0-9]([a-z0-9-]*[a-z0-9])?', CTX_TO):
    sys.exit(f'21-rename-context: CTX_TO={CTX_TO!r} is not a legal context key — lowercase '
             f'letters, digits and hyphens, starting and ending with a letter or digit. '
             f'Nothing written.')
# MODx owns these two and they are not tenants. Renaming a context ONTO one would take the manager
# out, which is a locked-out estate and not a rename.
if CTX_TO in ('mgr', 'web') or CTX_FROM in ('mgr', 'web'):
    sys.exit(f'21-rename-context: {"CTX_TO" if CTX_TO in ("mgr", "web") else "CTX_FROM"} is a '
             f'MODx-internal context. Nothing written.')

conn = pymysql.connect(**db_config())
cur = conn.cursor()
SCHEMA = conn.db.decode() if isinstance(conn.db, bytes) else conn.db


def one(sql, args=()):
    cur.execute(sql, args)
    r = cur.fetchone()
    return r[0] if r else None


def settings_photo():
    cur.execute("SELECT context_key, `key`, value FROM modx_context_setting")
    return {(c, k): v for c, k, v in cur.fetchall()}


have_from = one("SELECT COUNT(*) FROM modx_context WHERE `key`=%s", (CTX_FROM,))
have_to = one("SELECT COUNT(*) FROM modx_context WHERE `key`=%s", (CTX_TO,))

if not have_from and have_to:
    print(f'21-rename-context: {CTX_FROM!r} is absent and {CTX_TO!r} is present — already renamed. '
          f'Nothing written.')
    sys.exit(0)
if not have_from:
    sys.exit(f'21-rename-context: no context {CTX_FROM!r} on this ground, and no {CTX_TO!r} either. '
             f'Nothing written.')
if have_to:
    sys.exit(f'21-rename-context: {CTX_TO!r} ALREADY EXISTS beside {CTX_FROM!r}. Renaming onto it '
             f'would merge two contexts into one, and this verb does not merge. Nothing written.')

# Every column in this schema that stores a context key BY NAME, plus the one that stores it under
# another name. Derived so a table added by a MODx upgrade cannot be missed.
cur.execute("SELECT table_name, column_name FROM information_schema.columns "
            "WHERE table_schema=%s AND column_name='context_key' ORDER BY table_name", (SCHEMA,))
targets = [(t, c) for t, c in cur.fetchall()]
targets.append(('modx_access_context', 'target'))          # the same fact, a different column name
targets.append(('modx_context', 'key'))                    # the row itself, renamed last

print(f"21-rename-context: {CTX_FROM} -> {CTX_TO}"
      f"{'  (DRY RUN — nothing will be written)' if DRY else ''}")
print(f"  {len(targets)} column(s) hold a context key in schema {SCHEMA}")

# Objects whose NAME is derived from the context key. A rename that leaves these behind is worse
# than one that fails: `04` builds the new one, the old keeps its memberships, and the estate has
# two groups over one set of files with no error anywhere.
DERIVED_NAMES = [('modx_documentgroup_names', 'name', '{ctx}ProtectedFiles')]

MEMBERSHIP = {'modx_documentgroup_names': ('modx_document_groups', 'document_group')}

for table, column, pattern in DERIVED_NAMES:
    old_name, new_name = pattern.format(ctx=CTX_FROM), pattern.format(ctx=CTX_TO)
    gid = one(f"SELECT id FROM `{table}` WHERE `{column}`=%s", (new_name,))
    if gid is None:
        continue
    # An EMPTY group of the target name is ADOPTED, not refused. setup/04 creates
    # `<ctx>ProtectedFiles` from the estate's declaration before it does anything else, so a
    # converge run against a ground whose contexts have not been renamed yet leaves exactly this:
    # an empty group named for a context that is not there. Measured on production 2026-09-22 —
    # ids 6 and 7, `family-alice` and `platform`, nought members between them, left by a
    # deploy_modx_documents whose 04 then refused. Refusing here would make that residue block the
    # very migration that resolves it; a group with MEMBERS is a different thing and still refuses.
    mtable, mcol = MEMBERSHIP[table]
    members = one(f"SELECT COUNT(*) FROM `{mtable}` WHERE `{mcol}`=%s", (gid,))
    if members:
        sys.exit(f"21-rename-context: {new_name!r} ALREADY EXISTS in {table} (id {gid}) and holds "
                 f"{members} member(s). This rename would produce two groups over one set of "
                 f"documents. Merge them first. Nothing written.")
    print(f"  adopting empty {new_name!r} (id {gid}) left by an earlier converge — it will be "
          f"dropped and the rename will carry {old_name!r} onto the name")
    if not DRY:
        cur.execute(f"DELETE FROM `{table}` WHERE id=%s", (gid,))

before = settings_photo()
moved_total = 0
try:
    for table, column in targets:
        n = one(f"SELECT COUNT(*) FROM `{table}` WHERE `{column}`=%s", (CTX_FROM,))
        moved_total += n
        if not DRY and n:
            cur.execute(f"UPDATE `{table}` SET `{column}`=%s WHERE `{column}`=%s",
                        (CTX_TO, CTX_FROM))
        print(f"    {'would move' if DRY else 'moved'} {n:>5}  {table}.{column}")

    for table, column, pattern in DERIVED_NAMES:
        old_name, new_name = pattern.format(ctx=CTX_FROM), pattern.format(ctx=CTX_TO)
        n = one(f"SELECT COUNT(*) FROM `{table}` WHERE `{column}`=%s", (old_name,))
        moved_total += n
        if not DRY and n:
            cur.execute(f"UPDATE `{table}` SET `{column}`=%s WHERE `{column}`=%s",
                        (new_name, old_name))
        print(f"    {'would rename' if DRY else 'renamed'} {n:>3}  {table}.{column}: "
              f"{old_name} -> {new_name}")

    if DRY:
        conn.rollback()
        print(f"  {moved_total} row(s) would move. Nothing written.")
        sys.exit(0)

    # The settings must be the same settings, under the new key. Not "nothing was lost" — the same
    # rows, the same values, no additions.
    expected = {((CTX_TO if c == CTX_FROM else c), k): v for (c, k), v in before.items()}
    after = settings_photo()
    if after != expected:
        missing = {i: expected[i] for i in expected.keys() - after.keys()}
        extra = {i: after[i] for i in after.keys() - expected.keys()}
        changed = {i: (expected[i], after[i]) for i in expected.keys() & after.keys()
                   if expected[i] != after[i]}
        conn.rollback()
        sys.exit(f"21-rename-context: SETTINGS DRIFT — rolled back, the ground is as it was.\n"
                 f"  rows the rename should have produced and did not: {missing}\n"
                 f"  rows that appeared and should not have:           {extra}\n"
                 f"  values that changed:                              {changed}")

    left = one("SELECT COUNT(*) FROM modx_context WHERE `key`=%s", (CTX_FROM,))
    if left:
        conn.rollback()
        sys.exit(f"21-rename-context: {CTX_FROM!r} still has {left} context row(s) after the "
                 f"rename — rolled back, the ground is as it was.")

    conn.commit()
    print(f"  {moved_total} row(s) moved, {len(before)} setting(s) carried over unchanged. "
          f"Committed.")
finally:
    cur.close()
    conn.close()
