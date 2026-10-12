#!/usr/bin/env python3
"""
domains — the ONE home for the context<->domain fact. The LOGIC lives here; the fact itself
is declared in the estate file (the owner, 2026-08-01: the repo carries no estate).

Every context's http_host / site_url / base_url, for both profiles:

  prod   the public domains nginx forwards (BINDING; applied by 10-production-domains.py)
  bench  the on-box bench hosts           (applied by bench-domains.py)

Before this module the prod map lived in setup/10 and the bench map in bench-domains.py, with
no way to ASSERT either one — so a seed load that skipped setup/10 left prod resolving on bench
hosts and nothing failed. One home, three consumers: the two binders and verify-domains.py.
That has not changed; what changed is that the home is a declaration, not a literal.

The seed always carries the BENCH hosts by design. Whoever loads it must bind the target profile
immediately — which is why loading goes through setup/load-seed.sh, where the bind and the verify
are not separate steps a human can skip.
"""
import os

from estate import DOMAINS

# Every context this estate serves. A context may be absent on an older pull — consumers skip
# contexts that do not exist in modx_context rather than creating them.
CONTEXTS = list(DOMAINS.get('contexts') or [])

# PRODUCTION binding: context -> (public host, site_url scheme). BINDING in the strict sense —
# this is the authority; live prod is expected to match it exactly.
#   http_host  is the context's own host setting; the context itself comes from the edge's
#              X-Tenant header, read by EdgeContext.php.
#   site_url   drives absolute-link generation, so it carries the PUBLIC scheme (https at the
#              edge; MODx still speaks http internally).
PROD = {ctx: tuple(v) for ctx, v in (DOMAINS.get('prod') or {}).items()}

# BENCH binding: each context's *.<bench zone> host.
# The zone is declared, not hardcoded — a bench is a property of whoever runs one. So is the
# SCHEME, and for the same reason: `http` was written here when a bench meant
# a directory on somebody's workstation. The bench is now MODx staging on its own host, behind
# nginx with four estate-CA certificates, and an estate had no way to say so.
#
# WHAT THE HARDCODE ACTUALLY DID — measured 2026-08-13, all four contexts:
#     http://<ctx>.modx.<zone>/   301 -> https://<ctx>.modx.<zone>/
#     https://<ctx>.modx.<zone>/  302 -> http://<ctx>.modx.<zone>/sso-login     <- MODx's own link
# The edge catches it and 301s back, so nothing is visibly broken and every absolute URL MODx
# generates is still the wrong scheme. That is the shape that survives: a defect whose only
# symptom is an extra redirect nobody counts.
#
# Default stays 'http' so an estate that does not declare it moves nowhere.
_BENCH_ZONE = DOMAINS.get('bench_zone')
_BENCH_SCHEME = DOMAINS.get('bench_scheme') or 'http'
if _BENCH_SCHEME not in ('http', 'https'):
    raise SystemExit(f"domains: bench_scheme must be 'http' or 'https', not {_BENCH_SCHEME!r}. "
                     f"It is written into every context's site_url, so a typo here is a site that "
                     f"generates unreachable absolute links rather than an error.")
BENCH = ({ctx: (f'{ctx}.{_BENCH_ZONE}', _BENCH_SCHEME) for ctx in CONTEXTS} if _BENCH_ZONE else {})

BENCH_ZONE = _BENCH_ZONE

# ── A DOOR THAT NEEDS NO DNS NAME AND NO CERTIFICATE ──────────────────────────────────────────
# The owner, 2026-08-19: "can i see it without deploying?", and then, of the bench: "how do i get to
# see your local modx?" The answer was three steps — a hosts line on his machine, the estate root
# installed in his browser, and a name that then stopped reaching staging for him. That is not a
# reviewing surface, it is a procedure.
#
# A port door resolves its context the way production does: `setup/bench-server.php` sends
# `BENCH_TENANT` as HTTP_X_TENANT and EdgeContext.php reads it — no rewrite, no path parsing,
# no base_url change, and the four host vhosts keep working beside it. I proposed path routing first
# (`/<context>/<alias>`) and withdrew it: it makes the bench resolve differently from production,
# and the bench's whole job is to be the place a host/base_url fault shows up before it ships.
#
# WHAT THIS DOOR DOES NOT GIVE YOU, stated here rather than discovered in a browser: MODx still
# generates ABSOLUTE links from `site_url`, which is the bench HOSTNAME — deliberately, because
# changing it would move the context settings the host door depends on. So the page renders and
# relative links work; anything absolute points at a name the visitor may not resolve. It is a
# door for reading a page, not for walking the site.
#
# Declared, never derived: a port is a number somebody allocated from the estate's registry, and
# guessing one is how the estate got its collisions (config/scripts/port-allocate.py exists
# because a hand-read counter produced one). Absent declaration = no extra keys and nothing
# changes anywhere.
BENCH_PORTS = {ctx: int(p) for ctx, p in (DOMAINS.get('bench_ports') or {}).items()
               if ctx in CONTEXTS}


