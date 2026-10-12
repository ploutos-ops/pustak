#!/usr/bin/env python3
"""
13 — storage download-gate wiring.

Installs the `deliverStorageFile` PLUGIN (common/plugins/deliverStorageFile), binds it to the
OnWebPagePrerender event, and creates the `storage.*` system settings it reads.

THE MODEL (see the plugin's own header for the full rationale + compatibility watch):
  A protected file is a NATIVE Static Resource whose `content` field holds the sha1 of its bytes
  (the ONE and only copy — no TV) and whose native `content_type` is the real type (e.g. PDF). Core
  enforces the same RBAC as any page (resource-group check, in the request phase, BEFORE render); the
  plugin then, on render, replaces the disk read with a storage fetch by sha1. A Static Resource whose
  content is a normal file path is left untouched (served from disk). Delivery is decided by the shape
  of `content`: a bare 40-hex string => storage; anything else => disk.

  Why a plugin and not a custom resource class: the custom-class path is MODX-3-unreliable
  (revolution#14759) and rides the flakiest seam; the plugin is a documented, maintained mechanism.
  Why not edit core: an upgrade overwrites core files wholesale, so the patch dies every upgrade.

SECRET NEVER HARD-CODED. The storage bearer comes from the environment; unset => existing value is
left untouched (a re-run never blanks a configured token):
  MODX_STORAGE_TOKEN   — "Storage Service — modx" bearer (Vaultwarden)   [set by infra on prod]
  MODX_STORAGE_BASEURL — the door's https address (it authenticates by CLIENT CERTIFICATE, which
#                         cannot be presented without TLS); unset => the existing value is left as it is
  MODX_STORAGE_ENV     — storage scope environment (production | staging | development); default production

Idempotent. Run with the DB env (MODX_DB_*). Clear the MODx cache afterwards.
"""
import os
import pymysql
from domains import db_config

HERE = os.path.dirname(os.path.abspath(__file__))
PLUGIN_FILE = os.path.join(HERE, '..', 'common', 'plugins', 'deliverStorageFile')

DB = db_config()

conn = pymysql.connect(**DB)
cur = conn.cursor()


def set_setting(key, value, xtype='textfield'):
    """Upsert a system setting. value=None => leave existing untouched, create empty if absent."""
    cur.execute("SELECT value FROM modx_system_settings WHERE `key`=%s", (key,))
    row = cur.fetchone()
    if row:
        if value is not None:
            cur.execute("UPDATE modx_system_settings SET value=%s WHERE `key`=%s", (value, key))
        return
    cur.execute("INSERT INTO modx_system_settings (`key`,value,xtype,namespace,area) "
                "VALUES (%s,%s,%s,'storage','storage')", (key, value if value is not None else '', xtype))


# --- settings ---------------------------------------------------------------------------------
set_setting('storage.base_url',   os.environ.get('MODX_STORAGE_BASEURL'))   # only if provided: the ground's environment owns it
set_setting('storage.modx_token', os.environ.get('MODX_STORAGE_TOKEN'), 'password')   # only if provided
set_setting('storage.environment', os.environ.get('MODX_STORAGE_ENV', 'production'))
print('storage.* settings ensured.')

# --- plugin element + event binding -----------------------------------------------------------
with open(PLUGIN_FILE, encoding='utf-8') as fh:
    code = fh.read()

cur.execute("SELECT id FROM modx_site_plugins WHERE name='deliverStorageFile'")
row = cur.fetchone()
if row:
    pid = row[0]
    cur.execute("UPDATE modx_site_plugins SET plugincode=%s, disabled=0 WHERE id=%s", (code, pid))
    print(f'  deliverStorageFile plugin updated (id={pid})')
else:
    cur.execute("INSERT INTO modx_site_plugins (name, description, plugincode, disabled, category) "
                "VALUES ('deliverStorageFile', "
                "'Object-storage download gate: a Static Resource whose content is a bare sha1 "
                "is streamed from the Storage service instead of disk, behind the same RBAC as a page.', "
                "%s, 0, 0)", (code,))
    pid = cur.lastrowid
    print(f'  deliverStorageFile plugin created (id={pid})')

cur.execute("SELECT 1 FROM modx_site_plugin_events WHERE pluginid=%s AND event='OnWebPagePrerender'", (pid,))
if not cur.fetchone():
    cur.execute("INSERT INTO modx_site_plugin_events (pluginid, event, priority, propertyset) "
                "VALUES (%s, 'OnWebPagePrerender', 0, 0)", (pid,))
print('  bound to OnWebPagePrerender.')

conn.commit()
cur.close()
conn.close()
print('Storage gate wiring complete. Clear the MODx cache (php clear-cache.php) after this.')
