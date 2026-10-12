#!/usr/bin/env python3
"""Import chunks, snippets, and templates from files into MODx."""

import pymysql
import os
import sys
import re
import subprocess
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'setup'))
from domains import db_config

# The element NAME is the filename with the editor extension WE ADD stripped, so an element file
# may be leftNavBar.html / userHome.php for editor support while the MODx element name stays
# leftNavBar / userHome. Only '.html'/'.php' are stripped — the ones export.py adds — never '.js'
# or '.css', because element names can legitimately END in those (the chunk literally named
# 'kuber.js'): stripping '.js' there would corrupt the name. A JS chunk we choose to suffix becomes
# 'kuber.js.html' and strips back to 'kuber.js' correctly.
def element_name(filename):
    return re.sub(r'\.(html|php)$', '', filename)

# Connection + cache path are env-driven so the SAME tool applies elements on the
# dev bench (defaults) or, via an infra-run wrapper, against production.
DB = db_config()
REPO = os.path.dirname(os.path.abspath(__file__))

ITEMS = [
    ('common/chunks',    'modx_site_htmlsnippets', 'name',         'snippet'),
    ('common/snippets',  'modx_site_snippets',     'name',         'snippet'),
    ('common/templates', 'modx_site_templates',    'templatename', 'content'),
]

conn = pymysql.connect(**DB)
cur = conn.cursor()

for subdir, table, name_col, content_col in ITEMS:
    path = os.path.join(REPO, subdir)
    for filename in sorted(os.listdir(path)):
        filepath = os.path.join(path, filename)
        if not os.path.isfile(filepath):
            continue
        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read()
        name = element_name(filename)

        cur.execute(f'SELECT id FROM {table} WHERE {name_col} = %s', (name,))
        row = cur.fetchone()
        if row:
            cur.execute(f'UPDATE {table} SET {content_col} = %s WHERE id = %s', (content, row[0]))
            print(f'  updated: {subdir}/{name}')
        else:
            cur.execute(
                f'INSERT INTO {table} ({name_col}, {content_col}) VALUES (%s, %s)',
                (name, content)
            )
            print(f'  inserted: {subdir}/{name}')

conn.commit()
cur.close()
conn.close()

# Clear snippet/chunk/template script and includes caches so stale compiled
# PHP files don't serve the old content.
# NO DEFAULT. This fell back to '/srv/local/modx' — the dev bench that stopped existing when the
# bench became staging — and the consequence is worse than a missing path: the loop below runs
# `sudo rm -rf` on whatever it builds. A default here decides what SILENCE means, and silence
# meant deleting under a path nobody on this ground named (2026-08-14).
_root = os.environ.get('MODX_ROOT')
if not _root or not os.path.isfile(os.path.join(_root, 'index.php')):
    sys.exit("REFUSING: MODX_ROOT is %r and has no index.php. The element caches were NOT cleared,\n"
             "          so the import above may be serving stale compiled PHP. Set MODX_ROOT."
             % (_root or ''))
CACHE_BASE = _root + '/core/cache'
# The invoker's own first (the bench's php-fpm runs as the bench's owner, so the cache is
# theirs to delete, and an agent holds no sudo — every run here printed two sudo refusals and
# left the element caches standing, measured 2026-09-18); sudo only where the tree is not ours.
import shutil
for subdir in ['scripts/elements', 'includes/elements']:
    path = os.path.join(CACHE_BASE, subdir)
    try:
        shutil.rmtree(path)
    except FileNotFoundError:
        pass
    except PermissionError:
        subprocess.run(['sudo', 'rm', '-rf', path], check=False)

print('Import complete.')
