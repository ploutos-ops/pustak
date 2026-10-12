<?php
/**
 * tableRenderer &auth — the test, run against the REAL snippet and the REAL verifier.
 *
 *     php tests/tableRenderer-auth.php
 *
 * WHAT IS UNDER TEST. A MODx page must be able to read a value from kuber at render time
 * under the identity of the person reading it, and render BLANK when there is no identity
 * (the owner, 2026-07-29: *"if not logged in, display blank"*). `&auth` is how tableRenderer presents
 * that identity: a 60-second RS256 JWT, minted server-side, sent as `Authorization: Bearer`.
 *
 * WHY THE PIECES ARE REAL RATHER THAN MODELLED. Three things here could each be got wrong in a way
 * a self-consistent test would agree with enthusiastically:
 *
 *   1. THE TOKEN. It is verified by `Kuber\Core\Crypto::jwtVerify()` — kuber's own production
 *      verifier, loaded from their checkout — not by a re-implementation in this file. If the two
 *      sides disagree about b64url padding, the signing input, or the claim names, that is exactly
 *      the failure this must catch, and a verifier I wrote would share my mistake. When kuber's
 *      checkout is absent the test REFUSES to run rather than quietly dropping to the weaker
 *      check; `ALLOW_NO_KUBER=1` is the explicit opt-out for someone who has only this repo.
 *   2. THE HEADER. Asserted from the receiving end — a real HTTP server on loopback that records
 *      the Authorization header it was actually sent. Inspecting a variable inside the snippet
 *      would prove the string was built, not that it left the process.
 *   3. THE SNIPPET. The same file production runs, eval'd the way MODx eval's it (a MODx snippet is
 *      stored without an opening <?php tag), with $modx stubbed to what it touches.
 *
 * EVERY NEGATIVE CASE HAS A POSITIVE CONTROL, and vice versa, because a check that can only say
 * "no" is worthless: the wrong-key case is paired with the right-key case, the refused-transport
 * case with an accepted one, "no request was made" with "a request was made".
 */

$HOME = getenv('HOME');
$REPO = dirname(__DIR__);

$SNIPPET = getenv('TABLERENDERER') ?: $REPO . '/common/snippets/tableRenderer.php';
$CRYPTO  = getenv('KUBER_CRYPTO')  ?: $HOME . '/gitea/work-products/kuber/php/Core/Crypto.php';

if (!is_file($SNIPPET)) {
    fwrite(STDERR, "tableRenderer.php not found at $SNIPPET\n");
    exit(2);
}
if (!is_file($CRYPTO)) {
    if (getenv('ALLOW_NO_KUBER') !== '1') {
        fwrite(STDERR, "REFUSING TO RUN: kuber's own verifier is not at $CRYPTO.\n"
            . "The point of this test is that the token is verified by the code that will verify\n"
            . "it in production, not by a copy of it. Set KUBER_CRYPTO, or ALLOW_NO_KUBER=1 to\n"
            . "run the rest of the suite knowingly without the cross-verify.\n");
        exit(2);
    }
    fwrite(STDERR, "WARNING: running WITHOUT kuber's verifier — token claims are unchecked.\n");
} else {
    require $CRYPTO;
}
$HAVE_CRYPTO = is_file($CRYPTO);

// ---- keys ------------------------------------------------------------------------------------
/** @return array{0:string,1:string} [privatePem, publicPem] */
function keypair(): array {
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($k, $priv);
    return [$priv, openssl_pkey_get_details($k)['key']];
}
[$PRIV, $PUB]             = keypair();
[$OTHER_PRIV, $OTHER_PUB] = keypair();   // the wrong key, for the probe control

// ---- the receiving end -----------------------------------------------------------------------
// A real server, so "the header was sent" is measured where it arrives. It also writes one line
// per request, which is how the "no request was made at all" cases are proved: a snippet that
// refuses must not fetch as nobody, and silence is only meaningful if the log can record noise.
$TMP = sys_get_temp_dir() . '/trauth-' . getmypid();
@mkdir($TMP, 0700, true);
$LOG = "$TMP/requests.log";
touch($LOG);

