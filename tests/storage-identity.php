<?php
/**
 * storage-identity — which identity this application presents to the storage door.
 *
 * The door authenticates callers by client certificate now. What must be true, and what this
 * drives rather than reads:
 *
 *   1. with a readable cert+key, curl carries CURLOPT_SSLCERT/SSLKEY and NO Authorization header
 *   2. without them, the bearer path is taken AND SAYS SO in the log — a silent fallback is how a
 *      migration stops half-done and stays there
 *   3. with neither, it refuses and no request is made
 *
 * IT DRIVES THE REAL FUNCTION. The decision used to live inside the plugin, which needs a MODX
 * request and a static-resource render to reach — so the only available test was a grep of the
 * plugin's source, and this repository has already been taught what that is worth: MODx's
 * `failures-name-themselves` passed twice over deleted code because it matched the comment
 * explaining it. The decision was moved into a class so it could be exercised with a real
 * directory on disk and no MODX in the room.
 *
 *   php tests/storage-identity.php
 */
require_once __DIR__ . '/../common/lib/StorageIdentity.php';

use Pustak\Modx\StorageIdentity;

$pass = 0; $fail = 0;
function ok(string $m): void   { global $pass; $pass++; printf("  ok    %s\n", $m); }
function bad(string $m, string $d = ''): void {
    global $fail; $fail++; printf("  FAIL  %s\n", $m);
    if ($d !== '') { printf("        %s\n", $d); }
}

echo "storage-identity — the caller proves who it is, and says so when it cannot\n\n";

$dir = sys_get_temp_dir() . '/storage-identity-' . getmypid();
@mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir) {
    foreach (['cert.pem', 'key.pem'] as $f) { @unlink("$dir/$f"); }
    @rmdir($dir);
});

// ── 1. the certificate path ────────────────────────────────────────────────────────────────────
file_put_contents("$dir/cert.pem", "-- not a real certificate, and it does not need to be --\n");
file_put_contents("$dir/key.pem", "-- nor this --\n");

[$path, $opts, $note] = StorageIdentity::curlOptions($dir, 'a-token-that-must-not-be-used');
$path === StorageIdentity::CERTIFICATE
    ? ok('a readable cert+key takes the certificate path')
    : bad("took the '$path' path with a readable cert+key", (string) $note);
($opts[CURLOPT_SSLCERT] ?? null) === "$dir/cert.pem"
    ? ok('curl carries CURLOPT_SSLCERT pointing at cert.pem')
    : bad('CURLOPT_SSLCERT is not the cert', var_export($opts[CURLOPT_SSLCERT] ?? null, true));
($opts[CURLOPT_SSLKEY] ?? null) === "$dir/key.pem"
    ? ok('curl carries CURLOPT_SSLKEY pointing at key.pem beside it')
    : bad('CURLOPT_SSLKEY is not the key', var_export($opts[CURLOPT_SSLKEY] ?? null, true));

// THE ONE THAT MATTERS MOST. Presenting both would leave the bearer path alive and untested: the
// door would accept the token, the certificate could be wrong or expired, and nobody would find
// out until the token was withdrawn — which is the day this is supposed to keep working.
isset($opts[CURLOPT_HTTPHEADER])
    ? bad('the certificate path ALSO sends an Authorization header',
          'the door would accept the token and the certificate would never be exercised')
    : ok('and NO Authorization header — the bearer path is not kept alive beside it');

// The paths are what is remembered, never the bytes: infra replaces these files in place every 8
// hours, so a cached certificate would work for eight hours and then fail inexplicably.
(is_string($opts[CURLOPT_SSLCERT]) && !str_contains((string) $opts[CURLOPT_SSLCERT], 'not a real'))
    ? ok('it hands curl the PATH, never the file contents (renewed every 8h in place)')
    : bad('the certificate contents were read into the options');

