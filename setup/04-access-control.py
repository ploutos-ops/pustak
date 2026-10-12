#!/usr/bin/env python3
"""
04 — Access control (multi-context member login).

Model:
  * User groups are declared in GROUPS below, each with the contexts it may enter:
      - `allContextMember`  — every content context (alice, bob).
      - `familyMember` / `corporateMember` / `worldviewMember` — one context each, so a
        user can be given one site and no other. Membership is manager work; this script
        only guarantees the groups exist and are granted correctly.
  * One resource group `allContextProtected` — the resources only members may see. Every
    PAGE in the context goes in it, except the `support` subtree (see PAGE protection
    below). The group is shared across contexts but every grant carries context_key, so a
    per-context grant only unlocks its own pages.
  * Three resource groups `<ctx>ProtectedFiles` — every Static Resource in that context, so
    the estate's FILES need a signed-in member of their own context while its PAGES stay
    public. See "FILE protection" below.
  * Per context, two access layers must BOTH be wired (this is the multi-context part
    single-context installs get for free):
      - context access: anonymous (principal 0) gets Load Only so public pages render and
        a blocked user reaches unauthorized_page; member groups get Load/List/View.
      - resource-group access: member groups get Load/List/View on allContextProtected for
        the contexts they cover; anonymous gets Load Only so a protected deep-link resolves
        and MODx sends the login page (unauthorized) instead of a 404 (view/list stay denied).
  * a user's home page (homePages) is NOT written here — it is set in the MODx manager.

FILE protection (2026-07-24). The owner: "feel free to protect everything ... i can
always unlock the doors and it is a simpler option than finding doors to be locked." So the rule
is blunt, statable and carries no per-file judgement:

    a PAGE you can read; a FILE you must be signed in to its own context for.

Every `modStaticResource` in a context joins `<ctx>ProtectedFiles` (family 472 / corporate 233 /
worldview 493 at the time of writing). Files are the estate itself — EINs, W-9s, beneficiary
designations, bank correspondence — and until this landed they served to anonymous `curl`:
`modx_document_groups` held nothing but the three context homes.

PAGE protection (2026-07-27). The half of that rule which said pages stay public is gone, and it
was never true anyway: the three "context homes" in `allContextProtected` were 13/27/41, the
DELETED `home` twins, so the group protected nothing and every page answered anonymous with 200.
Now every `modDocument` joins `allContextProtected` except its context's `support` subtree, which
must stay anonymous or nobody can sign in. Same switch shape as the files — `PROTECT_PAGES` in
access_model.py, one flag, one re-run to reverse.

THE SECOND EXEMPTION IS THE MACHINE DOORS, and it is a different rule from `support`
for a different reason: a support page must answer anonymously or nobody can SIGN IN; a machine
door must answer anonymously or its own CODE never runs, and that code is the door. The set is
declared in `service.json` (`identity.routes`) and read by `access_model.MACHINE_DOORS`; every row
it exempts is printed by id and uri, because an exemption nobody meant must be readable rather than
counted.

The grants are three-tier, and the middle tier is the one that is easy to miss. `modAccessResourceGroup::loadAttributes`
takes a DIFFERENT branch for a signed-in user: it JOINs modx_member_groups and so reads ONLY the
grants of groups the user actually belongs to — a `principal = 0` row is loaded for ANONYMOUS
alone. A resource in a group the caller has no row for fails checkPolicy('load'), and MODx then
404s instead of asking them to log in. So per context:
    anonymous (principal 0)      Load Only  -> resolves, fails 'view', gets the login page (401)
    groups covering the context  Load/View  -> a member of that site opens the file
    groups NOT covering it       Load Only  -> a signed-in outsider gets 401, not a misleading 404
Membership with no grant does not protect a resource, it black-holes it for EVERYONE bar sudo
(`allContextProtected` is exactly that shape today), and at 1,198 files that failure would present
as the whole estate's links breaking. Grant and membership are therefore written in the same
transaction, always.

Reversal is ONE step, which is the condition the owner consented on: set PROTECT_FILES = False in
access_model.py and re-run. This script is idempotent by clear-and-rebuild, so the flip drops every
membership and grant as a unit. The (now empty) groups are left behind deliberately — flipping back
re-fills them, and an empty group grants nothing. `verify-file-protection.py` reads the same switch
as INTENT, so a deliberate unprotect does not leave the monitor alerting forever.

RENAME (2026-07-20): `Members` -> `allContextMember`, `Protected` -> `allContextProtected`.
Both are looked up by name, so an existing install must be renamed in place, not left to be
recreated alongside the old ones — this script does that rename itself (see adopt()).

Idempotent: clears the rows it manages, then rebuilds. Derives every id (users by name,
groups by name, protected home from the protected_page context setting) — no hardcodes.
"""
import os
import sys
import pymysql, json

