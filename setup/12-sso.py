#!/usr/bin/env python3
"""
12 — Google SSO wiring.

Creates the `sso.*` system settings and, per SSO-gated context, four public resources:
  - the login entry  (runs [[!GoogleSSOStart]])    -> branded sign-in screen -> the provider
  - the callback     (runs [[!GoogleSSOCallback]]) -> completes login, establishes the session
  - the logout entry (runs [[!GoogleSSOLogout]])   -> tears down the web session
  - the refusal page (runs [[!unauthorizedNotice]])-> says WHY a page was refused, to whom
and repoints login_page at the login entry, logout_page at the logout entry, and unauthorized_page
at the refusal page — the full SSO-only cutover (the Login package's form is retired). A blocked
anonymous visitor still reaches the branded sign-in; a blocked SIGNED-IN one now gets told that
their account is not a member, which is the sentence that ends the loop.

The snippets themselves live in common/snippets/ and are deployed by import.py (bench) /
apply-modx-elements.sh (prod). This script is the wiring (settings + resources + context
settings). Idempotent — safe to re-run.

SECRETS ARE NEVER HARD-CODED. client id/secret come from the environment; if unset, the existing
setting value is left untouched (so a re-run never blanks a configured secret):
  MODX_SSO_CLIENT_ID       — the shared project_sso web-client id   (Vaultwarden)
  MODX_SSO_CLIENT_SECRET   — the shared project_sso web-client secret
WHO MAY SIGN IN is not set here: it is the MODx user and its membership, and nothing else. The
  sso.allowed_emails / sso.allowed_domains list is retired; setup/02 deletes its rows.
Deploy knobs (env, with defaults):
  MODX_SSO_AUTO_PROVISION  — '1' to create a MODx user on first valid login, else unknown = denied (default 0)
  MODX_SSO_CONTEXTS        — comma-separated contexts to gate (default: family,corporate,worldview)
  MODX_SSO_CALLBACK_PATH   — url path of the callback (default: sso-callback)
  MODX_SSO_LOGIN_PATH      — url path of the login entry (default: sso-login)
  MODX_SSO_DENIED_PATH     — url path of the refusal page (default: sso-unauthorized)
  MODX_SSO_REPOINT         — '1' (default) to repoint login_page/unauthorized_page/logout_page

Run on the bench with the DB env (MODX_DB_*) as the other setup scripts. Clear cache afterwards.
"""
import os
import re
import pymysql

# One home for "which container is exempt from page protection" — setup/04 reads the same constant
# and refuses to protect a context that has no such container (it would lock the door from inside).
import settings_guard
from access_model import SUPPORT_ALIAS
import domains
from domains import db_config
from estate import PROTECTED_CONTEXTS, SYSTEM_SETTINGS
# THE INTERNAL ZONE IS THE ESTATE'S DECLARATION (estate.json system_settings estate.internal_zone,
# which setup/02 writes as the MODx setting SsoConfig reads) — never a suffix typed here. No zone,
# no internal name: every guard below then refuses, closed, and says which setting is missing.
_ZONE = str(SYSTEM_SETTINGS.get('estate.internal_zone') or '').strip().lower()
if _ZONE and not _ZONE.startswith('.'):
    _ZONE = '.' + _ZONE
_ZONE_SAID = f"the internal zone ({_ZONE})" if _ZONE else "the internal zone (estate.internal_zone is not declared, so no name is internal)"


def internal(host):
    return bool(_ZONE) and str(host or '').lower().endswith(_ZONE)

DB = db_config()

# The gated set is the PROTECTED set — one declaration, not two. It used to default to the literal
# 'family,corporate,worldview', which was this estate's context list living in the software repo
# (phase 1 of the estate split took the estate out of everything else) and, worse, a second list that could
# disagree with 04's: a context declared protected but absent from this literal gets its pages shut
# by 04 and its login_page left pointing at the retired form, which locks the door from the inside.
# That is exactly what happened to `claude`. The env var still overrides for a one-context run.
CONTEXTS      = [c.strip() for c in os.environ['MODX_SSO_CONTEXTS'].split(',') if c.strip()] \
                if os.environ.get('MODX_SSO_CONTEXTS') else list(PROTECTED_CONTEXTS)
