#!/usr/bin/env python3
"""
Set up MODx contexts, context settings, and users.

WHICH contexts and WHICH people is not decided here — it is declared in `setup/estate.py`, which is
the one file an outsider installing this software edits. This script used to carry three
contexts and three real people inline, so the software and the owner's estate were the same artifact and
could not be separated without knowing to look here.

Idempotent: an existing context or user is reported and left alone.
"""

import pymysql
import os

from domains import db_config
from estate import CONTEXTS, WEB_CONTEXT, USERS

DB = db_config()
conn = pymysql.connect(**DB)
cur = conn.cursor()

# ── Contexts ─────────────────────────────────────────────────────────────────

for key, spec in CONTEXTS.items():
    name, desc, rank = spec['name'], spec['description'], spec['rank']
    cur.execute('SELECT `key` FROM modx_context WHERE `key` = %s', (key,))
    if not cur.fetchone():
        cur.execute(
            'INSERT INTO modx_context (`key`, name, description, rank) VALUES (%s, %s, %s, %s)',
            (key, name, desc, rank)
        )
        print(f'  created context: {key}')
    else:
        print(f'  context exists: {key}')

# MODX's own `web` context always exists; only relabel it.
cur.execute("UPDATE modx_context SET name=%s, description=%s, rank=%s WHERE `key`='web'",
            (WEB_CONTEXT['name'], WEB_CONTEXT['description'], WEB_CONTEXT['rank']))

# ── Context Settings ──────────────────────────────────────────────────────────

def set_context_setting(context_key, setting_key, value, xtype='textfield'):
    cur.execute(
        'SELECT context_key FROM modx_context_setting WHERE context_key=%s AND `key`=%s',
        (context_key, setting_key)
    )
    if cur.fetchone():
        cur.execute(
            'UPDATE modx_context_setting SET value=%s WHERE context_key=%s AND `key`=%s',
            (value, context_key, setting_key)
        )
    else:
        cur.execute(
            'INSERT INTO modx_context_setting (context_key, `key`, value, xtype) VALUES (%s, %s, %s, %s)',
            (context_key, setting_key, value, xtype)
        )

context_settings = {'web': {'site_name': WEB_CONTEXT['site_name']}}
context_settings.update({k: {'site_name': v['site_name']} for k, v in CONTEXTS.items()})

for ctx, settings in context_settings.items():
    for k, v in settings.items():
        set_context_setting(ctx, k, v)
    print(f'  settings applied: {ctx}')

# ── Resource Group ────────────────────────────────────────────────────────────
# Deliberately NOT created here. setup/04 owns the protected resource group end to end: it
# adopts a legacy `Protected` by in-place rename, or creates `allContextProtected` if neither
# name exists. If 01 created it under the new name first, 04's adopt would find the new name
# already present and skip the rename, stranding the legacy `Protected` as a parallel group with
# the old grants (BUG 1). One owner for that group: setup/04.

# ── Users ─────────────────────────────────────────────────────────────────────
# Declared in estate.py (imported at the top). This script only creates them; setup/04 owns groups
# and grants, so a user created here has an active account and no access until 04 places them.

import hashlib, secrets, time
from domains import db_config

for username, fullname, email, sso_only in USERS:
    cur.execute("SELECT id FROM modx_users WHERE username=%s", (username,))
    row = cur.fetchone()
    if row:
        print(f'  user exists: {username}')
        continue
    salt = secrets.token_hex(8)
    secret = secrets.token_urlsafe(32) if sso_only else os.environ.get('MODX_ADMIN_PASS') or secrets.token_urlsafe(32)
    password_hash = hashlib.sha512((salt + secret).encode()).hexdigest()
    cur.execute(
        "INSERT INTO modx_users (username, password, salt, active, sudo, createdon) VALUES (%s, %s, %s, 1, 0, %s)",
        (username, password_hash, salt, int(time.time()))
    )
    user_id = cur.lastrowid
    cur.execute(
        "INSERT INTO modx_user_attributes (internalKey, fullname, email, address, comment) VALUES (%s, %s, %s, '', '')",
        (user_id, fullname, email)
    )
    print(f'  created user: {username} (id={user_id}, {email})')

conn.commit()
cur.close()
conn.close()
print('Setup complete.')
