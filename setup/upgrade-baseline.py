#!/usr/bin/env python3
"""
upgrade-baseline — capture and compare the state of a MODx DB across an upgrade.

    setup/upgrade-baseline.py capture before.json      # write a baseline
    setup/upgrade-baseline.py compare before.json after.json

Why this exists: the upgrade runbook (docs/upgrades.md) says "verify it still works". Without a
recorded before-state that is an opinion, not a check. This records the things a core upgrade can
silently change — the version, the table list, every table's row count, and the counts of the
objects we actually author (contexts, resources per context, elements per class, TVs, users,
access rows) — and then diffs two captures so the report is "what moved", not "it looks fine".

Contract:
  - capture is READ-ONLY. Safe on production.
  - compare exits 0 when nothing moved, 1 when something did. Every difference is printed; none
    are judged. A MODx release legitimately adds tables and settings rows — the caller decides
    whether each delta is explained by the release notes. This tool never decides that.

Env: MODX_DB_HOST / MODX_DB_USER / MODX_DB_PASS / MODX_DB_NAME (dev-box values as defaults),
MODX_ROOT for the version file — required, no default.
"""
import argparse, json, os, re, sys, pymysql, domains

# No default: /srv/local/modx was the dev bench and it is gone (2026-08-14). An upgrade
# baseline taken against a guessed docroot describes a MODx nobody is running.
MODX_ROOT = os.environ.get('MODX_ROOT') or sys.exit(
    "REFUSING: MODX_ROOT is not set, so there is no docroot to take a baseline of. Nothing read.")

# The element tables we author, keyed by the name used in the report.
ELEMENT_TABLES = {
    'chunks':    'modx_site_htmlsnippets',
    'snippets':  'modx_site_snippets',
    'templates': 'modx_site_templates',
    'plugins':   'modx_site_plugins',
    'tvs':       'modx_site_tmplvars',
}


def code_version(root=MODX_ROOT):
    """Read the version off the codebase, not the DB — they can disagree mid-upgrade, and that
    disagreement is itself the thing worth seeing."""
    path = os.path.join(root, 'core', 'docs', 'version.inc.php')
    try:
        text = open(path, encoding='utf-8').read()
    except OSError as exc:
        return f'unreadable ({exc.__class__.__name__})'
    parts = {}
    for key in ('version', 'major_version', 'minor_version', 'patch_level', 'distro'):
        m = re.search(r"\$v\['%s'\]\s*=\s*'([^']*)'" % key, text)
        parts[key] = m.group(1) if m else '?'
    return "%s.%s.%s-%s (%s)" % (parts['version'], parts['major_version'], parts['minor_version'],
                                 parts['patch_level'], parts['distro'].strip('@'))


def capture(cur, root=MODX_ROOT):
    snap = {'code_version': code_version(root)}

    cur.execute("SELECT VERSION()")
    snap['mysql_version'] = cur.fetchone()[0]

    # DB's own idea of the version — MODx writes this at the end of a successful setup run.
    cur.execute("SELECT value FROM modx_system_settings WHERE `key` = 'settings_version'")
    row = cur.fetchone()
    snap['settings_version'] = row[0] if row else None

    cur.execute("SELECT DATABASE()")
    dbname = cur.fetchone()[0]

    # Table list + row counts. COUNT(*) per table, not information_schema.TABLE_ROWS — the latter
    # is an InnoDB estimate and would produce phantom diffs.
    cur.execute("SELECT table_name FROM information_schema.tables "
                "WHERE table_schema = %s AND table_type = 'BASE TABLE' ORDER BY table_name", (dbname,))
    tables = [r[0] for r in cur.fetchall()]
    counts = {}
    for t in tables:
        cur.execute("SELECT COUNT(*) FROM `%s`" % t)   # table names come from information_schema
        counts[t] = cur.fetchone()[0]
    snap['tables'] = counts

    # Resources per context — a whole-DB resource count would hide a context losing its documents
    # to another gaining them.
    cur.execute("SELECT context_key, COUNT(*) FROM modx_site_content GROUP BY context_key "
                "ORDER BY context_key")
    snap['resources_by_context'] = {k: n for k, n in cur.fetchall()}

    # Static resources vs documents: the storage gate depends on class_key, and a core
    # upgrade that rewrote class_key strings would be invisible in a plain resource count.
    cur.execute("SELECT class_key, COUNT(*) FROM modx_site_content GROUP BY class_key "
                "ORDER BY class_key")
    snap['resources_by_class'] = {k: n for k, n in cur.fetchall()}

    cur.execute("SELECT published, COUNT(*) FROM modx_site_content GROUP BY published")
    snap['resources_by_published'] = {str(k): n for k, n in cur.fetchall()}

    snap['elements'] = {}
    for name, table in ELEMENT_TABLES.items():
        cur.execute("SELECT COUNT(*) FROM `%s`" % table)
        snap['elements'][name] = cur.fetchone()[0]

    cur.execute("SELECT `key` FROM modx_context ORDER BY `key`")
    snap['contexts'] = [r[0] for r in cur.fetchall()]

    # Access control: the thing that locks everyone out if an upgrade moves it.
    ACCESS_TABLES = {
        'user_groups':            'modx_membergroup_names',
        'user_group_members':     'modx_member_groups',
        'resource_groups':        'modx_documentgroup_names',
        'resource_group_members': 'modx_document_groups',
        'access_context':         'modx_access_context',
        'access_resource_groups': 'modx_access_resource_groups',
    }
    snap['access'] = {}
    for name, table in ACCESS_TABLES.items():
        if table not in counts:            # a release could rename one; say so rather than crash
            snap['access'][name] = f'MISSING TABLE {table}'
            continue
        cur.execute("SELECT COUNT(*) FROM `%s`" % table)
        snap['access'][name] = cur.fetchone()[0]

    cur.execute("SELECT COUNT(*) FROM modx_users")
    snap['users'] = cur.fetchone()[0]

    # System settings: an upgrade adds new keys. Record the key list so an added/removed key is
    # named, and record OUR settings' values so a silent default-reset is visible.
    cur.execute("SELECT `key` FROM modx_system_settings ORDER BY `key`")
    snap['system_setting_keys'] = [r[0] for r in cur.fetchall()]
    watched = ('friendly_urls', 'friendly_alias_urls', 'container_suffix', 'auto_isfolder',
               'use_alias_path', 'link_tag_scheme', 'default_template', 'site_status',
               'error_page', 'unauthorized_page', 'site_start')
    cur.execute("SELECT `key`, value FROM modx_system_settings WHERE `key` IN %s", (watched,))
    snap['watched_settings'] = {k: v for k, v in cur.fetchall()}

    cur.execute("SELECT context_key, `key`, value FROM modx_context_setting "
                "ORDER BY context_key, `key`")
    snap['context_settings'] = {f'{c}.{k}': v for c, k, v in cur.fetchall()}

    return snap