CALLBACK_PATH = os.environ.get('MODX_SSO_CALLBACK_PATH', 'sso-callback').strip('/')
LOGIN_PATH    = os.environ.get('MODX_SSO_LOGIN_PATH', 'sso-login').strip('/')
LOGOUT_PATH   = os.environ.get('MODX_SSO_LOGOUT_PATH', 'sso-logout').strip('/')
# unauthorized_page USED TO BE login_page, and that is the loop. Signed out, sending a
# refused visitor to sign in is right. Signed IN and not a member of the context, it is a bounce
# between two pages that each expect the other to explain, and neither ever does: GoogleSSOStart
# returns a signed-in caller to site_start, site_start is userHome, userHome routes at a protected
# page, and the refusal starts again. A separate resource is what lets the refusal say something.
DENIED_PATH   = os.environ.get('MODX_SSO_DENIED_PATH', 'sso-unauthorized').strip('/')
REPOINT       = os.environ.get('MODX_SSO_REPOINT', '1') == '1'

conn = pymysql.connect(**DB)
cur = conn.cursor()

# Photograph every settings row before this pass touches anything, and
# REFUSE THE COMMIT if a setting that had a value no longer does. Three times on
# 2026-08-12 a pass here emptied settings nobody asked it to touch (the MODX_SSO_OIDC
# set, the outward-facing settings, merged elements) and every one was found by somebody
# stumbling into it rather than by the pass saying anything. A loss rolls the whole pass
# back and exits 1, so the estate is as it was; a removal this pass MEANS to make is
# declared with g.allow(scope, context, key). Drilled in tests/settings-guard.py.
g = settings_guard.start(conn, '12-sso')


def set_setting(key, value, xtype='textfield'):
    """Upsert a system setting. value=None means 'leave existing untouched, create empty if absent'."""
    cur.execute("SELECT value FROM modx_system_settings WHERE `key`=%s", (key,))
    row = cur.fetchone()
    if row:
        if value is not None:
            cur.execute("UPDATE modx_system_settings SET value=%s WHERE `key`=%s", (value, key))
        return
    cur.execute("INSERT INTO modx_system_settings (`key`,value,xtype,namespace,area) "
                "VALUES (%s,%s,%s,'sso','sso')", (key, value if value is not None else '', xtype))


# --- settings ---------------------------------------------------------------------------------
set_setting('sso.google_client_id',     os.environ.get('MODX_SSO_CLIENT_ID'))          # secret-ish; only if provided
set_setting('sso.google_client_secret', os.environ.get('MODX_SSO_CLIENT_SECRET'), 'password')
set_setting('sso.auto_provision',       os.environ.get('MODX_SSO_AUTO_PROVISION', '0'), 'combo-boolean')
# THE GROUP AN AUTO-PROVISIONED USER LANDS IN, and it was a literal naming a group that does not
# exist. `04-access-control.py` RENAMES a legacy `Members` group to `allContextMember` (its
# OLD_NAMES map), so on any estate built by this repo the name written here matches nothing.
# GoogleSSOCallback only logs a WARN when the lookup misses, so the visible symptom is a user who
# signs in successfully and can read nothing — the failure with no error in it.
#
# value=None keeps the contract above: production, where this row already holds 'Members' and no
# env is set, is not touched by this change at all.
_PROVISION_GROUP = os.environ.get('MODX_SSO_PROVISION_USERGROUP')
if _PROVISION_GROUP:
    cur.execute("SELECT name FROM modx_membergroup_names WHERE name=%s", (_PROVISION_GROUP,))
    if not cur.fetchone():
        cur.execute("SELECT name FROM modx_membergroup_names ORDER BY name")
        known = ', '.join(r[0] for r in cur.fetchall()) or '(none)'
        raise SystemExit(
            f"12-sso: MODX_SSO_PROVISION_USERGROUP names '{_PROVISION_GROUP}', which is not a user\n"
            f"        group on this ground. Groups here: {known}.\n"
            f"        Refusing rather than writing it: a provisioned user would join no group, read\n"
            f"        nothing, and the only trace would be a WARN in the MODx log.")
