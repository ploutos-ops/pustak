#!/usr/bin/env python3
"""
Create three-tree resource skeleton per context:
  support  (unpublished parent) — login, logout, search, error pages, robot, userHome, public
  content  (published parent)   — home
  files    (published parent)   — empty for now
"""
import os
import pymysql, time
from domains import db_config, BENCH
from estate import CONTEXTS as _SPEC

DB = db_config()
conn = pymysql.connect(**DB)
cur = conn.cursor()

now = int(time.time())

# Template IDs
TMPL_FULL   = 4   # page.full
TMPL_PUBLIC = 3   # page.public
TMPL_BLANK  = 2   # page.folder

def get_resource(context_key, alias, parent=0):
    cur.execute(
        "SELECT id FROM modx_site_content WHERE context_key=%s AND alias=%s AND parent=%s",
        (context_key, alias, parent)
    )
    row = cur.fetchone()
    return row[0] if row else None

def make_resource(context_key, pagetitle, alias, parent, template, published, menuindex, content='', hidemenu=1):
    existing = get_resource(context_key, alias, parent)
    if existing:
        print(f'  exists: [{context_key}] {alias} (id={existing})')
        return existing
    cur.execute("""
        INSERT INTO modx_site_content
          (type, pagetitle, longtitle, menutitle, description, alias, template, context_key,
           parent, published, hidemenu, menuindex, searchable, cacheable,
           createdby, createdon, publishedon, publishedby, content)
        VALUES
          ('document',%s,'',%s,'',%s,%s,%s,%s,%s,%s,%s,1,1,1,%s,%s,1,%s)
    """, (pagetitle, pagetitle, alias, template, context_key,
          parent, published, hidemenu, menuindex,
          now, now, content))
    rid = cur.lastrowid
    print(f'  created: [{context_key}] {alias} (id={rid})')
    return rid

# The estate's contexts, their names and their bench hosts — read from the estate file, never typed
# here (the three this listed were the keys before the estate renamed them).
contexts = [c for c in BENCH if c in _SPEC]
if not contexts:
    raise SystemExit("REFUSING: the estate declares no context with a bench host (domains.bench_zone + contexts)")
ids = {}   # ids[context][alias] = resource_id

for ctx in contexts:
    ids[ctx] = {}

    # ── support (unpublished, hidden, menuindex=2000) ────────────────────────
    sup = make_resource(ctx, 'Support', 'support', 0, TMPL_BLANK, 0, 2000)
    ids[ctx]['support'] = sup

    ids[ctx]['login']        = make_resource(ctx, 'Login',        'login',        sup, TMPL_PUBLIC, 1, 0)
    ids[ctx]['logout']       = make_resource(ctx, 'Logout',       'logout',       sup, TMPL_PUBLIC, 1, 0)
    ids[ctx]['search']       = make_resource(ctx, 'Search',       'search',       sup, TMPL_FULL,   1, 0)
    ids[ctx]['unauthorized'] = make_resource(ctx, 'Unauthorized', 'unauthorized', sup, TMPL_PUBLIC, 1, 0)
    ids[ctx]['error']        = make_resource(ctx, 'Error',        'error',        sup, TMPL_PUBLIC, 1, 0)
    ids[ctx]['unavailable']  = make_resource(ctx, 'Unavailable',  'unavailable',  sup, TMPL_PUBLIC, 1, 0)
    ids[ctx]['badip']        = make_resource(ctx, 'Bad IP',       'badip',        sup, TMPL_PUBLIC, 1, 0)
    ids[ctx]['robot']        = make_resource(ctx, 'Robots',       'robots',       sup, TMPL_BLANK,  1, 0,
                                             content='User-agent: *\nDisallow: /')
    ids[ctx]['userHome']     = make_resource(ctx, 'User Home',    'userHome',     sup, TMPL_FULL,   1, 0)
    ids[ctx]['public']       = make_resource(ctx, 'Public',       'public',       sup, TMPL_PUBLIC, 1, 0)

    # ── content (published, visible in menu, menuindex=1) ───────────────────
    cont = make_resource(ctx, 'Content', 'content', 0, TMPL_FULL, 1, 1)
    ids[ctx]['content'] = cont
    ids[ctx]['home']    = make_resource(ctx, 'Home', 'home', cont, TMPL_FULL, 1, 0)

    # ── files (published, menuindex=500) ────────────────────────────────────
    files = make_resource(ctx, 'Files', 'files', 0, TMPL_FULL, 1, 500)
    ids[ctx]['files'] = files

