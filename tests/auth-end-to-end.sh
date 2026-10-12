#!/usr/bin/env bash
# auth-end-to-end.sh — a real page, a real sign-in, and the token read off the wire.
#
#     bash tests/auth-end-to-end.sh
#
# WHAT THIS ADDS over tests/tableRenderer-auth.php. That test runs the real snippet against a
# stubbed $modx, so it proves the logic and the token. It cannot prove the three MODx calls the
# logic RESTS on — $modx->user->isAuthenticated($ctx), $modx->user->getOne('Profile'), and
# $_SESSION surviving into snippet scope — because a stub answers those the way I expect rather
# than the way MODx does. Getting any of them wrong shows up as a permanently blank table, which is
# also the correct output for "nobody is signed in": the failure is indistinguishable from success
# by looking at the page. So it is measured here instead, on the bench, end to end:
#
#   anonymous  -> the page renders and NOTHING is requested of the audience   (the owner's "display blank")
#   signed in  -> a Bearer arrives, and kuber's own jwtVerify() returns that person's address
#
# BENCH ONLY. It writes a throwaway signing key into system settings, creates one page, and removes
# both. It refuses to run unless sign-in points at mock-oidc, because it signs in as a real person
# without their password.
set -uo pipefail

HOST=${MODX_TEST_HOST:-}   # empty: the context's live http_host, read after lib.sh
IP=${MODX_TEST_IP:-127.0.0.1}
CTX=${MODX_TEST_CTX:-$(python3 "$(dirname "${BASH_SOURCE[0]}")/../setup/estate-get.py" domains.contexts | head -1)}   # default: the estate's first context
[ -n "$CTX" ] || { echo "REFUSING TO RUN: no MODX_TEST_CTX and no context in the estate MODX_ESTATE names" >&2; exit 2; }
REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
CRYPTO=${KUBER_CRYPTO:-$HOME/gitea/work-products/kuber/php/Core/Crypto.php}
ALIAS=tablerenderer-auth-e2e

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
[ -n "$HOST" ] || HOST=$(ctx_host "$CTX") || exit 2

# The scheme and port are DECLARED, not assumed — see lib.sh origin_of(). This REFUSES if the
# ground cannot tell it which, rather than speaking http at an https edge and reporting the 301
# as a failure of the page.
read -r SCHEME PORT < <(host_origin "$HOST")
DOOR=$(host_door "$HOST") || exit 2

require_repo
require_bench
require_mock_idp

echo "tableRenderer &auth — end to end on the bench"

if [ ! -f "$CRYPTO" ]; then
  echo "REFUSING TO RUN: kuber's verifier is not at $CRYPTO — the token would be checked by a copy" >&2
  echo "of their code rather than by the code that will verify it. Set KUBER_CRYPTO." >&2
  exit 2
