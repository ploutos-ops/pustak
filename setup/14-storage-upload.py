#!/usr/bin/env python3
"""
14 — storage migration, PART A: upload the real bytes + emit the id->sha1 map.

For every published Static Resource whose content is an `assets/components/<comp>/files/<name>` path
and whose bytes are present locally (/srv/local/modx-assets/<comp>/files/<name>):
  - compute the REAL sha1 of the bytes (NOT the filename — filenames are the old system's hash: many
    are md5, some are arbitrary; only the sha1-of-bytes is what the Storage service keys on);
  - upload the bytes to the Storage service (system_id=modx, environment=$MODX_STORAGE_ENV), idempotent
    (dedup by sha1);
  - record {id, old_content, sha1, size, content_type} in install/storage-migration-map.json.

This script does NOT change any resource's content — that is PART B (15-storage-apply.py), so the DB
write can be applied to prod by infra from the committed map, while THIS part (which needs the files)
runs where the files are (the bench). FAIL-FAST: if ANY referenced file is missing, it aborts before
uploading anything (no partial migration).

Env:
  MODX_DB_*            — the DB to READ the resource list from (this box)
  MODX_STORAGE_TOKEN   — "Storage Service — modx" bearer  (else read from the storage.modx_token setting)
  MODX_STORAGE_ENV     — production | staging | development   (default: the storage.environment setting)
  MODX_STORAGE_BASEURL — the door's address (else the storage.base_url setting; neither => refused)
  MODX_ASSETS_ROOT     — default /srv/local/modx-assets
"""
import os, sys, json, hashlib, pymysql, urllib.request, urllib.error
from estate import estate_path   # install/ lives in the estate repo now
from domains import db_config

HERE = os.path.dirname(os.path.abspath(__file__))
MAP_FILE = estate_path('install', 'storage-migration-map.json')
ASSETS_ROOT = os.environ.get('MODX_ASSETS_ROOT', '/srv/local/modx-assets')

DB = db_config()

conn = pymysql.connect(**DB); cur = conn.cursor()

def setting(key, default=None):
    cur.execute("SELECT value FROM modx_system_settings WHERE `key`=%s", (key,))
    r = cur.fetchone(); return r[0] if r and r[0] not in (None, '') else default

BASE  = (os.environ.get('MODX_STORAGE_BASEURL') or setting('storage.base_url') or '').rstrip('/')
if not BASE:
    raise SystemExit("REFUSED: no storage door — neither MODX_STORAGE_BASEURL nor the storage.base_url "
                     "setting is set. Nothing was uploaded.")
ENV   = os.environ.get('MODX_STORAGE_ENV') or setting('storage.environment', 'production')
TOKEN = os.environ.get('MODX_STORAGE_TOKEN') or setting('storage.modx_token')
if not TOKEN:
    sys.exit('FATAL: no storage token (MODX_STORAGE_TOKEN or storage.modx_token setting).')

# --map MODE (Migration 2): upload bytes for resources that DO NOT EXIST YET.
# The default mode reads the resource list from the database, which cannot work for the
# chunk-only files — they were never migrated, so there is no row to read. In this mode
# the list comes from install/chunk-migration-map.json (produced by the chunk-migration step),
# which already carries the local path, the reserved id and the real sha1. Same upload,
# same idempotence, same storage environment: one door for bytes, two ways to name them.
MAP_IN = None
if '--map' in sys.argv:
    MAP_IN = sys.argv[sys.argv.index('--map') + 1]

if MAP_IN:
    with open(MAP_IN) as fh:
        manifest = json.load(fh)
    rows = [(it['new_id'], it['bytes'], it.get('content_type') or 0) for it in manifest['items']]
    print(f'--map {os.path.relpath(MAP_IN)}: {len(rows)} files, none of which exist as resources yet')