# The DATABASE form of an element body is owned by canon.py at the repo root, not restated here:
# this script and apply-modx-elements.sh both write the protectOnSave body, and when they disagreed
# by one newline the protection verifier went red on every correct deploy. See canon.db_body().
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
from canon import db_body   # noqa: E402

# The model itself is declared ONCE, in access_model.py, because verify-file-protection.py asserts
# the same facts and a watcher with its own copy of them goes green on a model it no longer
# describes. This script is the only thing that WRITES them.
from access_model import (CONTEXTS, GROUPS, PROTECTED, OLD_NAMES,
                          PROTECT_FILES, FILE_CLASS, FILE_GROUPS, UG, POL_LOAD, POL_VIEW,
                          PROTECT_PAGES, DOC_CLASS, SUPPORT_ALIAS, MACHINE_DOORS,
                          PLUGIN_NAME, PLUGIN_EVENTS, SETTING_KEY, declaration)
# The full context declaration, for the one thing the access POLICY does not own: which page a
# member lands on. access_model re-exports the protected KEYS; the home is per-context estate.
from estate import CONTEXTS as CONTEXT_SPEC
from domains import db_config

# ---------------------------------------------------------------------------------------------
# GUARD: NEVER CONVERGE A POPULATED ESTATE DOWN TO NOTHING.  (Found 2026-08-04 by doing it.)
#
# Phase 1 makes the estate a FILE this software loads: no MODX_ESTATE means the generic empty
# estate, which is correct and is the from-nothing install path. The foot-gun is that this script is
# idempotent BY CLEAR-AND-REBUILD, so running it with no estate file against a database that
# already holds one does not fail — it rebuilds the model as EMPTY and reports success.
#
# I did exactly that on the bench while merging: `access.model` went to `contexts=[]`, every
# context's page protection was dropped, and `verify-file-protection.py` then exited 0 — GREEN,
# because the declaration it checks against had been emptied in the same run. That is the failure
# the CMDB inspection spent four rounds on under a different name: working the view to empty reads
# as complete. On a bench it cost a re-run. `converge_modx_access` runs this script on PRODUCTION.
#
# So: if the database already declares contexts and this run declares none, REFUSE. Emptying an
# estate deliberately is still possible — it just has to be said out loud, once, with a flag.
def _refuse_if_this_would_empty_a_populated_estate(conn, declared_contexts):
    if declared_contexts or os.environ.get('MODX_ALLOW_EMPTY_ESTATE') == '1':
        return
    with conn.cursor() as cur:
        cur.execute("SELECT `value` FROM modx_system_settings WHERE `key`='access.model'")
        row = cur.fetchone()
    live = []
    if row and row[0]:
        try:
            live = json.loads(row[0]).get('contexts') or []
        except (ValueError, AttributeError):
            live = []
    if live:
        raise SystemExit(
            "04-access-control: REFUSING.\n"
            f"  This database already declares protected contexts: {sorted(live)}\n"
            "  This run declares NONE, because MODX_ESTATE is not set — so it would rebuild the\n"
            "  access model as empty, drop every page protection, and report success. The verifier\n"
            "  would then go green against the emptied declaration.\n"
            "  Set MODX_ESTATE to this estate's file, or MODX_ALLOW_EMPTY_ESTATE=1 if emptying it\n"
            "  is genuinely what you mean."
        )


DB = db_config()

conn = pymysql.connect(**DB); cur = conn.cursor()

# BEFORE anything is cleared or rebuilt. The guard reads the LIVE declaration and refuses if this
# run would empty it — so it has to fire ahead of the first DELETE, not after.
_refuse_if_this_would_empty_a_populated_estate(conn, CONTEXTS)


def one(q, a=()):
    cur.execute(q, a); r = cur.fetchone(); return r[0] if r else None