file_put_contents("$TMP/router.php", <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
file_put_contents(getenv('TRAUTH_LOG'), $path . "\t" . $auth . "\n", FILE_APPEND);
if ($path === '/deny') {
    http_response_code(403);
    echo '{"error":"no such identity"}';
    return;
}
header('Content-Type: application/json');
echo json_encode(['rows' => [['seen' => $auth]]]);
PHP);

/** First free loopback port at or above $from — a fixed port would make the suite fail on a busy box. */
function freePort(int $from = 8399): int {
    for ($p = $from; $p < $from + 50; $p++) {
        $s = @stream_socket_server("tcp://127.0.0.1:$p", $e, $m);
        if ($s) { fclose($s); return $p; }
    }
    fwrite(STDERR, "no free port in $from..\n");
    exit(2);
}
$PORT = freePort();
$server = proc_open(
    ['php', '-S', "127.0.0.1:$PORT", '-t', $TMP, "$TMP/router.php"],
    [1 => ['file', "$TMP/server.out", 'a'], 2 => ['file', "$TMP/server.out", 'a']],
    $pipes, $TMP, ['TRAUTH_LOG' => $LOG] + $_ENV
);
register_shutdown_function(function () use ($server, $TMP) {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    array_map('unlink', glob("$TMP/*") ?: []);
    @rmdir($TMP);
});
for ($i = 0; $i < 100; $i++) {                       // wait for it to actually accept
    $c = @stream_socket_client("tcp://127.0.0.1:$PORT", $e, $m, 0.2);
    if ($c) { fclose($c); break; }
    usleep(50000);
}
$BASE = "http://127.0.0.1:$PORT";

// ---- $modx, stubbed to exactly what the snippet touches --------------------------------------
class StubParser {
    public $uncacheable = true;
    public function isProcessingUncacheable() { return $this->uncacheable; }
}
class StubResource {
    public $content = '';
    public function get($k) { return $k === 'content' ? $this->content : null; }
}
class StubProfile {
    public $email = '';
    public function get($k) { return $k === 'email' ? $this->email : null; }
}
class StubUser {
    public $authenticated = true;
    public $profile;
    public function __construct(string $email) { $this->profile = new StubProfile(); $this->profile->email = $email; }
    public function isAuthenticated($ctx = '') { return $this->authenticated; }
    public function getOne($alias) { return $alias === 'Profile' ? $this->profile : null; }
}
class StubContext {
    public $key = 'family';
    public function get($k) { return $k === 'key' ? $this->key : null; }
}
class StubModx {
    public $resource;
    public $user;
    public $context;
    public $parser;
    public $settings = [];                            // system settings, as MODx getOption falls back to
    public function __construct() {
        $this->resource = new StubResource();
        $this->context  = new StubContext();
        $this->parser   = new StubParser();
    }
    /** props first, then system settings, then the default — MODx's own precedence. */
    public function getOption($key, $props = null, $default = null) {
        if (is_array($props) && array_key_exists($key, $props) && $props[$key] !== null) {
            return $props[$key];
        }
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }
    public function getParser() { return $this->parser; }
    public function log($level, $msg) { }
}

$code = file_get_contents($SNIPPET);
if (str_starts_with(ltrim($code), '<?php')) {
    $code = substr(ltrim($code), 5);
}
/** Run the snippet in its own scope so nothing leaks between cases. */
$run = function ($modx, $scriptProperties) use ($code) { return eval($code); };

/** A fresh $modx with a signed-in, SSO-verified reader and the private key in place. */
$fresh = function (string $email = 'someone@example.org') use ($PRIV) {
    $m = new StubModx();
    $m->user = new StubUser($email);
    $m->settings['kuber.jwt_private_key'] = $PRIV;
    $_SESSION = ['sso.verified_email' => $email];
    return $m;
};

