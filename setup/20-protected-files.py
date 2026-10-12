#!/usr/bin/env python3
"""
20 — protected-file UPLOAD wiring: the manager flow for putting bytes into storage (the download gate's other half).

The download gate built delivery: a Static Resource whose `content` is a bare sha1 streams from the Storage
service behind page RBAC (deliverStorageFile, installed by 13). What it never built was a way IN
that a person can use — the bytes reached storage through a migration script or a hand-typed curl.
The owner, 2026-09-06: an editor cannot be asked to remember curl. This script converges a ground so
the manager flow works end to end:

  1. a **Protected** media source rooted at `core/protected/` — under core/, which the webserver
     already denies, so an uploaded file is never web-readable for even a moment. `baseUrl` is
     deliberately empty: nothing under this source ever has a URL of its own;
  2. the directory itself, created if absent;
  3. the `storeProtectedFile` PLUGIN (common/plugins/storeProtectedFile — the header there owns the
     behaviour), bound to the events its .events file declares, exactly as 13 installs the
     delivery plugin;
  4. the `storage.protected_source` system setting = the source's id, which is how the plugin
     recognises "this static resource is a protected upload" — by id, never by name, so a manager
     rename cannot silently widen or narrow the absorb.

The editor's whole job is then: Files -> Protected -> upload; Create Static Resource; pick the
Protected source; point it at the file; save. The save absorbs the bytes into storage, swaps
`content` to the sha1, sets the real content type when the editor left the default, and removes
the disk copy. `store-file.py` at the repo root is the same act as a one-file CLI.

Idempotent. Run with the DB env (MODX_DB_*) and MODX_ROOT (refused if unset — 11's precedent: no
default docroot). Clear the MODx cache afterwards.
"""
import json
import os
import subprocess
import sys

import pymysql
from domains import db_config
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
from canon import db_body   # the database form: what the element deploy writes, byte for byte

HERE = os.path.dirname(os.path.abspath(__file__))
PLUGIN_NAME = 'storeProtectedFile'
PLUGIN_FILE = os.path.join(HERE, '..', 'common', 'plugins', PLUGIN_NAME)
SOURCE_NAME = 'Protected'
SOURCE_PATH = 'core/protected/'          # relative to MODX_BASE_PATH; core/ is webserver-denied

# MODX_ROOT is OPTIONAL here, deliberately — 04 invokes this script on grounds whose converge job
# passes only the DB env (production's does). The DB rows never need it; only the directory does,
# and a missing directory is REPORTED LOUDLY rather than refused, because the rows landing is what
# makes the ground converge idempotent from then on.
MODX_ROOT = os.environ.get('MODX_ROOT', '')

conn = pymysql.connect(**db_config())
cur = conn.cursor()

# --- 1+2: the Protected media source and its directory ----------------------------------------
# THE DIRECTORY STEP CAN NEVER FAIL THE CONVERGE. On its first ride through the staging converge
# (job 01a07936-0a72, 2026-09-06) a PermissionError here took the WHOLE access converge red —
# model written, cache never refreshed — breaking 04's stated contract that it refuses rather than
# half-applies. The directory is the one step that may genuinely need other hands on a ground; the
# rows below must land regardless, and a dir this uid cannot create is a LOUD LINE, not an exit.
if MODX_ROOT:
    protected_dir = os.path.join(MODX_ROOT, 'core', 'protected')
    def web_writable(path):
        """True when www-data can write `path`: as its owner, through its group, or as other."""
        import pwd, stat
        try:
            wu = pwd.getpwnam('www-data')
        except KeyError:
            return False
        st = os.stat(path)
        return stat.S_ISDIR(st.st_mode) and (
            (st.st_uid == wu.pw_uid and st.st_mode & stat.S_IWUSR)
            or (st.st_gid == wu.pw_gid and st.st_mode & stat.S_IWGRP)
            or st.st_mode & stat.S_IWOTH)

    # AN EXISTING, WEB-WRITABLE DIRECTORY IS LEFT ALONE. Production's is www-data-owned, and its
    # converge runs as automation, which may not chown or chmod a directory it does not own: every
    # run printed "DIRECTORY NOT ENSURED: Operation not permitted" over a directory that was already
    # exactly right (converge job 01a09ddf, 2026-09-14). The goal is the property, not the act.
    if os.path.isdir(protected_dir) and web_writable(protected_dir):
        st = os.stat(protected_dir)
        print(f'  {protected_dir} present and writable by the web user (mode {oct(st.st_mode & 0o7777)}) — left as is.')
    else:
        try:
            os.makedirs(protected_dir, exist_ok=True)
            # THE WEB USER MUST BE ABLE TO WRITE HERE — manager uploads land in this directory. As
            # root it is owned by www-data 2775; otherwise world-writable-sticky 2777, /tmp semantics:
            # this is an INBOX, not storage — files leave it on the first save, and core/ is never
            # web-served, so the wide mode is reachable only by local processes.
            try:
                import pwd
                wu = pwd.getpwnam('www-data')
                os.chown(protected_dir, wu.pw_uid, wu.pw_gid)
                os.chmod(protected_dir, 0o2775)
                print(f'  {protected_dir} ensured (www-data, 2775).')
            except (KeyError, PermissionError, OSError):
                os.chmod(protected_dir, 0o2777)
                print(f'  {protected_dir} ensured (2777 — could not chown to the web user from this '
                      f'uid; an inbox files leave on first save, under core/ which is never web-served).')
        except OSError as e:
            print(f'  DIRECTORY NOT ENSURED: {e}. core/protected/ must exist web-writable on this '
                  f'ground or manager uploads will fail — everything else below is converged.')