def bench_port_hosts():
    """context -> 'localhost:<port>', for the contexts that declare a port. Empty if none do."""
    return {ctx: f'localhost:{port}' for ctx, port in sorted(BENCH_PORTS.items())}


# ── THE LOCAL PROFILE: a third instance, on a developer's own box ─────────────────────────────
# The owner's ruling, and it is older than this code: THERE MUST BE A DEVELOPMENT BOX. What changed
# over time was only an agent's answer to "which ground is it" — first staging, then not staging,
# and the ruling was left with nothing filling it. This profile fills it.
#
# It is NOT a new binding. `http_host` is byte-identical to the bench's, and that is forced from
# three directions, none of which this profile may weaken:
#   * 12-sso.py refuses to point a context at the estate OIDC provider unless http_host is in
#     the internal zone, and SsoConfig.php restates the refusal at request time;
#   * regenerate-seed.sh refuses to build a seed from a ground whose http_host is outside the
#     bench zone — the guard that proves a seed did not come from production;
#   * the port door and the vhost reach the same context by the same X-Tenant key, so
#     nothing about a context's settings has to change to be reached either way.
# The ONE field that differs is `site_url`, because site_url means "the address this site is
# reached at", and on a box with no vhosts that address is the port door. Everything absolute —
# links, the sign-in redirect, the OIDC callback — follows it.
#
# Derived from `bench_ports`, so nothing new is declared: one fact, already owned by the estate
# file and already committed to the port registry, with a second derivation hanging off it. A
# context that declares no port has no local instance and none is invented for it.
_LOCAL_SCHEME = DOMAINS.get('local_scheme') or 'http'
if _LOCAL_SCHEME not in ('http', 'https'):
    raise SystemExit(f"domains: local_scheme must be 'http' or 'https', not {_LOCAL_SCHEME!r}. "
                     f"It is written into every local context's site_url, so a typo here is an "
                     f"instance that generates unreachable absolute links rather than an error.")
LOCAL = ({ctx: (f'{ctx}.{_BENCH_ZONE}', _LOCAL_SCHEME) for ctx in BENCH_PORTS}
         if _BENCH_ZONE else {})

# The identity provider the LOCAL instance signs in against. It is an estate fact rather than a
# ground fact: the port comes from the estate's own registry, so the string is identical on every
# dev box, which is what lets the whole instance come up from one command instead of a URL
# somebody types. Absent = no local provider declared, and the wiring step says so by name.
LOCAL_OIDC = (DOMAINS.get('local_oidc') or '').strip().rstrip('/')


def local_door_hosts():
    """The Host forms a LOCAL port door is addressed by. Not context keys: the door's context is
    its BENCH_TENANT.

    Three per context, and each earns its place by a measurement rather than by symmetry:

      localhost:<port>        what a browser sends over the ssh forward. The one that matters.
      127.0.0.1:<port>        what `curl http://127.0.0.1:<port>/` sends when nobody passes -H.
      <ctx>.<zone>:<port>     what tests/lib.sh host_origin() composes when it reaches a ground by
                              --resolve with no DNS. The suites are the second consumer of this
                              instance and they must not need a special case to run against it.
    """
    out = {}
    for ctx, port in sorted(BENCH_PORTS.items()):
        out[f'localhost:{port}'] = ctx
        out[f'127.0.0.1:{port}'] = ctx
        if _BENCH_ZONE:
            out[f'{ctx}.{_BENCH_ZONE}:{port}'] = ctx
    return out