def delete_in(sql, ids, what):
    """
    Run a `... IN %s` DELETE, or say out loud that there was nothing to run it against.

    An empty estate is a supported state, not an error: with no MODX_ESTATE the loader yields no
    contexts and no groups (the install-from-nothing path), so every collection below is legally
    empty. pymysql renders an empty tuple as `IN ()`, which MariaDB rejects with a syntax error — so
    the from-nothing install died here, on a path whose docstring claimed it was proven.

    It PRINTS rather than passing silently. An access-control script that quietly does nothing is
    the shape of the failure where the written model said pages were protected and every page answered 200.
    "Nothing to protect" and "protection did not run" must not look the same in the output.
    """
    ids = tuple(ids)
    if not ids:
        print(f'  skip: no {what} declared — nothing to clear')
        return 0
    return cur.execute(sql, (ids,))


def adopt(table, new, old, state_table, state_fk, state_key, resource_group):
    """Return the id of group `new` in `table`, converging every legacy shape on ONE group.

    The old row already carries the memberships (user group) or document assignments (resource
    group); leaving a parallel group behind would give the install two groups, one silently
    unenforced. Four states, all resolved to a single `new` carrying the old one's state:
      * new only     -> return it.
      * old only     -> rename old to new in place (keeps its rows), return it.
      * BOTH exist   -> FOLD: move the stray old group's state rows onto new (dropping any that
                        would duplicate a new-id twin), delete the stray group and its now-orphan
                        access grants, return new. This is the BUG 1 residual: 01 no longer creates
                        the new name (so a clean run never reaches this), but a half-migrated DB
                        that already carries BOTH names must not keep the old, unenforced group.
      * neither      -> return None; the caller creates.

    `state_table`/`state_fk`/`state_key`: the table whose rows reference this group's id and its
    natural key (member_groups.user_group keyed by member; document_groups.document_group keyed by
    document). `resource_group` picks the access role the stray plays — user-group ids and
    resource-group ids are SEPARATE id spaces that can collide numerically, so the stray's grants
    are matched as `target` (resource group) vs `principal` (user group), never by bare id.
    """
    new_id = one(f"SELECT id FROM {table} WHERE name=%s", (new,))
    old_id = one(f"SELECT id FROM {table} WHERE name=%s", (old,))
    if new_id and old_id and new_id != old_id:
        # drop stray state rows that already have a new-id twin, then repoint the rest onto new
        cur.execute(f"DELETE s FROM {state_table} s JOIN {state_table} t "
                    f"ON s.{state_key}=t.{state_key} AND t.{state_fk}=%s WHERE s.{state_fk}=%s",
                    (new_id, old_id))
        cur.execute(f"UPDATE {state_table} SET {state_fk}=%s WHERE {state_fk}=%s", (new_id, old_id))
        # the stray's own access grants are redundant (new's are rebuilt below) — drop them
        if resource_group:
            cur.execute("DELETE FROM modx_access_resource_groups WHERE target=%s", (str(old_id),))
        else:
            cur.execute("DELETE FROM modx_access_context WHERE principal_class=%s AND principal=%s", (UG, old_id))
            cur.execute("DELETE FROM modx_access_resource_groups WHERE principal_class=%s AND principal=%s", (UG, old_id))
        cur.execute(f"DELETE FROM {table} WHERE id=%s", (old_id,))
        print(f'  folded stray {table}.{old} (id {old_id}) -> {new} (id {new_id})')
        return new_id
    if new_id:
        return new_id
    if old_id:
        cur.execute(f"UPDATE {table} SET name=%s WHERE id=%s", (new, old_id))
        print(f'  renamed {table}.{old} -> {new} (id {old_id})')
        return old_id
    return None


# ── buckets ───────────────────────────────────────────────────────────────────
role_id = one("SELECT id FROM modx_user_group_roles WHERE name='Member'")
if not role_id:
    cur.execute("INSERT INTO modx_user_group_roles (name,description,authority) "
                "VALUES ('Member','Regular member',9999)"); role_id = cur.lastrowid

group_ids = {}
for gname, (gctxs, _) in GROUPS.items():
    gid = adopt('modx_membergroup_names', gname, OLD_NAMES.get(gname, gname),
                'modx_member_groups', 'user_group', 'member', resource_group=False)
    if not gid:
        desc = 'Site members — all contexts' if len(gctxs) > 1 else f'Site members — {gctxs[0]} only'
        cur.execute("INSERT INTO modx_membergroup_names (name,description,parent,rank,dashboard) "
                    "VALUES (%s,%s,0,1,0)", (gname, desc)); gid = cur.lastrowid
    group_ids[gname] = gid

protected_gid = adopt('modx_documentgroup_names', PROTECTED, OLD_NAMES[PROTECTED],
                      'modx_document_groups', 'document_group', 'document', resource_group=True)