def _diff_map(label, before, after, out):
    """Report added / removed / changed keys of two dicts. Returns True if anything moved."""
    moved = False
    for key in sorted(set(after) - set(before)):
        out.append(f'  + {label}: {key} = {after[key]!r}  (added)')
        moved = True
    for key in sorted(set(before) - set(after)):
        out.append(f'  - {label}: {key} = {before[key]!r}  (removed)')
        moved = True
    for key in sorted(set(before) & set(after)):
        if before[key] != after[key]:
            out.append(f'  ~ {label}: {key}: {before[key]!r} -> {after[key]!r}')
            moved = True
    return moved


def compare(before, after):
    out, moved = [], False
    for scalar in ('code_version', 'settings_version', 'mysql_version', 'users'):
        if before.get(scalar) != after.get(scalar):
            out.append(f'  ~ {scalar}: {before.get(scalar)!r} -> {after.get(scalar)!r}')
            moved = True
    if before.get('contexts') != after.get('contexts'):
        out.append(f"  ~ contexts: {before.get('contexts')} -> {after.get('contexts')}")
        moved = True
    for label in ('tables', 'resources_by_context', 'resources_by_class',
                  'resources_by_published', 'elements', 'access', 'watched_settings',
                  'context_settings'):
        moved |= _diff_map(label, before.get(label, {}), after.get(label, {}), out)
    b_keys, a_keys = set(before.get('system_setting_keys', [])), set(after.get('system_setting_keys', []))
    for key in sorted(a_keys - b_keys):
        out.append(f'  + system_setting_keys: {key}  (added)')
        moved = True
    for key in sorted(b_keys - a_keys):
        out.append(f'  - system_setting_keys: {key}  (removed)')
        moved = True
    return moved, out


ap = argparse.ArgumentParser(description='capture / compare MODx state across an upgrade')
sub = ap.add_subparsers(dest='cmd', required=True)
p_cap = sub.add_parser('capture', help='write a baseline JSON (read-only)')
p_cap.add_argument('path')
p_cmp = sub.add_parser('compare', help='diff two baselines; exit 1 if anything moved')
p_cmp.add_argument('before')
p_cmp.add_argument('after')
args = ap.parse_args()

if args.cmd == 'capture':
    try:
        conn = pymysql.connect(**domains.db_config())
        cur = conn.cursor()
        snap = capture(cur)
        cur.close(); conn.close()
    except Exception as exc:
        print(f'upgrade-baseline: cannot capture ({exc.__class__.__name__}: {exc})', file=sys.stderr)
        sys.exit(2)
    with open(args.path, 'w', encoding='utf-8') as fh:
        json.dump(snap, fh, indent=2, sort_keys=True, default=str)
        fh.write('\n')
    print(f"upgrade-baseline: captured {snap['code_version']} -> {args.path} "
          f"({len(snap['tables'])} tables, {sum(snap['resources_by_context'].values())} resources)")
    sys.exit(0)

before = json.load(open(args.before, encoding='utf-8'))
after = json.load(open(args.after, encoding='utf-8'))
moved, lines = compare(before, after)
print(f"before: {before.get('code_version')}   after: {after.get('code_version')}")
if not moved:
    print('upgrade-baseline: identical — nothing moved')
    sys.exit(0)
print(f'upgrade-baseline: {len(lines)} difference(s) — each needs an explanation from the release notes')
for line in lines:
    print(line)
sys.exit(1)