def contexts_on_the_bench_zone(live_hosts):
    """Contexts whose LIVE http_host sits in this estate's declared bench zone.

    Exists because one question has a forced answer and was being asked as a decision. The rule says
    internal-zone names use the estate OIDC provider and public names use Google, and setup/12-sso.py
    requires MODX_SSO_OIDC_CONTEXTS to be named by hand — correctly, since 'all of them' would
    move production's identity provider. But on a ground whose hosts are in the bench zone there
    is nothing to decide: Google refuses to issue a callback to a private name, so a context
    served there CANNOT sign anyone in through Google (measured 2026-08-14 —
    the claude bench context 302'd to accounts.google.com with an internal-zone redirect_uri, which can only end
    on Google's error page).

    Derived from the LIVE hosts rather than from the declaration, because what matters is where
    the context actually answers on this ground. An estate that declares no bench zone gets an
    empty list and 12-sso.py's refusal stands unchanged — production is never in a bench zone, so
    this can never quietly move it.
    """
    if not _BENCH_ZONE:
        return []
    suffix = '.' + _BENCH_ZONE.lstrip('.')
    return sorted(ctx for ctx, host in (live_hosts or {}).items()
                  if isinstance(host, str) and host.endswith(suffix))


PROFILES = {'prod': PROD, 'bench': BENCH, 'local': LOCAL}

# WHICH PROFILE'S site_url IS NOT ITS http_host. Empty for every profile but `local`, which is the
# whole safety of this table: `auth.get(ctx, host)` falls back to the host for prod and bench, so
# their expected settings render byte-identically to before this existed.
_SITE_AUTHORITY = {'local': bench_port_hosts}


def binding(profile):
    """The context -> (host, scheme) map for 'prod', 'bench' or 'local'. Unknown profile is fatal."""
    try:
        chosen = PROFILES[profile]
    except KeyError:
        raise SystemExit(f"domains: unknown profile '{profile}' (expected: {', '.join(PROFILES)})")
    if not chosen:
        raise SystemExit(
            f"domains: profile '{profile}' is empty — the estate file declares no {profile} "
            f"binding. Binding nothing would leave every context resolving nowhere and report "
            f"success, so this refuses instead."
        )
    return chosen


def expected_settings(profile):
    """context -> {setting key: expected value} — what modx_context_setting must hold.

    `http_host` is the context's host setting and `site_url` is the ADDRESS. On production and on the
    bench they name the same authority, which is why one string used to serve both. On the local
    instance they do not, and the difference is the point of that profile — see LOCAL above.
    """
    auth = _SITE_AUTHORITY.get(profile, lambda: {})()
    return {ctx: {'http_host': host,
                  'site_url':  f'{scheme}://{auth.get(ctx, host)}/',
                  'base_url':  '/'}
            for ctx, (host, scheme) in binding(profile).items()}


def _socket_kwargs():
    """`{'unix_socket': path}` when MODX_DB_SOCKET names one, else `{}`.

    THE ONE DIFFERENCE BETWEEN A DEV BOX AND EVERY OTHER GROUND, and it is not a preference.
    dev-box.yml grants `skunkworks`@localhost on `modx`.* through MariaDB's unix_socket plugin,
    which decides who you are from the OS user on the far end of the socket and carries no
    secret at all. The `mysql` CLI finds that socket from host=localhost by itself. **pymysql does
    not** — it reads localhost as TCP 127.0.0.1, where the plugin cannot see the caller, and the
    answer is `(1698, "Access denied for user 'skunkworks'@'localhost'")`.

    So every python consumer of db_config() fails on a box using the grant the estate itself
    issued, and fails with an error that says ACCESS when the fault is ROUTE — which sends the
    reader to check privileges that are already correct. `bench-domains.py` died exactly there on
    the first build of the devbox-fl bench.

    Opt-in and additive: unset, this returns `{}` and no existing ground moves by a byte. It
    chooses the transport only — MODX_DB_PASS is still required above and still travels, so a
    ground that authenticates by secret over a socket keeps working.
    """
    path = os.environ.get('MODX_DB_SOCKET')
    return {'unix_socket': path} if path else {}


