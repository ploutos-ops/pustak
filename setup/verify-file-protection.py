#!/usr/bin/env python3
"""
verify-file-protection — assert that a MODx database still matches the declared FILE protection.
READ-ONLY: it opens the database, counts, and prints. It never writes.

WHY THIS EXISTS. `04-access-control.py` protects every Static Resource that exists WHEN IT RUNS.
Nothing re-runs it. So the moment a migration, a manager upload or a restore introduces a new
static resource, that file is public and no part of the estate says so — the same silent-degradation
shape as production quietly on bench domains and the s312 watchers that stopped matching what
they watched. A protection nobody checks is a claim, not a control.

INTENT COMES FROM THE SAME DECLARATION AS THE WRITE (access_model.py), never from a second copy
here, and it includes the switch: with PROTECT_FILES = False this asserts the estate is OPEN and
goes green on it, so the owner unprotecting deliberately does not leave a monitor screaming.

What it asserts, per context:
  PROTECT_FILES = True
    1. every modStaticResource (deleted=0) is a member of its context's <ctx>ProtectedFiles group
    2. that group carries a Load/List/View grant for EVERY user group covering the context
    3. that group carries at least a Load grant for anonymous (principal 0)
  PROTECT_FILES = False
    4. those groups hold no memberships at all (a half-applied revert is not "open")
And unconditionally, because it is the failure that takes the whole estate dark at once:
  5. NO file group ever holds memberships without a view grant — membership with no grant does not
     protect a resource, it makes it unreachable for everyone bar sudo, and it presents as every
     link breaking rather than as a permission.

Exit codes, kept distinct because "the site is wrong" and "the check is broken" must never look
alike (a broken check that exits 1 reads as a real fault; one that exits 0 reads as green forever):
  0 = matches the declaration       1 = DRIFT                2 = the check could not run

Usage:  MODX_DB_* env as every other script in setup/ ...
          python3 setup/verify-file-protection.py [--quiet]
"""
import json
import os
import sys

# The DATABASE form of an element body is owned by canon.py at the repo root. This check compares
# what a ground HOLDS against what the writers STORE, so it must ask the same owner they do.
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
from canon import db_body   # noqa: E402

from domains import db_config

try:
    import pymysql
    from access_model import (CONTEXTS, GROUPS, PROTECT_FILES, FILE_CLASS, FILE_GROUPS,
                              PROTECT_PAGES, DOC_CLASS, SUPPORT_ALIAS, MACHINE_DOORS, PROTECTED,
                              UG, POL_VIEW,
                              PLUGIN_NAME, PLUGIN_EVENTS, SETTING_KEY, declaration)
except Exception as exc:                                     # missing dep, stale clone, bad model
    print(f'CHECK-BROKEN: cannot load the access model or pymysql: {exc}', file=sys.stderr)
    sys.exit(2)

QUIET = '--quiet' in sys.argv


def say(msg):
    if not QUIET:
        print(msg)