set_setting('sso.provision_usergroup',  _PROVISION_GROUP)
set_setting('sso.callback_path',        CALLBACK_PATH)

# --- the identity provider's endpoints -------------------------------------------
#
# Nothing wrote these until now, and the gap was invisible in the worst way: `GoogleSSOStart` and
# `GoogleSSOCallback` fall back to Google when the settings are absent, so a bench with no endpoint
# settings SILENTLY signs in against real Google. After every `restore_modx_dev` that is the state,
# and the two suites that drive a real sign-in (`sso-verified-email.sh`, `auth-end-to-end.sh`)
# refuse to run rather than authenticate a real person with no password — so the estate's only
# end-to-end proof of its own login has been unrunnable since the last restore, quietly.
#
# ONE variable, not three. `MODX_SSO_IDP` is the provider's base URL and the three settings are read
# out of its `/.well-known/openid-configuration`. Three hand-set variables could disagree with each
# other and with the provider, which is the defect class this whole ticket has been unwinding; a
# discovery document cannot be internally inconsistent. Unset = leave them alone (production).
#
# THE GUARD IS THE SAME ONE THE SNIPPET USES, restated here because a writer that can point
# production at a mock is more dangerous than the gap it closes: `GoogleSSOCallback` honours these
# settings ONLY when `http_host` is in the internal zone, and does NOT verify the id_token signature,
# trusting instead that the token came over TLS from Google's own endpoint. So this refuses to write
# them at all unless every gated context's http_host is an internal-zone host. On production it is a no-op
# that says so.
IDP = os.environ.get('MODX_SSO_IDP', '').strip().rstrip('/')
if IDP:
    import json as _json
    import urllib.request as _url

    cur.execute("SELECT context_key, value FROM modx_context_setting WHERE `key`='http_host' "
                "AND context_key IN (" + ','.join(['%s'] * len(CONTEXTS)) + ")", tuple(CONTEXTS)) \
        if CONTEXTS else None
    hosts = dict(cur.fetchall()) if CONTEXTS else {}
    public = {c: h for c, h in hosts.items() if not internal(h)}
    if public:
        raise SystemExit(
            f"12-sso: REFUSING to write IdP endpoints — {', '.join(f'{c}={h}' for c, h in public.items())} "
            f"is not in {_ZONE_SAID}.\n"
            f"        GoogleSSOCallback honours sso.auth_endpoint/token_endpoint/issuer only on an\n"
            f"        internal host and does not verify the id_token signature — it trusts that the\n"
            f"        token came from Google over TLS. Writing these against a public host would\n"
            f"        make any address typed at the provider a verified identity on that site."
        )
    try:
        with _url.urlopen(f'{IDP}/.well-known/openid-configuration', timeout=10) as fh:
            disco = _json.load(fh)
        auth, token, issuer = (disco['authorization_endpoint'], disco['token_endpoint'],
                               disco['issuer'])
    except Exception as exc:                                    # noqa: BLE001 — any failure is fatal
        raise SystemExit(
            f"12-sso: MODX_SSO_IDP={IDP} did not yield a usable discovery document ({exc}).\n"
            f"        Refusing rather than writing a guess: an endpoint that does not answer leaves\n"
            f"        sign-in falling back to real Google, which is exactly the silent state this\n"
            f"        exists to end."
        )
    set_setting('sso.auth_endpoint',  auth)
    set_setting('sso.token_endpoint', token)
    set_setting('sso.issuer',         issuer)
    print(f'  IdP endpoints written from {IDP}/.well-known/openid-configuration (issuer={issuer})')
else:
    print('  IdP endpoints untouched (MODX_SSO_IDP unset) — sign-in uses Google')
print(f'Settings ensured (contexts={CONTEXTS}, callback=/{CALLBACK_PATH}, login=/{LOGIN_PATH}, repoint={REPOINT}).')