if not protected_gid:
    # reuse the stray 'Members' resource group if present, else create
    stray = one("SELECT id FROM modx_documentgroup_names WHERE name='Members'")
    if stray:
        cur.execute("UPDATE modx_documentgroup_names SET name=%s WHERE id=%s", (PROTECTED, stray)); protected_gid = stray
    else:
        cur.execute("INSERT INTO modx_documentgroup_names (name) VALUES (%s)", (PROTECTED,)); protected_gid = cur.lastrowid
print(f'  user groups={group_ids}  {PROTECTED} resource group={protected_gid}  role={role_id}')

# per-context file resource groups (created on first run; no legacy name to adopt)
file_gids = {}
for ctx, fgname in FILE_GROUPS.items():
    fgid = one("SELECT id FROM modx_documentgroup_names WHERE name=%s", (fgname,))
    if not fgid:
        cur.execute("INSERT INTO modx_documentgroup_names (name) VALUES (%s)", (fgname,)); fgid = cur.lastrowid
    file_gids[ctx] = fgid
print(f'  file resource groups={file_gids}  PROTECT_FILES={PROTECT_FILES}')

# ── memberships (derive user ids by name) ──────────────────────────────────────
# A user this script MANAGES (named anywhere in GROUPS) must be a member of EXACTLY the groups
# GROUPS declares for them, and no other MANAGED group. Insert the declared memberships, then
# reconcile: remove any membership a managed user has in a managed group that GROUPS does not
# declare. This undoes inherited/orphan rows — e.g. a stale modx_member_groups row that a newly
# minted user id resurrects (BUG 2) — without touching users the script does not manage or
# manager-added members of these groups (their user ids are never in `managed`).
managed = {}                                  # username -> {declared group names}
for gname, (_, unames) in GROUPS.items():
    for uname in unames:
        managed.setdefault(uname, set()).add(gname)

# Every user GROUPS declares must exist before any membership is written. This used to print
# `WARN user alice not found — skipped` and carry on, which is how the admin account could end up
# in no group at all on an estate where every page requires membership — the door locked with
# nobody holding a key, reported as a warning in a wall of output. The usual cause is that MODX's
# own installer created the manager account under a different name, so the message names the one
# file that decides it. Nothing has been committed at this point, so exiting here changes nothing.
_missing = sorted(u for u in managed if not one("SELECT id FROM modx_users WHERE username=%s", (u,)))
if _missing:
    raise SystemExit(
        f"REFUSING: the estate file (MODX_ESTATE) declares user(s) {_missing} that do not exist in\n"
        f"  this database.\n"
        f"  If this is a fresh install, run setup/01-contexts-users.py first.\n"
        f"  If it names your MODX admin account, set `admin_username` in the estate file (or\n"
        f"  MODX_ADMIN_USER with no estate file) to the account MODX's installer created\n"
        f"  (setup/config.xml `cmsadmin`).\n"
        f"  Refusing rather than skipping: a skipped membership leaves that user with no access at\n"
        f"  all on an estate where every page requires it."
    )

for uname, want_groups in managed.items():
    uid = one("SELECT id FROM modx_users WHERE username=%s", (uname,))
    for gname in want_groups:
        if not one("SELECT id FROM modx_member_groups WHERE user_group=%s AND member=%s", (group_ids[gname], uid)):
            cur.execute("INSERT INTO modx_member_groups (user_group,member,role,rank) VALUES (%s,%s,%s,0)",
                        (group_ids[gname], uid, role_id))
    # strip this managed user from any managed group we did NOT declare for them
    stray_gids = [group_ids[g] for g in GROUPS if g not in want_groups]
    if stray_gids:
        removed = cur.execute("DELETE FROM modx_member_groups WHERE member=%s AND user_group IN %s",
                              (uid, tuple(stray_gids)))
        if removed:
            print(f'  reconcile: {uname} (user {uid}) removed from {removed} undeclared managed group(s)')
    print(f'  member: {uname} (user {uid}) -> {sorted(want_groups)}')

# ── clear the rows we manage, then rebuild (idempotent) ────────────────────────
delete_in("DELETE FROM modx_access_context WHERE target IN %s", CONTEXTS, 'contexts')
cur.execute("DELETE FROM modx_access_resource_groups WHERE target=%s", (str(protected_gid),))
cur.execute("DELETE FROM modx_document_groups WHERE document_group=%s", (protected_gid,))
# the file groups too — unconditionally, so PROTECT_FILES=False + re-run IS the one-step revert
delete_in("DELETE FROM modx_access_resource_groups WHERE target IN %s",
          (str(g) for g in file_gids.values()), 'file groups')