else:
    print('  DIRECTORY NOT ENSURED: MODX_ROOT is not set in this environment. core/protected/ '
          'must exist web-writable on this ground or manager uploads will fail — everything else '
          'below is converged.')

# Property shape mirrors what the manager writes: full property rows, value carried in `value`.
# The column is PHP-SERIALIZED (xPDO field type array — measured: writing JSON here produced
# `unserialize(): Error at offset 0` and a source that "declares no basePath"), so the dict is
# serialized by php itself rather than by a hand-rolled serializer that would drift from it.
props = {
    'basePath':         {'name': 'basePath', 'type': 'textfield', 'value': SOURCE_PATH},
    'basePathRelative': {'name': 'basePathRelative', 'type': 'combo-boolean', 'value': True},
    'baseUrl':          {'name': 'baseUrl', 'type': 'textfield', 'value': ''},
    'baseUrlRelative':  {'name': 'baseUrlRelative', 'type': 'combo-boolean', 'value': True},
}
props_php = subprocess.run(
    ['php', '-r', 'echo serialize(json_decode($argv[1], true));', json.dumps(props)],
    capture_output=True, text=True, check=True).stdout
if not props_php.startswith('a:'):
    sys.exit(f'REFUSING: php did not serialize the source properties (got {props_php[:60]!r}).')

cur.execute("SELECT id FROM modx_media_sources WHERE name=%s", (SOURCE_NAME,))
row = cur.fetchone()
if row:
    sid = row[0]
    cur.execute("UPDATE modx_media_sources SET properties=%s WHERE id=%s", (props_php, sid))
    print(f'  media source {SOURCE_NAME} updated (id={sid}).')
else:
    cur.execute(
        "INSERT INTO modx_media_sources (name, description, class_key, properties, is_stream) "
        "VALUES (%s, %s, 'MODX\\\\Revolution\\\\Sources\\\\modFileMediaSource', %s, 1)",
        (SOURCE_NAME,
         'Protected uploads: files here are absorbed into the Storage service on the first '
         'save of a Static Resource that points at them. Not web-served, ever.',
         props_php))
    sid = cur.lastrowid
    print(f'  media source {SOURCE_NAME} created (id={sid}).')

# --- 3: the plugin, bound to what its .events file declares (13's upsert, generalised) ---------
with open(PLUGIN_FILE, encoding='utf-8') as fh:
    code = db_body(fh.read())
with open(PLUGIN_FILE + '.events', encoding='utf-8') as fh:
    events = [ln.strip() for ln in fh if ln.strip()]

cur.execute("SELECT id FROM modx_site_plugins WHERE name=%s", (PLUGIN_NAME,))
row = cur.fetchone()
if row:
    pid = row[0]
    cur.execute("UPDATE modx_site_plugins SET plugincode=%s, disabled=0 WHERE id=%s", (code, pid))
    print(f'  {PLUGIN_NAME} plugin updated (id={pid}).')
else:
    cur.execute(
        "INSERT INTO modx_site_plugins (name, description, plugincode, disabled, category) "
        "VALUES (%s, %s, %s, 0, 0)",
        (PLUGIN_NAME,
         'Protected-file upload absorb: on save, a Static Resource on the Protected source '
         'has its bytes pushed to the Storage service and its content swapped to the sha1.',
         code))
    pid = cur.lastrowid
    print(f'  {PLUGIN_NAME} plugin created (id={pid}).')

for ev in events:
    cur.execute("SELECT 1 FROM modx_site_plugin_events WHERE pluginid=%s AND event=%s", (pid, ev))
    if not cur.fetchone():
        cur.execute("INSERT INTO modx_site_plugin_events (pluginid, event, priority, propertyset) "
                    "VALUES (%s, %s, 0, 0)", (pid, ev))
    print(f'  bound to {ev}.')

# --- 4: the setting the plugin matches the source BY ------------------------------------------
cur.execute("SELECT value FROM modx_system_settings WHERE `key`='storage.protected_source'")
if cur.fetchone():
    cur.execute("UPDATE modx_system_settings SET value=%s WHERE `key`='storage.protected_source'", (str(sid),))
else:
    cur.execute("INSERT INTO modx_system_settings (`key`, value, xtype, namespace, area) "
                "VALUES ('storage.protected_source', %s, 'textfield', 'storage', 'storage')", (str(sid),))
print(f'  storage.protected_source = {sid}.')

conn.commit()
cur.close()
conn.close()
print('Protected-upload wiring complete. Clear the MODx cache (php clear-cache.php) after this.')