fi
reader=$(q "SELECT p.email FROM modx_user_attributes p JOIN modx_users u ON u.id=p.internalKey
            WHERE u.active=1 AND p.email LIKE '%@%' ORDER BY u.id LIMIT 1;")
[ -z "$reader" ] && { echo "REFUSING TO RUN: no active user with an email on this bench." >&2; exit 2; }

tmp=$(mktemp -d); chmod 700 "$tmp"
had_key=$(q "SELECT COUNT(*) FROM modx_system_settings WHERE \`key\`='kuber.jwt_private_key';")
cleanup() {
  q "DELETE FROM modx_site_content WHERE context_key='$CTX' AND alias='$ALIAS';" >/dev/null 2>&1
  # The bench had no such setting before this ran (asserted below), so removing it restores the box.
  [ "$had_key" = 0 ] && q "DELETE FROM modx_system_settings WHERE \`key\`='kuber.jwt_private_key';" >/dev/null 2>&1
  [ -n "${listener:-}" ] && kill "$listener" 2>/dev/null
  modx_php "$REPO/clear-cache.php" >/dev/null 2>&1 || true
  rm -rf "$tmp"
}
trap cleanup EXIT
# A GROUND THAT ALREADY HOLDS A SIGNING KEY IS USED, NOT OVERWRITTEN.
#
# This REFUSED outright where kuber.jwt_private_key existed — right on a dev bench, wrong on
# staging: staging is restored from production, so it always holds the real key and this suite
# could never run on the one ground that matters (infra measured it, 2026-08-14).
#
# What the suite needs is the PUBLIC half matching whatever MODx signs with, and where a key is
# already present that is derivable without writing anything: the private half is read from the
# database and piped straight into `openssl pkey -pubout`, so it never lands on this filesystem
# and never appears on a command line. Nothing is inserted and nothing is deleted — the ground is
# as it started whether this suite passes, fails, or dies half way.
if [ "$had_key" != 0 ]; then
  python3 - "$tmp/pub.pem" <<'KEYPY' || { echo "could not derive the public half of this ground's signing key" >&2; exit 2; }
import getpass, os, subprocess, sys, pymysql
# THE SAME SOCKET PATH lib.sh TAKES, AND ITS ABSENCE HERE COST TWO FALSE CLAIMS (2026-09-12).
# This read `os.environ['MODX_DB_PASS']` — a hard KeyError where the bench account authenticates by
# unix_socket and there IS no password — and defaulted the user to a fixed account name, which is not the OS user
# running it. The block died, the suite printed "could not derive the public half of this ground's
# signing key", and skunkworks reported that to the owner twice as a key infra had not rendered. Nothing
# on this ground was ever missing a key: the suite derives one, or makes a throwaway pair.
sock = os.environ.get('MODX_DB_SOCKET')
kw = (dict(unix_socket=sock, user=os.environ.get('MODX_DB_USER') or getpass.getuser(), password='')
      if sock and not os.environ.get('MODX_DB_PASS')
      else dict(host=os.environ.get('MODX_DB_HOST', 'localhost'),
                user=os.environ.get('MODX_DB_USER') or getpass.getuser(),
                password=os.environ.get('MODX_DB_PASS', '')))
c = pymysql.connect(database=os.environ.get('MODX_DB_NAME', 'modx'), charset='utf8mb4', **kw)
cur = c.cursor()
cur.execute("SELECT value FROM modx_system_settings WHERE `key`='kuber.jwt_private_key' LIMIT 1")
row = cur.fetchone()
if not row or not row[0]:
    raise SystemExit('kuber.jwt_private_key counted but read back empty')
# stdin, not a file and not argv: the private half of a real key does not touch this disk.
r = subprocess.run(['openssl', 'pkey', '-pubout', '-out', sys.argv[1]],
                   input=row[0].encode(), capture_output=True)
if r.returncode != 0:
    raise SystemExit('openssl could not read that key: ' + r.stderr.decode()[:200])
KEYPY
  echo "  (using the signing key this ground already holds — nothing written)"
else
  # --- a throwaway keypair, never on a command line --------------------------------------------
  ( umask 077; openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$tmp/priv.pem" 2>/dev/null )
  openssl pkey -in "$tmp/priv.pem" -pubout -out "$tmp/pub.pem" 2>/dev/null
fi

# --- the audience: a listener that records what it was sent ------------------------------------
port=$(python3 - <<'PY'
import socket
s = socket.socket(); s.bind(('127.0.0.1', 0)); print(s.getsockname()[1]); s.close()
PY
)
cat > "$tmp/router.php" <<'PHP'
<?php
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
file_put_contents(getenv('E2E_LOG'), $auth . "\n", FILE_APPEND);
header('Content-Type: application/json');
echo json_encode(['rows' => [['figure' => 'reached the audience']]]);
PHP
touch "$tmp/requests.log"
# WHERE THE AUDIENCE LISTENS AND WHERE MODX REACHES IT ARE TWO ADDRESSES ON A CONTAINERISED
# GROUND — AND THE SECOND ONE MUST BE A NAME.
#
# Two measurements, one after the other, on the ground's first zero-refusal runs (2026-08-14):
#   * bound 127.0.0.1 and told the page the same: "could not fetch http://127.0.0.1:46653 — Could
#     not connect". MODx is in a container; that loopback is its own.
#   * bound the container's default gateway (172.17.0.1, read from /proc/net/route inside it) and
#     tableRenderer REFUSED: "&auth will not present a bearer credential in clear text ... must be
#     https (plain http is accepted only on loopback or an internal-zone host)". That is my own production
#     guard, behaving exactly as designed, and it is right — a bearer over plain http to a bare IP
#     is the thing it exists to stop.
#
# So the audience is addressed the way the estate addresses everything: by its internal-zone name. The
# runner's own FQDN is used when it is an internal-zone one AND the container can resolve it; the fixture
# binds the interface that name resolves to, not a wildcard. A dev box (no MODX_PHP_RUN) keeps
# loopback, which the guard also allows. Anything else REFUSES rather than quietly weakening the
# rule the guard enforces in production.
BIND=127.0.0.1
REACH=127.0.0.1
if [ -n "${MODX_PHP_RUN:-}" ]; then
  # AN INTERNAL-ZONE NAME THAT RESOLVES TO THIS MACHINE — found, not guessed, and not asked for.
  #
  # The audience needs a name because tableRenderer will not send a bearer over plain http to a
  # bare IP. hanuman answers `hostname -f` without its zone and its resolv.conf search list yields
  # nothing, so I nearly asked infra to declare one. It is already declared: the site's own host
  # names resolve to the machine the suites run on — the bench context's name and the runner's own
  # internal name are both this runner's address. A fixture on a high port of that same interface is
  # reached by the same name the estate already uses, and no ground has to state anything new.
  #
  # The rule, so it holds on any ground: take an internal-zone name that resolves to an address THIS
  # machine holds, and that the container can resolve too. Never compose a zone from a hostname.
  mine=$(ip -o addr show 2>/dev/null | awk '{print $4}' | cut -d/ -f1)
  fqdn=""; zone=$(internal_zone)
  for cand in "$HOST" "$(hostname -f 2>/dev/null)"; do
    under_zone "$cand" "$zone" || continue
    cip=$(getent hosts "$cand" 2>/dev/null | awk '{print $1; exit}')
    [ -n "$cip" ] || continue
    printf '%s\n' $mine | grep -qx "$cip" || continue      # it must be an address I hold
    fqdn="$cand"; break
  done
  if [ -n "$fqdn" ]; then
    # …and the container must be able to resolve it, or the page would name something MODx cannot
    # reach. ARGV, not an env var: manifest-drift caught that shape twice today.
    seen=$(modx_php -r 'echo gethostbyname($argv[1]) === $argv[1] ? "" : "yes";' -- "$fqdn" 2>/dev/null || true)
    [ -n "$seen" ] || fqdn=""
  fi
  if [ -n "$fqdn" ]; then
    REACH=$fqdn
    BIND=$(getent hosts "$fqdn" | awk '{print $1; exit}')
    [ -n "$BIND" ] || BIND=0.0.0.0
  else
    echo "REFUSING TO RUN: MODx runs elsewhere on this ground (MODX_PHP_RUN is set) and this" >&2
    echo "                 machine has no internal-zone name for it to call back on. tableRenderer will" >&2
    echo "                 not send a bearer over plain http to anything but loopback or an internal" >&2
    echo "                 host — correctly — so the audience cannot be reached without one, and" >&2
    echo "                 weakening that guard to run a test is not on the table." >&2
    exit 2
  fi
fi
E2E_LOG="$tmp/requests.log" local_php -S "$BIND:$port" -t "$tmp" "$tmp/router.php" >"$tmp/listener.out" 2>&1 &
listener=$!
for _ in $(seq 40); do (exec 3<>/dev/tcp/127.0.0.1/"$port") 2>/dev/null && break; sleep 0.05; done

# --- wire the bench: the key as a system setting, and one page that reads through it ------------
# The key goes in via a file, not an argument, so it never appears in a process list or a history.
if [ "$had_key" = 0 ]; then          # only where we generated the key; a real one is never written over
python3 - "$tmp/priv.pem" <<'PY'
import getpass, os, sys, pymysql
key = open(sys.argv[1]).read()
c = pymysql.connect(host=os.environ.get('MODX_DB_HOST', 'localhost'),
                    user=os.environ.get('MODX_DB_USER') or getpass.getuser(),
                    password=os.environ['MODX_DB_PASS'],
                    database=os.environ.get('MODX_DB_NAME', 'modx'), charset='utf8mb4')
cur = c.cursor()
cur.execute("INSERT INTO modx_system_settings (`key`,value,xtype,namespace,area) "
            "VALUES ('kuber.jwt_private_key',%s,'password','kuber','kuber')", (key,))
c.commit()
# Prove the key is there and whole before anything depends on it. An INSERT whose result is never
# read is how "the setting is empty" becomes a mystery instead of an error.
cur.execute("SELECT LENGTH(value) FROM modx_system_settings WHERE `key`='kuber.jwt_private_key'")
row = cur.fetchone()
if not row or row[0] != len(key):
    raise SystemExit(f'the signing key did not land intact: stored {row and row[0]} of {len(key)} bytes')
PY
[ $? = 0 ] || { echo "could not place the throwaway signing key" >&2; exit 2; }
fi
q "INSERT INTO modx_site_content
     (type,pagetitle,longtitle,menutitle,description,alias,uri,uri_override,template,content_type,
      parent,context_key,published,hidemenu,menuindex,searchable,cacheable,richtext,createdby,
      createdon,publishedon,publishedby,content)
   VALUES ('document','auth e2e','','','','$ALIAS','$ALIAS',1,0,1,
      0,'$CTX',1,1,0,0,0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),1,
      '[[!tableRenderer? &url=\`http://$REACH:$port/figures\` &rows=\`rows\` &auth=\`kuber\`]]');" \
  || { echo "could not create the test page" >&2; exit 2; }
clear_cache

PAGE="$SCHEME://$DOOR/$ALIAS"

# --- 1. anonymous: the page must ask the audience nothing --------------------------------------
anon=$(curl -sS --resolve "${DOOR%%:*}:$PORT:$IP" -m 10 "$PAGE")
check "$([ "$(wc -l < "$tmp/requests.log")" = 0 ] && echo 1 || echo 0)" \
      "an anonymous reader causes no request to the audience" \
      "log: $(cat "$tmp/requests.log")"
check "$(hasnt "$anon" '<table')" \
      "an anonymous reader sees no table — blank, as the owner asked" "$(echo "$anon" | head -c 300)"

# --- 2. sign in for real, then load the same page ----------------------------------------------
jar="$tmp/cookies"
C=(curl -sS --resolve "${DOOR%%:*}:$PORT:$IP" -b "$jar" -c "$jar" -m 10)
loc=$("${C[@]}" -o /dev/null -w '%{redirect_url}' "$SCHEME://$DOOR/sso-login?sso=go")
form=$("${C[@]}" "$loc")
qval=$(printf '%s' "$form" | grep -o 'name="q" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
qval=${qval//&amp;/&}
cb=$("${C[@]}" -o /dev/null -w '%{redirect_url}' -X POST \
     --data-urlencode "q=$qval" --data-urlencode "email=$reader" "${loc%%\?*}")
"${C[@]}" -o /dev/null "$cb"
sid=$(awk '/PHPSESSID/ {print $7}' "$jar" | tail -1)
signed=$(q "SELECT data FROM modx_session WHERE id='$sid';" | grep -c 'user.token')
check "$([ "$signed" = 1 ] && echo 1 || echo 0)" "signed in on the bench as an existing user" "session $sid"

out=$("${C[@]}" "$PAGE")
sent=$(tail -1 "$tmp/requests.log")
check "$([ "$(wc -l < "$tmp/requests.log")" = 1 ] && echo 1 || echo 0)" \
      "a signed-in reader causes exactly one request" "log: $(cat "$tmp/requests.log")"
check "$(hasre "$sent" '^Bearer [A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$')" \
      "a Bearer assertion arrives at the audience" "header: '$sent'"
check "$(has "$out" 'reached the audience')" \
      "the value fetched as the reader is what the page shows" "$(echo "$out" | head -c 300)"

# --- 3. the token, checked by the code that will check it in production -------------------------
jwt=${sent#Bearer }
verified=$(KUBER_CRYPTO="$CRYPTO" PUB="$tmp/pub.pem" JWT="$jwt" local_php -r '
  require getenv("KUBER_CRYPTO");
  $sub = Kuber\Core\Crypto::jwtVerify(getenv("JWT"), file_get_contents(getenv("PUB")), "modx", "kuber");
  echo $sub === null ? "REFUSED" : $sub;
')
check "$([ "$verified" = "$reader" ] && echo 1 || echo 0)" \
      "kuber's jwtVerify() returns the address that signed in" "returned: '$verified'"
# The control: the same call with a different expected audience must refuse, or the line above
# proves only that a string came back.
refused=$(KUBER_CRYPTO="$CRYPTO" PUB="$tmp/pub.pem" JWT="$jwt" local_php -r '
  require getenv("KUBER_CRYPTO");
  $sub = Kuber\Core\Crypto::jwtVerify(getenv("JWT"), file_get_contents(getenv("PUB")), "modx", "someone-else");
  echo $sub === null ? "REFUSED" : $sub;
')
check "$([ "$refused" = REFUSED ] && echo 1 || echo 0)" \
      "...and refuses the same token for another audience" "returned: '$refused'"

q "DELETE FROM modx_session WHERE id='$sid';" >/dev/null 2>&1
summary