def support_id(ctx):
    """The context's top-level `support` container — the parent every auth page must sit under.

    Found by the SAME rule setup/04 uses, deliberately: 04 exempts `support` and its children from
    page protection and protects everything else. A sign-in page created outside that container is
    therefore protected by 04, and an anonymous request loops
    401 -> unauthorized_page (this very page) -> 401 … until PHP exhausts its memory limit and the
    server answers 500. Nobody can ever sign in.

    That is not hypothetical: this function exists because a from-nothing install did exactly that
    on 2026-07-28. Before it, the INSERT below hardcoded `parent=0`. The live estate was
    never affected — its auth pages were placed under `support` by the migration, not by this
    script, which is why this went unseen for as long as it did.
    """
    cur.execute("SELECT id FROM modx_site_content WHERE context_key=%s AND alias=%s "
                "AND parent=0 AND deleted=0", (ctx, SUPPORT_ALIAS))
    row = cur.fetchone()
    if not row:
        raise SystemExit(f'{ctx}: no top-level `{SUPPORT_ALIAS}` container — refusing to create the '
                         f'SSO pages, because setup/04 would then protect the sign-in page itself '
                         f'and no anonymous visitor could ever log in. Run 03-resources-skeleton.py '
                         f'first.')
    return row[0]


def ensure_resource(ctx, path, snippet, pagetitle):
    """Create-or-update a public, uncacheable, uri-pinned resource running `snippet` in `ctx`.
    Returns the resource id.

    NOTE on re-runs: an existing page is matched by uri and its `parent` is deliberately NOT
    rewritten. Pages already in the right place stay put, and a page someone has deliberately moved
    is not dragged back. Only newly created pages get the `support` parent.
    """
    content = f'[[!{snippet}]]'
    cur.execute("SELECT id FROM modx_site_content WHERE context_key=%s AND uri=%s", (ctx, path))
    row = cur.fetchone()
    if row:
        rid = row[0]
        cur.execute("UPDATE modx_site_content SET content=%s, cacheable=0, published=1, deleted=0, "
                    "uri_override=1, uri=%s, alias=%s, hidemenu=1, searchable=0 WHERE id=%s",
                    (content, path, path, rid))
        print(f'  {ctx}: updated {path} (id={rid})')
        return rid
    cur.execute(
        "INSERT INTO modx_site_content "
        "(type, pagetitle, longtitle, menutitle, description, alias, uri, uri_override, "
        " template, content_type, parent, context_key, published, hidemenu, menuindex, "
        " searchable, cacheable, richtext, createdby, createdon, publishedon, publishedby, content) "
        "VALUES ('document',%s,'','','',%s,%s,1, 0,1, %s,%s, 1,1,0, 0,0,0, 1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),1, %s)",
        (pagetitle, path, path, support_id(ctx), ctx, content))
    rid = cur.lastrowid
    print(f'  {ctx}: created {path} (id={rid}) under `{SUPPORT_ALIAS}`')
    return rid


def set_ctx(ctx, key, value):
    cur.execute("SELECT 1 FROM modx_context_setting WHERE context_key=%s AND `key`=%s", (ctx, key))
    if cur.fetchone():
        cur.execute("UPDATE modx_context_setting SET value=%s WHERE context_key=%s AND `key`=%s", (value, ctx, key))
    else:
        cur.execute("INSERT INTO modx_context_setting (context_key,`key`,value,xtype) VALUES (%s,%s,%s,'textfield')",
                    (ctx, key, value))