// ── 2. NO CERTIFICATE IS A REFUSAL — the fallback is gone ─────────────────────────
// This block asserted the bearer path. It existed so the certificate change needed no
// deploy-ordering gate; infra removed modx's row from STORAGE_SYSTEM_TOKENS on 2026-08-14
// (drilled at the door: modx bearer 401, modx certificate 200), so the fallback could only
// degrade to a slower 401. What must be true now is that nothing is attempted and the reason is
// legible HERE rather than at the door.
[$path, $opts, $note] = StorageIdentity::curlOptions(null, 'a-token-that-must-be-ignored');
$path === StorageIdentity::NONE
    ? ok('no rendered identity directory -> REFUSES, it does not fall back')
    : bad("took the '$path' path with no identity directory");
($opts === [] || !isset($opts[CURLOPT_HTTPHEADER]))
    ? ok('and it carries no Authorization header — there is no second way in')
    : bad('a bearer header was still built', var_export($opts[CURLOPT_HTTPHEADER] ?? null, true));
(!defined('Pustak\\Modx\\StorageIdentity::BEARER'))
    ? ok('and the BEARER path no longer exists to be reached')
    : bad('StorageIdentity::BEARER is still defined');
($note !== null && str_contains($note, 'No request was made'))
    ? ok('the refusal says no request was made — not merely that something failed')
    : bad('the refusal is vague', (string) $note);

// A token is still ACCEPTED as an argument and ignored, deliberately: the plugin and the ground
// still pass storage.modx_token while both are cleaned up, and changing the signature here would
// be a second deploy-ordering gate — the exact thing the bearer path existed to avoid.
[$path2] = StorageIdentity::curlOptions(null, 'still-passed-by-the-plugin');
$path2 === StorageIdentity::NONE
    ? ok('a still-rendered token changes nothing — accepted and ignored')
    : bad("a token still steers the caller: '$path2'");

// A directory that exists but holds no certificate is the same refusal, and it must say WHICH file.
[$path, , $note] = StorageIdentity::curlOptions('/nonexistent-identity-dir', '');
($path === StorageIdentity::NONE && str_contains((string) $note, '/nonexistent-identity-dir/cert.pem'))
    ? ok('an unreadable certificate names the path it looked for')
    : bad('the note does not name the missing certificate', (string) $note);

// ── 3. neither ─────────────────────────────────────────────────────────────────────────────────
[$path, $opts, $note] = StorageIdentity::curlOptions(null, '');
$path === StorageIdentity::NONE
    ? ok('no certificate and no token -> refuses rather than calling')
    : bad("took the '$path' path with nothing to present");
($note !== null && str_contains($note, 'No request was made'))
    ? ok('and the log says no request was made — not merely that something failed')
    : bad('the refusal does not say that nothing was sent', (string) $note);
$opts === []
    ? ok('and it hands back no curl options at all')
    : bad('options were returned for a caller that can prove nothing');

// ── 4. the controls ────────────────────────────────────────────────────────────────────────────
// Without these, every line above is consistent with a function that returns the same thing always.
// There were three answers while the bearer path existed; there are two now, and the control has
// to change with it or it fails on correct code — it did, on this commit, which is the control
// doing its job. What must still be true: the CERTIFICATE decides, and the token decides nothing.
[$a] = StorageIdentity::curlOptions($dir, '');
[$b] = StorageIdentity::curlOptions(null, 'tok');
[$c] = StorageIdentity::curlOptions(null, '');
($a !== $b)
    ? ok("control: with and without a certificate give different answers ($a vs $b)")
    : bad('control: the function does not discriminate', "$a / $b / $c");
($b === $c)
    ? ok('control: and the token makes no difference at all — it is ignored, not weighed')
    : bad('a token still changes the answer', "$b vs $c");

// And the certificate path must not depend on the token being absent — it wins either way.
[$withTok] = StorageIdentity::curlOptions($dir, 'tok');
[$noTok]   = StorageIdentity::curlOptions($dir, '');
($withTok === StorageIdentity::CERTIFICATE && $noTok === StorageIdentity::CERTIFICATE)
    ? ok('control: the certificate wins whether or not a token is also configured')
    : bad('the certificate path depends on the token', "$withTok / $noTok");

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