delete_in("DELETE FROM modx_document_groups WHERE document_group IN %s",
          file_gids.values(), 'file groups')

def live_home(ctx):
    """This context's landing page: the DECLARED home, else protected_page, else nav_root_id.

    The declaration wins and is written back. The home used to live only in the
    protected_page setting, put there once by setup/03 — a fact with no owner in the estate file,
    which is how `claude` came to be protected on production with no home at all: 04 printed
    "neither protected_page nor nav_root_id resolves", carried on, and the only symptom was a
    member signing in and being routed nowhere. Invisible from outside, because the outside is
    refused either way. Now the alias is declared, resolved to an id here, and CONVERGED into
    protected_page/nav_root_id so a re-run repairs a context whose settings drifted.

    protected_page pointed at the deleted 'home' twin in all three contexts (13/27/41 against live
    12/26/40), and homePages is built from it — so every user's profile named a resource that does
    not exist, makeUrl() returned '', and MODx's sendRedirect('') logged a line and did nothing.
    A dangling id falls through here for the same reason it now falls through in the userHome
    snippet, and says so rather than being silently carried into 344 user profiles.
    """
    alias = (CONTEXT_SPEC.get(ctx) or {}).get('home')
    if alias:
        rid = one("SELECT id FROM modx_site_content WHERE context_key=%s AND alias=%s "
                  "AND deleted=0 AND published=1", (ctx, alias))
        if not rid:
            raise SystemExit(
                f"04: {ctx} declares home='{alias}' and no published resource of that alias exists "
                f"in the context.\n"
                f"    Refusing rather than falling back to whatever the settings happen to say — a "
                f"declared home that silently does not resolve is the state this declaration exists "
                f"to end.")
        for key in ('protected_page', 'nav_root_id'):
            cur.execute("SELECT 1 FROM modx_context_setting WHERE context_key=%s AND `key`=%s", (ctx, key))
            if cur.fetchone():
                cur.execute("UPDATE modx_context_setting SET value=%s WHERE context_key=%s AND `key`=%s",
                            (str(rid), ctx, key))
            else:
                cur.execute("INSERT INTO modx_context_setting (context_key,`key`,value,xtype) "
                            "VALUES (%s,%s,%s,'textfield')", (ctx, key, str(rid)))
        print(f'  {ctx}: home={alias} -> {rid} (protected_page + nav_root_id converged)')
        return rid
    declared = one("SELECT value FROM modx_context_setting WHERE context_key=%s "
                   "AND `key`='protected_page'", (ctx,))
    for key in ('protected_page', 'nav_root_id'):
        val = one("SELECT value FROM modx_context_setting WHERE context_key=%s AND `key`=%s", (ctx, key))
        rid = int(val) if val and str(val).isdigit() else 0
        if rid and one("SELECT id FROM modx_site_content WHERE id=%s AND context_key=%s "
                       "AND deleted=0 AND published=1", (rid, ctx)):
            if key != 'protected_page':
                print(f'  WARN {ctx}: protected_page={declared or "unset"} does not resolve — '
                      f'falling back to {key}={rid}')
            return rid
    print(f'  WARN {ctx}: neither protected_page nor nav_root_id resolves — no home for this context')
    return None


def converge_logo(ctx):
    """The sign-in card's logo for this context: the estate's declared `logo` URL, written to the
    `site_logo` context setting (the owner, 2026-10-07: "sign-in to show company logo",
    "each company's own logo", "if empty, then bird"). Undeclared means the card's default, the
    bird. The bar and the tab icon read no `site_logo`: they are Saraswati's veena everywhere.

    Here, beside the declared home, because this is the one pass that converges the estate's
    per-context declarations on every ground and runs on every access converge. A declaration
    that is REMOVED is reported rather than deleted: settings_guard refuses a pass that empties a
    setting that had a value, and that refusal is the right default for an unexplained blanking.
    """
    logo = ((CONTEXT_SPEC.get(ctx) or {}).get('logo') or '').strip()
    have = one("SELECT value FROM modx_context_setting WHERE context_key=%s AND `key`='site_logo'", (ctx,))
    if not logo:
        if have:
            print(f'  WARN {ctx}: site_logo={have} is set but the estate declares no logo — '
                  f'delete the row by hand if the default bird is wanted')
        return
    if not logo.startswith('https://'):
        raise SystemExit(f"04: {ctx} declares logo='{logo}' — refusing: a sign-in page loads it, "
                         f"so it must be an absolute https URL")
    if have is None:
        cur.execute("INSERT INTO modx_context_setting (context_key,`key`,value,xtype) "
                    "VALUES (%s,'site_logo',%s,'textfield')", (ctx, logo))
    elif have != logo:
        cur.execute("UPDATE modx_context_setting SET value=%s WHERE context_key=%s AND `key`='site_logo'",
                    (logo, ctx))
    print(f'  {ctx}: site_logo -> {logo}')