def db_config():
    """
    MODx DB connection kwargs, from the environment. NO DEFAULTS for the credentials.

    They used to be a user and password, inline, in this function and repeated through ten scripts —
    a working password in source, in a repository about to be published. A default credential also
    decides what silence means: an unset MODX_DB_PASS would silently try somebody's dev password
    against whatever database it could reach. Refusing names the missing variable instead.
    """
    # SOCKET AUTHENTICATION NEEDS NO CREDENTIAL, AND DEMANDING ONE LOCKED EVERYBODY OUT.
    #
    # On a dev box the bench's MariaDB accounts use the **unix_socket** plugin: the OS user at the
    # far end of the socket IS the identity and there is no secret at all. The guard below demanded
    # MODX_DB_USER and MODX_DB_PASS anyway, so every caller had to supply a name and a password —
    # and `ops.sh restore_modx_dev` supplied them from the bench's own config.inc.php, which names
    # whoever installed the bench. Measured 2026-09-12 running it as `infra`:
    #
    #     pymysql.err.OperationalError: (1698, "Access denied for user 'skunkworks'@'localhost'")
    #     FAILED: bench-domains.py failed — bench left on prod domains, run it by hand
    #
    # It failed for every caller including skunkworks, because a password login against a
    # unix_socket account is refused whoever makes it. The owner, 2026-09-12: *"you have to fix
    # restore_modx_dev to work for everyone. no? othewrise nobody can edit modx."* ops.sh was fixed
    # the same evening; this is the other half — a suite or script that calls db_config() DIRECTLY
    # hits the same wall and gets the same misleading "Access denied", which sends the reader to
    # check privileges that are already correct.
    #
    # THE GUARD IS NOT WEAKENED, and that matters because of what it is for: a default credential
    # turns an unset variable into a silent connection attempt with somebody's dev password. There
    # is still NO default credential here. What is allowed is the case where no credential exists
    # to default to — MODX_DB_SOCKET names a socket, so the server authenticates by the OS user and
    # never reads a password. The user still defaults to nobody's guess: it is this process's own
    # OS user, which is the only identity the server will accept over that socket anyway.
    sock = os.environ.get('MODX_DB_SOCKET')
    if sock and not os.environ.get('MODX_DB_USER'):
        import getpass
        return dict(host=os.environ.get('MODX_DB_HOST', 'localhost'),
                    user=getpass.getuser(),
                    password='',
                    database=os.environ.get('MODX_DB_NAME', 'modx'),
                    charset='utf8mb4',
                    unix_socket=sock)
    missing = [k for k in ('MODX_DB_USER', 'MODX_DB_PASS') if not os.environ.get(k)]
    if missing:
        raise SystemExit(
            f"domains: {', '.join(missing)} not set. There is no default — a database credential "
            f"in source is a published credential, and a default one turns an unset variable into "
            f"a silent connection attempt. (If this database authenticates by unix_socket, set "
            f"MODX_DB_SOCKET instead and leave both of these unset: the OS user is the identity "
            f"and there is no password to supply.)"
        )
    return dict(host=os.environ.get('MODX_DB_HOST', 'localhost'),
                user=os.environ['MODX_DB_USER'],
                password=os.environ['MODX_DB_PASS'],
                database=os.environ.get('MODX_DB_NAME', 'modx'),
                charset='utf8mb4',
                **_socket_kwargs())


def live_settings(cur, contexts=None):
    """Read the live http_host/site_url/base_url per context. Missing keys simply do not appear."""
    cur.execute("SELECT context_key, `key`, value FROM modx_context_setting "
                "WHERE `key` IN ('http_host','site_url','base_url')")
    live = {}
    for ctx, key, value in cur.fetchall():
        if contexts is None or ctx in contexts:
            live.setdefault(ctx, {})[key] = value
    return live


def context_exists(cur, ctx):
    cur.execute("SELECT 1 FROM modx_context WHERE `key`=%s", (ctx,))
    return cur.fetchone() is not None


def drift(cur, profile):
    """
    Compare live context settings against the profile's expectation.

    Returns a list of (context, key, expected, actual) — empty means the DB matches. Contexts
    absent from modx_context are not drift (an older seed predates a newer context); a context that
    EXISTS but is missing the setting is drift, reported with actual=None.
    """
    expected = expected_settings(profile)
    present = {ctx: vals for ctx, vals in expected.items() if context_exists(cur, ctx)}
    live = live_settings(cur, set(present))
    out = []
    for ctx in sorted(present):
        for key in ('http_host', 'site_url', 'base_url'):
            want = present[ctx][key]
            got = live.get(ctx, {}).get(key)
            if got != want:
                out.append((ctx, key, want, got))
    return out