conn.commit()

# ── Print ID map ─────────────────────────────────────────────────────────────
print()
print('ID MAP:')
for ctx in contexts:
    print(f'  {ctx}:')
    for alias, rid in ids[ctx].items():
        print(f'    {alias}: {rid}')

# ── Context settings ─────────────────────────────────────────────────────────
print()
print('Applying context settings...')

def set_ctx(context_key, key, value):
    cur.execute(
        "SELECT context_key FROM modx_context_setting WHERE context_key=%s AND `key`=%s",
        (context_key, key)
    )
    if cur.fetchone():
        cur.execute(
            "UPDATE modx_context_setting SET value=%s WHERE context_key=%s AND `key`=%s",
            (value, context_key, key)
        )
    else:
        cur.execute(
            "INSERT INTO modx_context_setting (context_key, `key`, value, xtype) VALUES (%s,%s,%s,'textfield')",
            (context_key, key, value)
        )

hosts = {c: BENCH[c][0] for c in contexts}
names = {c: _SPEC[c].get('site_name') or c for c in contexts}

for ctx in contexts:
    i = ids[ctx]
    set_ctx(ctx, 'site_name',          names[ctx])
    set_ctx(ctx, 'http_host',          hosts[ctx])
    set_ctx(ctx, 'base_url',           '/')
    set_ctx(ctx, 'site_url',           f'http://{hosts[ctx]}/')
    set_ctx(ctx, 'site_start',         str(i['userHome']))
    set_ctx(ctx, 'error_page',         str(i['error']))
    set_ctx(ctx, 'unauthorized_page',  str(i['unauthorized']))
    set_ctx(ctx, 'login_page',         str(i['login']))
    set_ctx(ctx, 'logout_page',        str(i['logout']))
    set_ctx(ctx, 'search_page',        str(i['search']))
    set_ctx(ctx, 'bad_ip',             str(i['badip']))
    set_ctx(ctx, 'public_page',        str(i['public']))
    set_ctx(ctx, 'protected_page',     str(i['home']))
    set_ctx(ctx, 'nav_root_id',        str(i['content']))
    print(f'  context settings applied: {ctx}')

# ── Populate the auth pages (need per-context ids resolved above) ─────────────
# Login snippet lives ONLY on these support/housekeeping pages (never in content).
# The loginForm chunk carries `returnUrl=[[+request_uri]]`; the core login processor
# echoes it back as the response `url` (guarded to same-site). But the Login controller's
# redirectAfterLogin() consults `loginResourceId` FIRST and only falls through to that
# returnUrl when loginResourceId is empty. So the destination is chosen per-surface:
#   * unauthorized page — where a protected DEEP-LINK lands (sendUnauthorizedPage forwards
#     here, REQUEST_URI still the original) — omit loginResourceId so returnUrl wins and the
#     user resumes to the EXACT page they asked for.
#   * login page / logout page — proactive login, no meaningful prior URL — set
#     loginResourceId=userHome so they land on their home (userHome snippet routes per context).
print()
print('Populating login / userHome / logout / unauthorized pages...')

def login_call(i, return_to_prior=False):
    home_line = '' if return_to_prior else f"&loginResourceId=`{i['userHome']}`\n"
    return ("[[!Login?\n"
            + home_line +
            "&loginTpl=`Bootstrap.5.loginForm`\n"
            "&logoutTpl=`Bootstrap.5.logout`\n"
            "&errTpl=`lgnErrTpl`\n"
            "&loginMsg=`Login`\n"
            f"&logoutResourceId=`{i['logout']}`]]")

for ctx in contexts:
    i = ids[ctx]
    pages = {
        i['userHome']:     '[[!userHome]]',
        i['login']:        login_call(i),
        i['logout']:       '<p>You have been logged out. Come again soon.</p>\n' + login_call(i),
        i['unauthorized']: '<h2 class="mb-4">This page requires login.</h2>\n' + login_call(i, return_to_prior=True),
    }
    for rid, content in pages.items():
        cur.execute("UPDATE modx_site_content SET content=%s WHERE id=%s", (content, rid))
    print(f'  auth pages populated: {ctx}')

conn.commit()
cur.close()
conn.close()
print('Done.')