bound_doors = set()          # every declared machine door this database actually binds a page to
for ctx in CONTEXTS:
    home = live_home(ctx)
    converge_logo(ctx)

    # context access: anonymous (0) renders public pages and can reach unauthorized_page
    cur.execute("INSERT INTO modx_access_context (target,principal_class,principal,authority,policy) "
                "VALUES (%s,%s,0,9999,%s)", (ctx, UG, POL_LOAD))
    # resource-group access: anonymous (0) gets Load Only on the protected group. Without a LOAD
    # grant MODx's cache path (modRequest::getResource) fails checkPolicy('load') and returns null,
    # so a protected deep-link 404s. With Load Only it loads (found) but fails checkPolicy('view')
    # -> sendUnauthorizedPage() -> login-with-returnUrl. 'list' stays denied, so nav is unaffected.
    cur.execute("INSERT INTO modx_access_resource_groups "
                "(target,principal_class,principal,authority,policy,context_key) VALUES (%s,%s,0,9999,%s,%s)",
                (str(protected_gid), UG, POL_LOAD, ctx))

    # Every declared group gets a row on the protected group for THIS context — Load/List/View if
    # it covers the context, Load Only if it does not. The second half is not politeness, it is the
    # protection itself: `loadAttributes` takes a different branch for a signed-in user and reads
    # ONLY the grants of groups that user belongs to, so a group with NO row inherits nothing to
    # fail against and checkPolicy('view') comes back TRUE. Measured, on this data: with the
    # covering-groups-only loop, bob (worldviewMember) could view family/trust-nevada. It never
    # showed while only three home pages were in the group. The file grants below have always got
    # this right; the page grants had not.
    for gname, (gctxs, _) in GROUPS.items():
        if ctx in gctxs:
            cur.execute("INSERT INTO modx_access_context (target,principal_class,principal,authority,policy) "
                        "VALUES (%s,%s,%s,9999,%s)", (ctx, UG, group_ids[gname], POL_VIEW))
        cur.execute("INSERT INTO modx_access_resource_groups "
                    "(target,principal_class,principal,authority,policy,context_key) VALUES (%s,%s,%s,9999,%s,%s)",
                    (str(protected_gid), UG, group_ids[gname],
                     POL_VIEW if ctx in gctxs else POL_LOAD, ctx))

    # ── this context's PAGES ───────────────────────────────────────────────────
    # The grants above are already written, for this same group, in this same transaction — so
    # membership never lands ahead of the grant that makes it protection rather than a black hole.
    covering = [g for g, (gctxs, _) in GROUPS.items() if ctx in gctxs]
    if PROTECT_PAGES:
        support = one("SELECT id FROM modx_site_content WHERE context_key=%s AND alias=%s "
                      "AND parent=0 AND deleted=0", (ctx, SUPPORT_ALIAS))
        if not support:
            raise SystemExit(f'{ctx}: no top-level `{SUPPORT_ALIAS}` container — refusing to '
                             f'protect pages, because the login and unauthorized pages would be '
                             f'protected too and nobody could sign in.')
        # THE SECOND EXEMPTION, and it is named row by row rather than counted. A
        # machine door is a page whose own CODE is the door; protecting it answers the estate's
        # own monitor a 302 to the sign-in page and the element never runs. The set comes from
        # `service.json` — see access_model.MACHINE_DOORS for why that is its only owner and what
        # bounds the widening. Matched on `uri`, because that is the column MODx routes on.
        doors = ()
        if MACHINE_DOORS:
            cur.execute("SELECT id, uri FROM modx_site_content WHERE context_key=%s "
                        "AND class_key=%s AND deleted=0 AND uri IN %s ORDER BY id",
                        (ctx, DOC_CLASS, MACHINE_DOORS))
            doors = cur.fetchall()
        door_ids = [int(r[0]) for r in doors]
        n = cur.execute("INSERT INTO modx_document_groups (document,document_group) "
                        "SELECT id,%s FROM modx_site_content "
                        " WHERE context_key=%s AND class_key=%s AND deleted=0 "
                        "   AND id <> %s AND (parent <> %s OR parent IS NULL) "
                        + ("   AND id NOT IN %s " if door_ids else ""),
                        (protected_gid, ctx, DOC_CLASS, support, support)
                        + ((tuple(door_ids),) if door_ids else ()))
        exempt = one("SELECT COUNT(*) FROM modx_site_content WHERE context_key=%s AND deleted=0 "
                     "AND class_key=%s AND (id=%s OR parent=%s)",
                     (ctx, DOC_CLASS, support, support))
        print(f'  {ctx}: {n} pages -> {PROTECTED}; {exempt} left anonymous under '
              f'`{SUPPORT_ALIAS}` ({support}); view={covering}')
        # NAMED, never counted. An exemption nobody meant has to be readable in the deploy output —
        # `1 machine door` reads as fine whichever page it is.
        for rid, uri in doors:
            bound_doors.add(uri)
            print(f'  {ctx}: machine door /{uri} (id {rid}) left anonymous — its element is the door')
    elif home:
        # the old rule, kept behind the switch: only the home page is protected
        cur.execute("INSERT INTO modx_document_groups (document,document_group) VALUES (%s,%s)",
                    (home, protected_gid))
        print(f'  {ctx}: anon load-only; groups {covering}; protected home={home}')

    # ── this context's FILES ──────────────────────────────────────
    # Grants FIRST, then membership, inside the one transaction: a member row whose group
    # carries no grant is not protection, it is a black hole (see the module docstring).
    if PROTECT_FILES:
        fgid = file_gids[ctx]
        # anonymous: Load Only -> the deep link resolves, fails 'view', and MODx sends the
        # login page with a returnUrl rather than a 404.
        cur.execute("INSERT INTO modx_access_resource_groups "
                    "(target,principal_class,principal,authority,policy,context_key) VALUES (%s,%s,0,9999,%s,%s)",
                    (str(fgid), UG, POL_LOAD, ctx))
        for gname, (gctxs, _) in GROUPS.items():
            # a signed-in caller reads ONLY its own groups' grants — principal 0 is invisible to
            # them — so an outsider needs an explicit Load Only row or their deep link 404s.
            pol = POL_VIEW if ctx in gctxs else POL_LOAD
            cur.execute("INSERT INTO modx_access_resource_groups "
                        "(target,principal_class,principal,authority,policy,context_key) VALUES (%s,%s,%s,9999,%s,%s)",
                        (str(fgid), UG, group_ids[gname], pol, ctx))
        n = cur.execute("INSERT INTO modx_document_groups (document,document_group) "
                        "SELECT id,%s FROM modx_site_content "
                        "WHERE context_key=%s AND class_key=%s AND deleted=0", (fgid, ctx, FILE_CLASS))
        viewers = [g for g, (gctxs, _) in GROUPS.items() if ctx in gctxs]
        print(f'  {ctx}: {n} files -> {FILE_GROUPS[ctx]} (group {fgid}); view={viewers}, '
              f'everyone else load-only')

