#!/usr/bin/env python3
"""
16 — modxSite context: the three site-wide fallback pages.

MODx's SYSTEM (global) site_start / error_page / unauthorized_page must point at SOME resource.
Every served context (family/corporate/worldview/claude) defines its OWN per-context override, so
the system-level fallback never actually fires — but the check in the manager tests those system
settings, and after our id renumbering they had drifted to pointing at a served context's
(sometimes unpublished) resource. The owner's design (2026-07-20): a dedicated, NEVER-SERVED context
`modxSite` holding exactly three published pages, and the system fallbacks point THERE — so the
site-wide defaults borrow no served context's resources.

`modxSite` has NO host binding and no context settings: nothing routes to it, it only supplies
three valid published resources for the three system settings to reference. This script creates
the context + pages and repoints the system settings. Idempotent: resolves by (context, alias)
and by context key, so a re-run fills gaps rather than duplicating.

Built by infra by hand on prod first; this folds it into setup so a fresh build
starts with it. Run after the per-context setup (03/11) — it only touches modxSite + three system
settings, nothing else.
"""
import os, time, pymysql
from domains import db_config

DB = db_config()

CTX_KEY  = 'modxSite'
CTX_NAME = 'MODx Site'
CTX_DESC = ('Holds the three site-wide fallback pages (site_start, error, unauthorized) so system '
            'settings never borrow a served context\'s resources.')
TMPL_PUBLIC   = 3   # page.public — clean, no nav dependency
CTYPE_HTML    = 1   # text/html

# alias, pagetitle, system-setting key, content
PAGES = [
    ('modxsite-home',         'MODx Site',    'site_start',
     '<p>Site-wide fallback start page; every served context defines its own site_start.</p>'),
    ('modxsite-error',        'Error',        'error_page',
     '<p>Site-wide fallback error page; every served context defines its own error_page.</p>'),
    ('modxsite-unauthorized', 'Unauthorized', 'unauthorized_page',
     '<p>Site-wide fallback unauthorized page; every served context defines its own unauthorized_page.</p>'),
]

conn = pymysql.connect(**DB); cur = conn.cursor()


def one(q, a=()):
    cur.execute(q, a); r = cur.fetchone(); return r[0] if r else None


# 1) context row (never served: no host, no context settings)
if not one("SELECT `key` FROM modx_context WHERE `key`=%s", (CTX_KEY,)):
    rank = (one("SELECT MAX(rank) FROM modx_context") or 0) + 1
    cur.execute("INSERT INTO modx_context (`key`,name,description,rank) VALUES (%s,%s,%s,%s)",
                (CTX_KEY, CTX_NAME, CTX_DESC, rank))
    print(f"  created context: {CTX_KEY} (rank {rank})")
else:
    cur.execute("UPDATE modx_context SET name=%s, description=%s WHERE `key`=%s", (CTX_NAME, CTX_DESC, CTX_KEY))
    print(f"  context exists: {CTX_KEY}")


def ensure_page(alias, pagetitle, content):
    """Create-or-return a published fallback page by (context, alias). Reconciles content on
    re-run so an edit here propagates rather than being ignored."""
    rid = one("SELECT id FROM modx_site_content WHERE context_key=%s AND alias=%s", (CTX_KEY, alias))
    if rid:
        cur.execute("UPDATE modx_site_content SET content=%s WHERE id=%s AND content<>%s", (content, rid, content))
        return rid
    cur.execute("""INSERT INTO modx_site_content
        (type,pagetitle,longtitle,menutitle,description,alias,template,content_type,context_key,parent,
         published,hidemenu,menuindex,searchable,cacheable,createdon,publishedon,content)
        VALUES ('document',%s,%s,%s,'',%s,%s,%s,%s,0,1,1,0,0,1,%s,%s,%s)""",
        (pagetitle, pagetitle, pagetitle, alias, TMPL_PUBLIC, CTYPE_HTML, CTX_KEY,
         int(time.time()), int(time.time()), content))
    rid = cur.lastrowid
    # flat uri (use_alias_path=0) — a NULL uri is unroutable
    cur.execute("UPDATE modx_site_content SET uri=%s WHERE id=%s", (alias, rid))
    print(f"  created page: {alias} (id={rid})")
    return rid


def set_system(key, value):
    if one("SELECT 1 FROM modx_system_settings WHERE `key`=%s", (key,)):
        cur.execute("UPDATE modx_system_settings SET value=%s, editedon=NOW() WHERE `key`=%s", (str(value), key))
    else:
        cur.execute("INSERT INTO modx_system_settings (`key`,value,xtype,namespace,area,editedon) "
                    "VALUES (%s,%s,'textfield','core','site',NOW())", (key, str(value)))


for alias, title, syskey, content in PAGES:
    rid = ensure_page(alias, title, content)
    set_system(syskey, rid)
    print(f"  system {syskey} -> {rid}")

conn.commit(); cur.close(); conn.close()
print("modxSite complete. Clear the MODx cache next.")