def main():
    db = db_config()
    conn = pymysql.connect(**db)
    cur = conn.cursor()

    def one(q, a=()):
        cur.execute(q, a); r = cur.fetchone(); return r[0] if r else None

    drift = []
    for ctx in CONTEXTS:
        gname = FILE_GROUPS[ctx]
        gid = one("SELECT id FROM modx_documentgroup_names WHERE name=%s", (gname,))
        if gid is None:
            # Absent group is drift when protection is declared, and simply "never applied" when it
            # is not — with PROTECT_FILES False there is nothing for the group to hold.
            if PROTECT_FILES:
                drift.append(f'{ctx}: resource group {gname} does not exist — 04 has never run here')
            continue

        total = one("SELECT COUNT(*) FROM modx_site_content "
                    "WHERE context_key=%s AND class_key=%s AND deleted=0", (ctx, FILE_CLASS))
        members = one("SELECT COUNT(*) FROM modx_document_groups dg JOIN modx_site_content c "
                      "ON c.id=dg.document WHERE dg.document_group=%s AND c.deleted=0 "
                      "AND c.class_key LIKE %s", (gid, '%modStaticResource'))
        # A MEMBER OF THE WRONG KIND. This group is for static files; a page in it is drift
        # even when it happens to be protected, because the declaration and the fact have parted.
        # Until this check existed the count above included such members and printed
        # `495 protected out of 493` — more members than there are files — and still exited 0. A
        # count that cannot exceed its own total is the whole reason this file is here, so the check
        # that lets it is worse than no count. Matched on the class NAME, not the fully-qualified
        # string, so a legacy `modStaticResource` row is not mistaken for a stranger.
        cur.execute("SELECT c.id, c.alias, c.class_key FROM modx_document_groups dg "
                    "JOIN modx_site_content c ON c.id=dg.document "
                    "WHERE dg.document_group=%s AND c.deleted=0 "
                    "AND c.class_key NOT LIKE %s ORDER BY c.id", (gid, '%modStaticResource'))
        strangers = cur.fetchall()
        if strangers:
            named = ', '.join(f'{r[0]} ({r[1]}, {r[2].rsplit(chr(92), 1)[-1]})' for r in strangers)
            drift.append(f'{ctx}: {len(strangers)} member(s) of {gname} are not static resources — '
                         f'{named}. A page belongs in {PROTECTED}; move it, then re-run setup/04. '
                         f'Only the MODx manager writes these (04 inserts static resources only), '
                         f'so this is a Resource Groups tick on the resource\'s Access tab.')
        viewers = {g for g, (gctxs, _) in GROUPS.items() if ctx in gctxs}
        granted_view = set()
        cur.execute("SELECT n.name FROM modx_access_resource_groups a "
                    "JOIN modx_membergroup_names n ON n.id=a.principal "
                    "WHERE a.target=%s AND a.context_key=%s AND a.policy=%s",
                    (str(gid), ctx, POL_VIEW))
        granted_view = {r[0] for r in cur.fetchall()}
        anon = one("SELECT COUNT(*) FROM modx_access_resource_groups "
                   "WHERE target=%s AND context_key=%s AND principal=0", (str(gid), ctx))

        # (5) unconditional: memberships with no view grant = the whole-estate-dark shape
        if members and not granted_view:
            drift.append(f'{ctx}: BLACK HOLE — {members} files in {gname} and NO view grant on it; '
                         f'those files are unreachable for everyone bar sudo')
            continue

        if PROTECT_FILES:
            unprotected = one("SELECT COUNT(*) FROM modx_site_content c "
                              "WHERE c.context_key=%s AND c.class_key=%s AND c.deleted=0 "
                              "AND NOT EXISTS (SELECT 1 FROM modx_document_groups dg "
                              "WHERE dg.document=c.id AND dg.document_group=%s)",
                              (ctx, FILE_CLASS, gid))
            if unprotected:
                drift.append(f'{ctx}: {unprotected} of {total} static resources are NOT in {gname} '
                             f'— they serve to anyone; re-run setup/04')
            missing = sorted(viewers - granted_view)
            if missing:
                drift.append(f'{ctx}: {gname} carries no view grant for {", ".join(missing)} '
                             f'— a member of that group cannot open its own files')
            if not anon:
                drift.append(f'{ctx}: {gname} has no anonymous grant — a deep link 404s instead of '
                             f'sending the login page')
            say(f'  {ctx}: {members}/{total} protected, view={sorted(granted_view)}, anon-grant={"yes" if anon else "NO"}')
        else:
            if members:
                drift.append(f'{ctx}: PROTECT_FILES is False but {gname} still holds {members} '
                             f'memberships — the revert was not applied; re-run setup/04')
            say(f'  {ctx}: unprotected by declaration; {members} memberships, {total} static resources')

    # ── PAGE protection (2026-07-27) ───────────────────────────────────────────────────────────
    # Same argument as the files, one level up: 04 protects the pages that exist when it runs, and
    # nothing re-runs it. Without this, the next page the owner writes is public and no part of the
    # estate says so — which is exactly the shape that let 13/27/41 sit in allContextProtected,
    # protecting nothing, while the written model said "home is protected".
    pgid = one("SELECT id FROM modx_documentgroup_names WHERE name=%s", (PROTECTED,))
    if pgid is None and PROTECT_PAGES:
        drift.append(f'resource group {PROTECTED} does not exist — 04 has never run here')
    elif pgid is not None:
        for ctx in CONTEXTS:
            support = one("SELECT id FROM modx_site_content WHERE context_key=%s AND alias=%s "
                          "AND parent=0 AND deleted=0", (ctx, SUPPORT_ALIAS))
            total = one("SELECT COUNT(*) FROM modx_site_content WHERE context_key=%s "
                        "AND class_key=%s AND deleted=0", (ctx, DOC_CLASS))
            members = one("SELECT COUNT(*) FROM modx_document_groups dg JOIN modx_site_content c "
                          "ON c.id=dg.document WHERE dg.document_group=%s AND c.context_key=%s "
                          "AND c.deleted=0 AND c.class_key LIKE %s", (pgid, ctx, '%modDocument'))
            # The mirror of the file-group check above: a static resource in the PAGE group. Same
            # fault, same lying ratio, opposite direction — and a check that only looks one way is
            # how such a page once stayed invisible for a day.
            cur.execute("SELECT c.id, c.alias, c.class_key FROM modx_document_groups dg "
                        "JOIN modx_site_content c ON c.id=dg.document "
                        "WHERE dg.document_group=%s AND c.context_key=%s AND c.deleted=0 "
                        "AND c.class_key NOT LIKE %s ORDER BY c.id", (pgid, ctx, '%modDocument'))
            page_strangers = cur.fetchall()
            if page_strangers:
                named = ', '.join(f'{r[0]} ({r[1]}, {r[2].rsplit(chr(92), 1)[-1]})' for r in page_strangers)
                drift.append(f'{ctx}: {len(page_strangers)} member(s) of {PROTECTED} are not pages — '
                             f'{named}. A static resource belongs in {FILE_GROUPS[ctx]}; move it, '
                             f'then re-run setup/04.')
            cur.execute("SELECT n.name FROM modx_access_resource_groups a "
                        "JOIN modx_membergroup_names n ON n.id=a.principal "
                        "WHERE a.target=%s AND a.context_key=%s AND a.policy=%s",
                        (str(pgid), ctx, POL_VIEW))
            granted_view = {r[0] for r in cur.fetchall()}
            viewers = {g for g, (gctxs, _) in GROUPS.items() if ctx in gctxs}

            if members and not granted_view:
                drift.append(f'{ctx}: BLACK HOLE — {members} pages in {PROTECTED} and NO view '
                             f'grant; every page in this context is dark for everyone bar sudo')
                continue

            if not PROTECT_PAGES:
                if members:
                    drift.append(f'{ctx}: PROTECT_PAGES is False but {PROTECTED} still holds '
                                 f'{members} pages — the revert was not applied; re-run setup/04')
                say(f'  {ctx}: pages unprotected by declaration; {members} memberships of {total}')
                continue

            if not support:
                drift.append(f'{ctx}: no top-level `{SUPPORT_ALIAS}` container — the login and '
                             f'unauthorized pages cannot be exempted')
                continue
            # BOTH DIRECTIONS ON THE MACHINE DOORS, because each direction is a real
            # outage in this estate and they are not the same one. A declared door left OUT of the
            # group is correct and must not be counted as an open page; a declared door left IN it
            # answers its own consumer a 302 to the sign-in page for a valid certificate exactly as
            # for none, which is the fault this exemption exists to end. An UNDECLARED page that is
            # open is still drift — being open is never what makes a page exempt.
            door_ids = []
            if MACHINE_DOORS:
                cur.execute("SELECT id, uri FROM modx_site_content WHERE context_key=%s "
                            "AND class_key=%s AND deleted=0 AND uri IN %s ORDER BY id",
                            (ctx, DOC_CLASS, MACHINE_DOORS))
                door_rows = cur.fetchall()
                door_ids = [int(r[0]) for r in door_rows]
                shut = [r for r in door_rows if one(
                    "SELECT COUNT(*) FROM modx_document_groups WHERE document=%s "
                    "AND document_group=%s", (int(r[0]), pgid))]
                if shut:
                    named = ', '.join(f'/{r[1]} (id {r[0]})' for r in shut)
                    drift.append(f'{ctx}: {len(shut)} declared machine door(s) are IN {PROTECTED} — '
                                 f'{named}. Their own element is the door; page protection answers '
                                 f'302 to the sign-in page above it, so the consumer never reaches '
                                 f'the element. Re-run setup/04.')
            open_pages = one("SELECT COUNT(*) FROM modx_site_content c WHERE c.context_key=%s "
                             "AND c.class_key=%s AND c.deleted=0 AND c.id<>%s AND c.parent<>%s "
                             + ("AND c.id NOT IN %s " if door_ids else "")
                             + "AND NOT EXISTS (SELECT 1 FROM modx_document_groups dg "
                             "WHERE dg.document=c.id AND dg.document_group=%s)",
                             (ctx, DOC_CLASS, support, support)
                             + ((tuple(door_ids),) if door_ids else ()) + (pgid,))
            if open_pages:
                drift.append(f'{ctx}: {open_pages} of {total} pages are NOT in {PROTECTED} and are '
                             f'not declared machine doors — they serve to anyone; re-run setup/04')
            # The exemptions must stay OUT, or the door locks from the inside.
            locked_support = one("SELECT COUNT(*) FROM modx_document_groups dg "
                                 "JOIN modx_site_content c ON c.id=dg.document "
                                 "WHERE dg.document_group=%s AND c.deleted=0 "
                                 "AND (c.id=%s OR c.parent=%s)", (pgid, support, support))
            if locked_support:
                drift.append(f'{ctx}: {locked_support} pages under `{SUPPORT_ALIAS}` are IN '
                             f'{PROTECTED} — login/unauthorized are protected, so nobody can '
                             f'sign in to reach them')
            missing = sorted(viewers - granted_view)
            if missing:
                drift.append(f'{ctx}: {PROTECTED} carries no view grant for {", ".join(missing)} '
                             f'— a member of that group cannot open its own pages')
            say(f'  {ctx}: {members}/{total} pages protected, view={sorted(granted_view)}')

        # A context setting that points at a resource which is not there is how this whole class of
        # fault hides: nothing errors, the id is simply carried into 344 user profiles.
        for ctx in CONTEXTS:
            for key in ('protected_page', 'nav_root_id'):
                val = one("SELECT value FROM modx_context_setting WHERE context_key=%s "
                          "AND `key`=%s", (ctx, key))
                if val and str(val).isdigit() and not one(
                        "SELECT id FROM modx_site_content WHERE id=%s AND context_key=%s "
                        "AND deleted=0 AND published=1", (int(val), ctx)):
                    drift.append(f'{ctx}: {key}={val} points at a resource that is deleted or '
                                 f'absent')

    # ── the save-time half: the declaration, and the plugin that reads it ────────────
    # Everything above asks "does the database match the model". This asks the question one level
    # up: is the thing that keeps it matching between deploys still installed, still enabled, still
    # bound, and still reading a model that says what access_model.py says.
    #
    # It is checked HERE rather than in a test because this file is what infra's 6-hourly monitor
    # runs. A rule that lives only in a test is a rule nobody runs on production.
    pgid = one("SELECT id FROM modx_documentgroup_names WHERE name=%s", (PROTECTED,))
    fgids = {c: one("SELECT id FROM modx_documentgroup_names WHERE name=%s", (FILE_GROUPS[c],))
             for c in CONTEXTS}

    if pgid and all(fgids.values()):
        want = declaration(pgid, fgids)
        raw = one("SELECT value FROM modx_system_settings WHERE `key`=%s", (SETTING_KEY,))
        if not raw:
            drift.append(f'system setting `{SETTING_KEY}` is absent — protectOnSave has no model to '
                         f'read, so every manager save leaves its page as 04 last left it')
        else:
            try:
                got = json.loads(raw)
            except ValueError as exc:
                got = None
                drift.append(f'system setting `{SETTING_KEY}` is not valid JSON ({exc}) — the '
                             f'plugin will refuse to act on every save')
            if got is not None and got != want:
                differing = sorted(set(want) | set(got))
                for k in differing:
                    if want.get(k) != got.get(k):
                        drift.append(f'`{SETTING_KEY}`.{k} says {got.get(k)!r}, access_model.py '
                                     f'says {want.get(k)!r} — re-run setup/04-access-control.py')
    else:
        drift.append(f'cannot resolve the resource group ids, so `{SETTING_KEY}` was not checked '
                     f'(page={pgid}, files={fgids})')

    prow = one("SELECT id FROM modx_site_plugins WHERE name=%s AND disabled=0", (PLUGIN_NAME,))
    if not prow:
        drift.append(f'plugin `{PLUGIN_NAME}` is absent or disabled — a page created in the manager '
                     f'is public until the next deploy')
    else:
        cur.execute("SELECT event FROM modx_site_plugin_events WHERE pluginid=%s", (prow,))
        bound = {r[0] for r in cur.fetchall()}
        if bound != set(PLUGIN_EVENTS):
            drift.append(f'plugin `{PLUGIN_NAME}` is bound to {sorted(bound)}, the model declares '
                         f'{sorted(PLUGIN_EVENTS)}')
        # The body in the database must be the body in this repo. A plugin edited in the manager is
        # a second master, and the whole reason the estate deploys elements from git.
        path = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'common', 'plugins',
                            PLUGIN_NAME)
        try:
            with open(path, encoding='utf-8') as fh:
                on_disk = fh.read()
        except OSError as exc:
            drift.append(f'cannot read {path} to compare the deployed plugin against it: {exc}')
        else:
            in_db = one("SELECT plugincode FROM modx_site_plugins WHERE id=%s", (prow,))
            # COMPARE THE WAY BOTH WRITERS STORE, or this fires on every correct deploy.
            # apply-modx-elements.sh hex-encodes the file body after `s/\n\z//` and
            # 04-access-control.py calls canon.db_body() — the same rule, one owner. Restating it
            # here as a slice is what made three copies of it, and the copy 04-access-control.py
            # did NOT have is why this check reported one byte of DRIFT on correct work, on a
            # ground it had just converged. Measured on a bench 2026-08-18, the first run this
            # check ever got against a database: `protectOnSave ... (10182 bytes vs 10181)`.
            expected = db_body(on_disk)
            if in_db != expected:
                drift.append(f'plugin `{PLUGIN_NAME}` in the database differs from '
                             f'common/plugins/{PLUGIN_NAME} ({len(in_db or "")} bytes vs '
                             f'{len(expected)} after the deploy verb\'s trailing-newline strip) '
                             f'— re-run setup/04-access-control.py')
        say(f'  {PLUGIN_NAME}: enabled, bound to {sorted(bound)}')

    cur.close(); conn.close()

    if drift:
        for d in drift:
            print(f'DRIFT: {d}')
        return 1
    say(f'file and page protection match the declaration '
        f'(PROTECT_FILES={PROTECT_FILES}, PROTECT_PAGES={PROTECT_PAGES}).')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except SystemExit:
        raise
    except Exception as exc:
        print(f'CHECK-BROKEN: {type(exc).__name__}: {exc}', file=sys.stderr)
        sys.exit(2)