# ── a declared door that this database binds to nothing ────────────────────────
# NOT a failure and NOT silence. `service.json` declares the paths this application offers a
# machine; whether a resource is bound to one is a fact about THIS database, and a bench that was
# never given the rows legitimately binds none. But "declared and bound nowhere" is exactly the
# state once found — `/actuator/info` answering 404 on three production names and all four bench
# names — and it is invisible unless something says it out loud on the ground that is missing it.
if PROTECT_PAGES:
    for uri in MACHINE_DOORS:
        if uri not in bound_doors:
            print(f'  WARN: /{uri} is declared a machine door in service.json and NO resource in '
                  f'{sorted(CONTEXTS)} carries that uri — the door is declared and bound to '
                  f'nothing, so its consumer gets a 404 and not the element')

# ── A user's home page is NOT written here ─────────────────────────────────────────────
# The owner, 2026-10-05: "i wish to get rid of overrides outside of front-end" / "let the front-end/
# manager do all the work". homePages (modx_user_attributes.extended) is set in the MODx manager;
# this script used to OVERWRITE the whole extended field on every converge, so a home set there
# lasted only until the next deploy. userHome reads homePages[context] when set, else nav_root_id.

# ── the save-time half: the declaration, then the plugin that reads it ──────────────────
# Order matters and is the same order the rest of this script uses: the thing that makes an action
# correct is written BEFORE the thing that acts. A plugin bound to its events while `access.model`
# is absent would log an error on every save the owner made; the setting first means the plugin's first
# firing already has a model to read.
model_json = json.dumps(declaration(protected_gid, file_gids), sort_keys=True)
cur.execute("SELECT value FROM modx_system_settings WHERE `key`=%s", (SETTING_KEY,))
if cur.fetchone():
    cur.execute("UPDATE modx_system_settings SET value=%s WHERE `key`=%s", (model_json, SETTING_KEY))