// ---- assertions -------------------------------------------------------------------------------
$pass = 0; $fail = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { $pass++; printf("  ok    %s\n", $name); return; }
    $fail++;
    printf("  FAIL  %s\n", $name);
    if ($detail !== '') {
        foreach (explode("\n", rtrim($detail)) as $line) { printf("          %s\n", $line); }
    }
};
$requests = function (string $path) use ($LOG) {
    $out = [];
    foreach (file($LOG, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        [$p, $a] = array_pad(explode("\t", $line, 2), 2, '');
        if ($p === $path) { $out[] = $a; }
    }
    return $out;
};

echo "tableRenderer &auth\n";

// --- 1. the whole point: a signed-in reader's identity reaches the endpoint --------------------
$m   = $fresh();
$out = $run($m, ['url' => "$BASE/t1", 'rows' => 'rows', 'auth' => 'kuber']);
$seen = $requests('/t1');
$check('a request is made', count($seen) === 1, 'requests to /t1: ' . count($seen));
$sent = $seen[0] ?? '';
$check('an Authorization: Bearer header arrives at the endpoint',
    (bool) preg_match('/^Bearer [A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $sent),
    "header was: '$sent'");
$check('the table renders', str_contains($out, '<table'), substr($out, 0, 300));

$jwt = preg_replace('/^Bearer /', '', $sent);
// The three controls below are only meaningful once there IS a token: "refused against the wrong
// key" is trivially true of an empty string, and a check that stays green over an empty haystack
// is worse than one that goes red. So they are gated on the positive case having produced one.
if ($HAVE_CRYPTO && $jwt !== '') {
    $check("kuber's own jwtVerify() accepts it and returns the reader's email",
        \Kuber\Core\Crypto::jwtVerify($jwt, $PUB, 'modx', 'kuber') === 'someone@example.org',
        'jwtVerify returned: ' . var_export(\Kuber\Core\Crypto::jwtVerify($jwt, $PUB, 'modx', 'kuber'), true));
    // The controls that prove the line above can say no.
    $check('the same token is REFUSED against a different public key',
        \Kuber\Core\Crypto::jwtVerify($jwt, $OTHER_PUB, 'modx', 'kuber') === null);
    $check("aud is really 'kuber' (refused when another audience is expected)",
        \Kuber\Core\Crypto::jwtVerify($jwt, $PUB, 'modx', 'roster') === null);
    $check("iss is really 'modx' (refused when another issuer is expected)",
        \Kuber\Core\Crypto::jwtVerify($jwt, $PUB, 'another-app', 'kuber') === null);
} elseif ($HAVE_CRYPTO) {
    $check("kuber's own jwtVerify() accepts the token", false, 'no token was produced to verify');
}
$claims = json_decode(base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true) ?: [];
$check('the assertion lives 60 seconds — it is a request credential, not a session',
    ($claims['exp'] ?? 0) - ($claims['iat'] ?? 0) === 60,
    'iat=' . ($claims['iat'] ?? 'absent') . ' exp=' . ($claims['exp'] ?? 'absent'));
$check('the token is not stale on arrival', ($claims['exp'] ?? 0) > time());

// --- 2. no identity => blank, and NOTHING is asked of kuber -----------------------------------
$m = $fresh();
$_SESSION = [];                                       // signed in to MODx, but not via SSO
$out = $run($m, ['url' => "$BASE/t2", 'rows' => 'rows', 'auth' => 'kuber']);
$check('an unverified reader produces no request at all', $requests('/t2') === [],
    'requests: ' . count($requests('/t2')));
$check('an unverified reader produces no table', !str_contains($out, '<table'), $out);

$m = $fresh('someone@example.org');
$_SESSION = ['sso.verified_email' => 'someone.else@example.org'];
$out = $run($m, ['url' => "$BASE/t3", 'rows' => 'rows', 'auth' => 'kuber']);
$check('a session email that disagrees with the account on file asserts nobody',
    $requests('/t3') === [] && !str_contains($out, '<table'), $out);

$m = $fresh();
$m->user->authenticated = false;                      // signed out of this context
$out = $run($m, ['url' => "$BASE/t4", 'rows' => 'rows', 'auth' => 'kuber']);
$check('a reader not authenticated in this context asserts nobody',
    $requests('/t4') === [] && !str_contains($out, '<table'), $out);

// --- 3. a missing key must never mean "ask anyway, as nobody" ----------------------------------
$m = $fresh();
$m->settings['kuber.jwt_private_key'] = '';
$out = $run($m, ['url' => "$BASE/t5", 'rows' => 'rows', 'auth' => 'kuber']);
$check('an absent signing key refuses rather than fetching unauthenticated',
    $requests('/t5') === [] && !str_contains($out, '<table'), $out);
$check('...and says which setting is missing', str_contains($out, 'kuber.jwt_private_key'), $out);

// A truncated key is the failure infra explicitly warned about — a mangled 1703-character
// string in a password field looks exactly like a good one. It must refuse, not raise.
$m = $fresh();
$m->settings['kuber.jwt_private_key'] = substr($PRIV, 0, 400);
$out = $run($m, ['url' => "$BASE/t5b", 'rows' => 'rows', 'auth' => 'kuber']);
$check('an unusable key refuses without taking the page down',
    $requests('/t5b') === [] && !str_contains($out, '<table') && str_starts_with($out, '<!--'), $out);

// --- 4. the refusals that are about the call, not the reader -----------------------------------
$m   = $fresh();
$out = $run($m, ['from' => 'x', 'auth' => 'kuber']);
// The reason is HTML-escaped into the comment, so the property name arrives as &amp;auth.
$check('&auth without &url is refused',
    str_contains($out, '&amp;auth is only meaningful') && !str_contains($out, '<table'), $out);

$m   = $fresh();
$out = $run($m, ['url' => 'http://kuber.example.com/rows', 'auth' => 'kuber']);
$check('a Bearer is never sent in clear over http to a public host',
    !str_contains($out, '<table') && str_contains($out, 'https'), $out);
// The positive control for that rule: the same call over https gets PAST the transport guard and
// fails later, on the network. Distinguished by which comment comes back.
$m   = $fresh();
$out = $run($m, ['url' => 'https://kuber.example.invalid/rows', 'auth' => 'kuber', 'urlTimeout' => 2]);
$check('https to the same host is allowed through the transport guard',
    !str_contains($out, 'https://kuber.example.invalid/rows') || !str_contains($out, 'must be'), $out);

// The internal zone is the setting estate.internal_zone, not a suffix typed in the snippet: http to
// a name in the declared zone passes the transport guard; the same name with no zone declared does not.
$m   = $fresh(); $m->settings['estate.internal_zone'] = '.corp.internal';
$out = $run($m, ['url' => 'http://kuber.corp.internal/rows', 'auth' => 'kuber', 'urlTimeout' => 2]);
$check('http to a name in the declared internal zone passes the transport guard',
    !str_contains($out, 'will not present a bearer credential in clear text'), $out);
$m   = $fresh();
$out = $run($m, ['url' => 'http://kuber.corp.internal/rows', 'auth' => 'kuber']);
$check('with no estate.internal_zone the same name is refused, and the refusal names the setting',
    str_contains($out, 'clear text') && str_contains($out, 'estate.internal_zone is not set'), $out);

// --- 5. kuber's 403 is the owner's blank -----------------------------------------------------------
$m   = $fresh();
$out = $run($m, ['url' => "$BASE/deny", 'rows' => 'rows', 'auth' => 'kuber']);
$check('a 403 renders no table', !str_contains($out, '<table'), $out);
$check('...and names the 403 in the source for whoever looks', str_contains($out, '403'), $out);

// --- 6. regressions: everything that worked before still does ----------------------------------
$m   = $fresh();
$out = $run($m, ['url' => "$BASE/t6", 'rows' => 'rows']);
$check('&url WITHOUT &auth still sends no Authorization header at all',
    $requests('/t6') === [''], 'header was: ' . var_export($requests('/t6'), true));
$check('&url without &auth still renders', str_contains($out, '<table'), $out);

$m = $fresh();
$m->resource->content = '<script type="application/ld+json" id="c">[{"name":"Alice","email":"alice@example.org"}]</script>';
$out = $run($m, ['from' => 'c']);
$check('&from still renders from the page', str_contains($out, 'Alice') && str_contains($out, '<table'), $out);

$m = $fresh();
$m->parser->uncacheable = false;
$out = $run($m, ['url' => "$BASE/t7", 'rows' => 'rows', 'auth' => 'kuber']);
$check('a cached call is still refused, and asks kuber nothing',
    $requests('/t7') === [] && str_contains($out, 'uncached'), $out);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
