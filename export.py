#!/usr/bin/env python3
"""Export MODx chunks, snippets, and templates to files."""

import pymysql
import os
import sys
import re
from canon import canon  # single source of the canonical on-disk form (LF + one trailing newline)
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'setup'))
from domains import db_config

# Env-driven so the same tool exports from the dev bench (defaults) or, via an
# infra-run wrapper, from a prod dump loaded locally.
DB = db_config()
REPO = os.path.dirname(os.path.abspath(__file__))

DIRS = {
    'modx_site_htmlsnippets': os.path.join(REPO, 'common/chunks'),
    'modx_site_snippets':     os.path.join(REPO, 'common/snippets'),
    'modx_site_templates':    os.path.join(REPO, 'common/templates'),
}

# Optional editor extension per element type; bare ('') when unset. import.py + apply-modx-elements.sh
# strip a whitelisted extension to recover the element name, so this is safe to turn on once the
# element deploy tool is extension-tolerant. Chunks are mostly HTML; the JS chunks (kuber.js,
# Bootstrap.5.Javascript) would want '.js' — handle those as a per-name exception when we flip.
EXT = {
    'modx_site_htmlsnippets': '.html',   # chunks are HTML fragments
    'modx_site_snippets':     '.php',    # snippets are PHP
    'modx_site_templates':    '.html',   # templates are HTML
}

def safe_name(name):
    return re.sub(r'[^\w.\-]', '_', name)

def file_ext(table, name):
    """Extension to append. Don't re-suffix a chunk whose NAME already ends in a real code
    extension (kuber.js) — it stays as-is and highlights correctly; import.py strips only .html/.php
    so its name is preserved either way."""
    ext = EXT[table]
    if ext == '.html' and re.search(r'\.(js|css)$', name):
        return ''
    return ext

conn = pymysql.connect(**DB)
cur = conn.cursor()

for table, directory in DIRS.items():
    name_col = 'name' if table != 'modx_site_templates' else 'templatename'
    content_col = 'snippet' if table == 'modx_site_snippets' else ('content' if table == 'modx_site_templates' else 'snippet')
    cur.execute(f'SELECT {name_col}, {content_col} FROM {table}')
    for row in cur.fetchall():
        name, content = row
        filename = os.path.join(directory, safe_name(name) + file_ext(table, name))
        # THE TRACKED TREE IS THE MANIFEST. Export an element only if we already own a file for it.
        # The DB also holds third-party elements (pdoTools, SimpleSearch) and MODx stock ones
        # (BaseTemplate) that install-extras.php recreates on any fresh build — tracking those would
        # duplicate a source we do not own, and they showed up as untracked noise after every
        # restore. Filtering by MODx category does NOT work: SearchForm sits in the SimpleSearch
        # category but is ours and customized, while BaseTemplate has no category and is not ours.
        # File-exists is exact. Adding a genuinely new element stays a deliberate one-time act,
        # which the one-master rule already requires (elements are authored in git, not in the
        # prod manager) — the skip is printed, never silent.
        if not os.path.exists(filename):
            print(f'  skipped (not tracked): {table}/{name}')
            continue
        with open(filename, 'w', encoding='utf-8') as f:
            f.write(canon(content))
        print(f'  exported: {table}/{name}')

cur.close()
conn.close()
print('Export complete.')
