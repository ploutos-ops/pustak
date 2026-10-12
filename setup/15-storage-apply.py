#!/usr/bin/env python3
"""
15 — storage migration, PART B: point resources at their storage sha1.

Reads install/storage-migration-map.json (produced by 14-storage-upload.py) and sets each Static
Resource's `content` field to its sha1 — the change that makes the deliverStorageFile plugin serve it
from storage instead of disk. NO file access needed, so INFRA runs this on prod from the committed map
(the bytes were already uploaded to prod storage by part A).

Safety:
  - idempotent: a resource already at its sha1 is skipped; the update is guarded on the recorded
    old_content, so it never clobbers a resource that has since diverged (those are reported, not touched).
  - the map carries old_content, so this is reversible (a --revert would restore the paths).
Run AFTER part A has uploaded the bytes to the SAME storage environment the resources will resolve in.

Env: MODX_DB_* (the DB to write). Clear the MODx cache afterwards.
"""
import os, sys, json, pymysql
from estate import estate_path   # install/ lives in the estate repo now
from domains import db_config

HERE = os.path.dirname(os.path.abspath(__file__))
MAP_FILE = estate_path('install', 'storage-migration-map.json')
REVERT = '--revert' in sys.argv

DB = db_config()

with open(MAP_FILE) as fh:
    data = json.load(fh)
items = data['items']
print(f"map: {data['count']} items, environment={data['environment']}, mode={'REVERT' if REVERT else 'APPLY'}")

conn = pymysql.connect(**DB); cur = conn.cursor()
applied = skipped = diverged = 0
for it in items:
    rid, old, sha1 = it['id'], it['old_content'], it['sha1']
    frm, to = (sha1, old) if REVERT else (old, sha1)
    cur.execute("SELECT content FROM modx_site_content WHERE id=%s", (rid,))
    row = cur.fetchone()
    if not row:
        diverged += 1; continue
    cur = cur  # noop
    current = (row[0] or '').strip()
    if current == to:
        skipped += 1                      # already in target state
    elif current == frm:
        cur.execute("UPDATE modx_site_content SET content=%s WHERE id=%s", (to, rid))
        applied += 1
    else:
        diverged += 1                     # neither old nor new — report, don't touch
        print(f"  DIVERGED id={rid}: content is neither expected value; left untouched")
    # A storage file carries no page template. Zero it on apply; restore it on revert.
    newtpl = (it.get('old_template') or 0) if REVERT else 0
    cur.execute("UPDATE modx_site_content SET template=%s WHERE id=%s AND template<>%s", (newtpl, rid, newtpl))

conn.commit(); cur.close(); conn.close()
print(f"applied={applied} skipped(already)={skipped} diverged/missing={diverged}")
print("Clear the MODx cache (php clear-cache.php) after this.")