# ── The estate OIDC provider, PER CONTEXT ───────────────────────────────────────────────
# The owner's rule, 2026-08-11: names in the internal zone authenticate against the estate's OIDC provider;
# public names authenticate against Google. Not a preference — Google will not accept a redirect URI
# on a name it cannot resolve publicly, so the move of the claude context to an internal-zone name
# left it serving pages and unable to sign anybody in.
#
# WRITTEN AS CONTEXT SETTINGS, NEVER SYSTEM SETTINGS, and that is the whole reason the earlier
# attempt was abandoned: one MODx serves four contexts, `sso.auth_endpoint` and friends are
# per-install, and pointing the install at another provider would have taken `family` and
# `corporate` with it. `$modx->getOption()` resolves a context setting over the system value, so
# per-context is the mechanism; what was missing was a client id and secret that are not Google's.
#
# MODX_SSO_OIDC=<issuer base>  — read its discovery document and write the endpoints, exactly as the
#                                bench block above does, because a discovery document cannot be
#                                internally inconsistent while three hand-typed URLs can.
# MODX_SSO_OIDC_CONTEXTS       — comma-separated contexts to point at it. Required: this is an
#                                identity change and "all of them" must never be the default.
# MODX_SSO_OIDC_CLIENT_<CTX>   — that context's client id at the provider (default: modx-<ctx>)
# MODX_SSO_OIDC_SECRET_<CTX>   — that context's client secret. Required per context.
#   <CTX> is the context key upper-cased with every character outside A-Z0-9 as `_`:
#   family-alice -> FAMILY_ALICE. A context key may carry a hyphen since the estate keys,
#   and `MODX_SSO_OIDC_SECRET_FAMILY-ALICE` is a name no shell can export, so the first restore
#   after the rename refused on a secret nobody could have handed it (2026-09-22).
OIDC = os.environ.get('MODX_SSO_OIDC', '').strip().rstrip('/')
if OIDC:
    import json as _json2
    import urllib.request as _url2

    targets = [c.strip() for c in os.environ.get('MODX_SSO_OIDC_CONTEXTS', '').split(',') if c.strip()]
    if not targets:
        # DERIVED WHERE THE ANSWER IS FORCED, still refused where it is a decision.
        #
        # The refusal below is right for production: naming which contexts change identity
        # provider must never default to 'all of them'. But on a ground whose contexts answer in
        # the declared BENCH ZONE there is nothing to decide — Google will not issue a callback to
        # a private name, so an internal-zone context cannot sign anyone in through Google at all. That is
        # not a policy, it is Google's rule, and this ground has been sitting in that state:
        # the claude bench context 302'd to accounts.google.com with an internal-zone redirect_uri (the owner, and
        # measured, 2026-08-14).
        #
        # Production is never in a bench zone, so this cannot quietly move it; an estate that
        # declares no bench zone gets nothing and the refusal stands exactly as it did.
        cur.execute("SELECT context_key, value FROM modx_context_setting WHERE `key`='http_host'")
        derived = domains.contexts_on_the_bench_zone(dict(cur.fetchall()))
        if derived:
            targets = derived
            print(f'  MODX_SSO_OIDC_CONTEXTS not set — derived from the bench zone '
                  f'({domains.BENCH_ZONE}): {", ".join(targets)}')
    if not targets:
        raise SystemExit(
            "12-sso: MODX_SSO_OIDC is set but MODX_SSO_OIDC_CONTEXTS is empty, and no context on\n"
            "        this ground answers in a declared bench zone.\n"
            "        Which contexts change identity provider is a decision, and there is no safe\n"
            "        default for it — 'all of them' would move production."
        )
    unknown = [c for c in targets if c not in CONTEXTS]
    if unknown:
        raise SystemExit(f"12-sso: MODX_SSO_OIDC_CONTEXTS names contexts this run does not gate: {unknown}")

    cur.execute("SELECT context_key, value FROM modx_context_setting WHERE `key`='http_host' "
                "AND context_key IN (" + ','.join(['%s'] * len(targets)) + ")", tuple(targets))
    thosts = dict(cur.fetchall())
    # THE SAME GUARD THE SNIPPET USES, restated because a writer that can point a public site at an
    # internal identity provider is more dangerous than the gap it closes. SsoConfig REFUSES
    # sso.provider=oidc on a public host at request time; this refuses to create the state at all.
    bad = {c: thosts.get(c, '<no http_host>') for c in targets
           if not internal(thosts.get(c, ''))}
    if bad:
        raise SystemExit(
            "12-sso: REFUSING to point a PUBLIC context at the estate OIDC provider — "
            + ', '.join(f'{c}={h}' for c, h in bad.items()) + ".\n"
            f"        The rule: names in {_ZONE_SAID} use the estate provider, public\n"
            "        names use Google. And the callback does not verify the id_token signature, so\n"
            "        on a public host any address typed at that provider would become a verified\n"
            "        identity on that site."
        )
    try:
        with _url2.urlopen(f'{OIDC}/.well-known/openid-configuration', timeout=10) as fh:
            d2 = _json2.load(fh)
        o_auth, o_token, o_iss = d2['authorization_endpoint'], d2['token_endpoint'], d2['issuer']
        o_ui = d2.get('userinfo_endpoint', '')
        # The signature is verified on this path, so a provider that publishes no keys
        # cannot be used. Refuse here rather than write a context that will refuse at sign-in.
        o_jwks = d2.get('jwks_uri', '')
        if not o_jwks:
            raise SystemExit(
                f"12-sso: {OIDC} publishes no jwks_uri in its discovery document.\n"
                f"        The oidc path verifies the id_token signature against those keys,\n"
                f"        so a provider without them cannot sign anyone in here."
            )
    except Exception as exc:                                    # noqa: BLE001 — any failure is fatal
        raise SystemExit(
            f"12-sso: MODX_SSO_OIDC={OIDC} did not yield a usable discovery document ({exc}).\n"
            f"        Refusing rather than writing a guess: a context whose endpoints are wrong\n"
            f"        falls back to nothing and reports 'sign-in is not configured'."
        )
    for c in targets:
        ev = re.sub(r'[^A-Z0-9]', '_', c.upper())
        secret = os.environ.get(f'MODX_SSO_OIDC_SECRET_{ev}', '').strip()
        if not secret:
            raise SystemExit(
                f"12-sso: no MODX_SSO_OIDC_SECRET_{ev} for context '{c}'.\n"
                f"        Each context is its own client at the provider; there is no shared secret\n"
                f"        to fall back to, and inventing one would make every internal context the\n"
                f"        same client."
            )
        cid = os.environ.get(f'MODX_SSO_OIDC_CLIENT_{ev}', f'modx-{c}').strip()
        set_ctx(c, 'sso.provider', 'oidc')
        set_ctx(c, 'sso.oidc_issuer', o_iss)
        set_ctx(c, 'sso.oidc_auth_endpoint', o_auth)
        set_ctx(c, 'sso.oidc_token_endpoint', o_token)
        set_ctx(c, 'sso.oidc_userinfo_endpoint', o_ui)
        set_ctx(c, 'sso.oidc_jwks_uri', o_jwks)
        set_ctx(c, 'sso.oidc_client_id', cid)
        set_ctx(c, 'sso.oidc_client_secret', secret)
        print(f'  {c}: sso.provider=oidc, client={cid}, issuer={o_iss} '
              f'(host={thosts.get(c)}) — Google untouched for every other context')