else:
    cur.execute("INSERT INTO modx_system_settings (`key`,value,xtype,namespace,area) "
                "VALUES (%s,%s,'textarea','access','access')", (SETTING_KEY, model_json))
print(f'  {SETTING_KEY}: {len(model_json)} bytes '
      f'(pages={PROTECT_PAGES}, files={PROTECT_FILES}, contexts={sorted(CONTEXTS)})')

# The plugin body is a FILE in this repo, never a copy kept in the database: the same rule the
# element deploy follows, so a change to it is a diff someone can read.
plugin_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'common', 'plugins',
                           PLUGIN_NAME)
with open(plugin_path, encoding='utf-8') as fh:
    # STORED IN THE DATABASE FORM, which is what apply-modx-elements.sh stores and what
    # verify-file-protection.py expects. Writing the file bytes verbatim here made this script and
    # the deploy verb two writers of one row in two byte-forms — one newline apart, red
    # verifier, and a remedy line that told the reader to re-run this very script.
    plugin_code = db_body(fh.read())

pid = one("SELECT id FROM modx_site_plugins WHERE name=%s", (PLUGIN_NAME,))
if pid:
    cur.execute("UPDATE modx_site_plugins SET plugincode=%s, disabled=0 WHERE id=%s",
                (plugin_code, pid))
else:
    cur.execute("INSERT INTO modx_site_plugins (name, description, plugincode, disabled, category) "
                "VALUES (%s,%s,%s,0,0)",
                (PLUGIN_NAME,
                 'Protects a resource at SAVE time, from the access.model setting.',
                 plugin_code))
    pid = cur.lastrowid

# Bindings are converged, not merely added: an event this plugin no longer claims is removed, so a
# binding cannot outlive the reason it was made. Anything not ours on this plugin id is not a case
# that exists — the id is created here or nowhere.
cur.execute("DELETE FROM modx_site_plugin_events WHERE pluginid=%s AND event NOT IN %s",
            (pid, tuple(PLUGIN_EVENTS)))
for ev in PLUGIN_EVENTS:
    cur.execute("SELECT 1 FROM modx_site_plugin_events WHERE pluginid=%s AND event=%s", (pid, ev))
    if not cur.fetchone():
        cur.execute("INSERT INTO modx_site_plugin_events (pluginid, event, priority, propertyset) "
                    "VALUES (%s,%s,0,0)", (pid, ev))
print(f'  {PLUGIN_NAME} plugin id={pid}, bound to {", ".join(PLUGIN_EVENTS)}')

conn.commit(); cur.close(); conn.close()

# ── the protected-file UPLOAD wiring rides the same converge (2026-09-06) ──────────────────────
# setup/20-protected-files.py is application state exactly like the plugin body above: the
# Protected media source, its directory, the storeProtectedFile plugin and storage.protected_source
# (docs/modx-storage-gate.md, 'The upload half'). Both converge jobs run THIS script from the
# repo's master on the ground — production's through deploy_modx_documents/converge_modx_access,
# staging's through converge_modx_staging_access — and none of them was ever going to grow a
# second setup invocation in infra's config for every setup script this repo adds (the owner,
# 2026-09-06: deploying my own application must not demand others' work). So 04 is the one
# converge entrypoint and 20 is part of what converging this application means. Idempotent, same
# env; its failure fails the converge loudly rather than shipping half an application.
import subprocess
r = subprocess.run([sys.executable, os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                                 '20-protected-files.py')],
                   capture_output=True, text=True)
for line in (r.stdout + r.stderr).splitlines():
    if line.strip():
        print(f'  20: {line}')
if r.returncode != 0:
    sys.exit(f'setup/20-protected-files.py failed (rc={r.returncode}) — the access model above IS '
             f'converged; the protected-upload wiring is not.')

print('Access setup done.')