else:
    cur.execute("""SELECT id, content, content_type FROM modx_site_content
                   WHERE class_key LIKE '%%modStaticResource%%' AND published=1 AND deleted=0
                   AND content LIKE 'assets/components/%%/files/%%'""")
    rows = cur.fetchall()

# --- validate ALL files present first (fail-fast, no partial upload) ---------------------------
items, missing = [], []
for rid, content, ctype in rows:
    if MAP_IN:
        local = content                      # the manifest carries an absolute path
    else:
        rel = content.strip().split('assets/components/', 1)[1]
        local = os.path.join(ASSETS_ROOT, rel)
    if not os.path.isfile(local):
        missing.append((rid, content.strip()))
    else:
        items.append((rid, content.strip(), int(ctype or 0), local))
if missing:
    print(f'ABORT: {len(missing)} referenced files missing locally (no upload performed). First few:')
    for rid, c in missing[:10]: print(f'  id={rid} {c}')
    sys.exit(1)

# content_type coverage warning (mime source for the gate)
no_ct = [rid for rid, _, ct, _ in items if ct == 0]
if no_ct:
    print(f'WARNING: {len(no_ct)} resources have no content_type (would serve as octet-stream): {no_ct[:15]}')

def upload(local, rid):
    import subprocess
    # multipart via curl (keeps the bearer off argv through a temp header file is overkill here; use urllib)
    boundary = 'modx-storage-boundary'
    with open(local, 'rb') as fh: data = fh.read()
    parts = []
    def field(name, val):
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{val}\r\n'.encode())
    field('system_id', 'modx'); field('environment', ENV)
    field('uploaded_by', 'skunkworks-migrate'); field('system_object_id', str(rid))
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{os.path.basename(local)}"\r\n'
                 f'Content-Type: application/octet-stream\r\n\r\n'.encode() + data + b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    body = b''.join(parts)
    req = urllib.request.Request(f'{BASE}/v1/files', data=body, method='POST',
                                 headers={'Authorization': 'Bearer ' + TOKEN,
                                          'Content-Type': f'multipart/form-data; boundary={boundary}'})
    with urllib.request.urlopen(req, timeout=120) as resp:
        return json.load(resp)

print(f'Uploading {len(items)} files to storage (system_id=modx, environment={ENV}) ...')
mapping = []
for i, (rid, old, ct, local) in enumerate(items, 1):
    sha1 = hashlib.sha1(open(local, 'rb').read()).hexdigest()
    r = upload(local, rid)
    if r.get('sha1') != sha1:
        sys.exit(f'FATAL: storage sha1 {r.get("sha1")} != local sha1 {sha1} for id={rid}')
    mapping.append({'id': rid, 'old_content': old, 'sha1': sha1,
                    'size': r.get('size_bytes'), 'content_type': ct})
    if i % 100 == 0: print(f'  {i}/{len(items)}')

if MAP_IN:
    # Stamp the upload back into the manifest infra will act on, so the record of
    # "the bytes are in storage" travels with the record of "create this resource".
    by_id = {m['id']: m for m in mapping}
    for it in manifest['items']:
        m = by_id.get(it['new_id'])
        if m:
            it['uploaded'] = {'environment': ENV, 'sha1': m['sha1'], 'size': m['size']}
    manifest['bytes_uploaded'] = {'environment': ENV, 'count': len(mapping)}
    with open(MAP_IN, 'w') as fh:
        json.dump(manifest, fh, indent=1)
    print(f'Uploaded {len(mapping)} files; recorded in {os.path.relpath(MAP_IN)}')
else:
    os.makedirs(os.path.dirname(MAP_FILE), exist_ok=True)
    with open(MAP_FILE, 'w') as fh:
        json.dump({'environment': ENV, 'count': len(mapping), 'items': mapping}, fh, indent=1)
    print(f'Uploaded {len(mapping)} files; map written to {os.path.relpath(MAP_FILE)}')
cur.close(); conn.close()