else:
    print('  sso.provider untouched (MODX_SSO_OIDC unset) — every context signs in against Google')

# --- per-context resources + wiring -----------------------------------------------------------
for ctx in CONTEXTS:
    cur.execute("SELECT 1 FROM modx_context WHERE `key`=%s", (ctx,))
    if not cur.fetchone():
        print(f'  {ctx}: context absent, skipped')
        continue
    ensure_resource(ctx, CALLBACK_PATH, 'GoogleSSOCallback', 'SSO callback')
    login_id  = ensure_resource(ctx, LOGIN_PATH,  'GoogleSSOStart',  'Sign in')
    logout_id = ensure_resource(ctx, LOGOUT_PATH, 'GoogleSSOLogout', 'Sign out')
    denied_id = ensure_resource(ctx, DENIED_PATH, 'unauthorizedNotice', 'Access refused')
    if REPOINT:
        set_ctx(ctx, 'login_page', str(login_id))
        set_ctx(ctx, 'unauthorized_page', str(denied_id))
        set_ctx(ctx, 'logout_page', str(logout_id))
        print(f'  {ctx}: login_page -> {login_id}, unauthorized_page -> {denied_id}, '
              f'logout_page -> {logout_id}')

g.commit()
cur.close()
conn.close()
print('SSO wiring complete. Clear the MODx cache (php clear-cache.php) after this.')
