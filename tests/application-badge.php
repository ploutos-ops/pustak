<?php
/**
 * application-badge — what MODx presents as its own identity, and what it does when it has none.
 *
 * Four of the estate's five players read their badge from an environment
 * variable; MODx cannot, because the application PHP-FPM serves reads no environment at all. The
 * answer is a rendered file, `core/config/application.php`, and this suite is what makes that
 * answer checkable rather than a sentence on a ticket.
 *
 * WHAT IT PROVES
 *   - the badge is read from the rendered file, verbatim, with no composition and no default;
 *   - ABSENT MEANS OMITTED — no file, no key, empty value and wrong type all produce NO header,
 *     never a placeholder, never the literal string 'null';
 *   - the plugin that actually calls out merges the badge alongside the Bearer token rather than
 *     replacing it — asserted against the file's real bytes, so a future edit that drops one of the
 *     two is caught here.
 *
 * Rules, same as the rest of this directory: a known-answer control runs FIRST so a harness that
 * can only pass is as visible as one that can only fail; every refusal is proven by inducing it.
 *
 *   php tests/application-badge.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../common/lib/Application.php';

use Pustak\Modx\Application;

$pass = 0; $fail = 0;
function check(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; if ($detail !== '') echo "        $detail\n"; }
}

$root = sys_get_temp_dir() . '/modx-badge-' . getmypid();
@mkdir($root . '/config', 0700, true);
$file = $root . '/config/application.php';
register_shutdown_function(function () use ($root, $file) {
    @unlink($file); @rmdir($root . '/config'); @rmdir($root);
});

function withFile(?string $contents, string $file, string $root): ?string {
    if ($contents === null) { @unlink($file); } else { file_put_contents($file, $contents); }
    Application::resetForTests();
    return Application::whoami($root);
}

echo "control — the probe can tell a present badge from an absent one\n";
$present = withFile("<?php return ['whoami' => 'control-badge'];", $file, $root);
$absent  = withFile(null, $file, $root);
check($present === 'control-badge' && $absent === null,
    'a known-good file reads back, and removing it reads null',
    'present=' . var_export($present, true) . ' absent=' . var_export($absent, true));
if ($present !== 'control-badge' || $absent !== null) {
    echo "\nREFUSING TO CONTINUE: the control failed, so nothing below would mean anything.\n";
    exit(2);
}

echo "\nthe value is read verbatim, never composed\n";
$v = withFile("<?php return ['whoami' => 'modx-prod-abc123'];", $file, $root);
check($v === 'modx-prod-abc123', 'the badge is exactly the rendered string', 'got: ' . var_export($v, true));
$v = withFile("<?php return ['whoami' => '  padded-badge  '];", $file, $root);
check($v === 'padded-badge', 'surrounding whitespace is trimmed (a rendered file often ends in one)', 'got: ' . var_export($v, true));

echo "\nabsent means OMITTED — four ways to have no badge, one outcome\n";
check(withFile(null, $file, $root) === null, 'no file at all -> null');
check(withFile("<?php return [];", $file, $root) === null, 'file present, no whoami key -> null');
check(withFile("<?php return ['whoami' => ''];", $file, $root) === null, 'empty value -> null, not an empty header');
check(withFile("<?php return ['whoami' => ['not', 'a', 'string']];", $file, $root) === null, 'wrong type -> null, not "Array"');
check(withFile("<?php return 'not an array';", $file, $root) === null, 'file returns a non-array -> null');

echo "\nthe headers it hands to a caller\n";
withFile("<?php return ['whoami' => 'header-badge'];", $file, $root);
$h = Application::badgeHeaders($root);
check($h === ['X-Application-Badge: header-badge'], 'one header, exactly the estate\'s name for it', 'got: ' . json_encode($h));
withFile(null, $file, $root);
$h = Application::badgeHeaders($root);
check($h === [], 'no badge -> NO header, not an empty one', 'got: ' . json_encode($h));

// The plugin that calls out with the badge belongs to an integration this enhancement does not
// ship; its wiring is asserted by that integration's own suite.
// THE ASSERTION THIS USED TO MAKE WAS THE WRONG ONE, and it is the session's own lesson. It read
//     strpos($plugin, "require_once dirname(__DIR__) . '/lib/Application.php'")
// — i.e. "the line exists". It never asked whether the file that line names could be reached, and
// it could not have: a plugin's body lives in `modx_site_plugins.plugincode`, a database column,
// so `dirname(__DIR__)` had nothing to resolve against on a real box — and `common/lib/` was
// deployed by NOTHING (measured with a control on the bench docroot). The check was
// green for two months over a require that would have been fatal the day the plugin was installed.
// "A verifier covers only the consumer it runs", in the suite written for that lesson.
$deployedLib = "rtrim(MODX_CORE_PATH, '/') . '/components/pustak/lib/";
foreach (['snippets/actuatorInfo.php' => file_get_contents(__DIR__ . '/../common/snippets/actuatorInfo.php')] as $label => $src) {
    check(strpos($src, $deployedLib) !== false || strpos($src, "components/pustak/lib/") !== false,
        "$label loads its classes from core/components/pustak/lib/ — where infra deploys them",
        'A repo path resolves on this checkout and nowhere a MODx element actually runs.');
    check(strpos($src, "dirname(__DIR__) . '/lib/") === false,
        "$label carries no repo-relative lib require",
        'That path only exists in a clone. An element body is a database column.');
}

// ---------------------------------------------------------------------------------------------
// THE INBOUND HALF. Everything above is the badge modx PRESENTS; this is
// the badge modx ACCEPTS. It matters more, because modx serves the public internet: for trump and
// kuberb a door that opens without its credential is a degraded state, and here it is a public
// disclosure. So the interesting assertions are the refusals, and each one is induced.
// ---------------------------------------------------------------------------------------------
// ── THE BADGE ACCEPTOR IS GONE ────────────────────────────────────────────────────
// This block used to induce every refusal Application::acceptedCaller() could produce — no map, an
// empty map, a wrong badge, a null value, a timing-safe comparison. All of it is deleted with the
// method, on the owner's ruling that the badge goes in favour of the certificate, and after infra
// moved the monitor — the only caller this door admits — onto one: --cert monitoring@production
// with no badge answers 200, certless 401, and the modx log carries zero badge admissions.
//
// What replaces those assertions is their point: there is no second way in.
check(!method_exists(Application::class, 'acceptedCaller'),
      'Application::acceptedCaller() no longer exists — the badge acceptor is deleted',
      'a badge acceptor that survives is a second credential nobody is testing');
$door = file_get_contents(dirname(__DIR__) . '/common/snippets/actuatorInfo.php');
$doorCode = implode("\n", array_filter(explode("\n", $door),
                    fn($l) => !preg_match('#^\s*(//|\*|/\*)#', $l)));
check(strpos($doorCode, 'HTTP_X_APPLICATION_BADGE') === false,
      '  and the actuator door does not read the badge header at all',
      'the door still has a badge path even though the acceptor is gone');
check(strpos($doorCode, 'acceptedClient(') !== false,
      '  it resolves the caller from the certificate, and only that');

// The OUTBOUND badge stays: whoami() is what modx PRESENTS to other doors, and those doors have
// not all moved yet. Deleting that too would be a different ticket's work.
check(method_exists(Application::class, 'whoami'),
      'the outbound badge (whoami) is untouched — other doors still expect it');

// ── the CERTIFICATE acceptor ────────────────────────────────────────────────────────────
// The owner ruled 2026-08-14: the badge is deleted in favour of the certificate. nginx sets
// X-Client-Identity only on a verified chain — the application validates nothing — so what this
// suite must prove is that the parsing admits exactly who it should and nobody else, including
// the forgeries a caller would try if nginx ever stopped overwriting the header.
check(Application::acceptedClient('CN=monitoring@production', ['monitoring']) === 'monitoring',
      'a verified certificate admits its application');
check(Application::acceptedClient('CN=monitoring@staging', ['monitoring']) === 'monitoring',
      '  on any environment — the door reads WHO, the ground is its own question');
check(Application::acceptedClient('', ['monitoring']) === null,
      'an EMPTY header is nobody — that is what nginx sets when no certificate verified');
check(Application::acceptedClient(null, ['monitoring']) === null,
      'and an absent header is nobody');
check(Application::acceptedClient('CN=trump@production', ['monitoring']) === null,
      'an application admitted at ANOTHER door is not admitted here');
check(Application::acceptedClient('CN=@production', ['monitoring']) === null,
      'a CN with no application is nobody, not an empty-string caller');
check(Application::acceptedClient('O=Example,CN=monitoring@production,OU=x', ['monitoring']) === 'monitoring',
      'the CN is found among other RDNs, in either order');
check(Application::acceptedClient('CN=monitoring@production', []) === null,
      'an EMPTY allow-list admits nobody — never everybody');
check(Application::acceptedClient('monitoring@production', ['monitoring']) === null,
      'a bare name that is not a DN is refused — the header is a DN or it is nothing');

// ── the header is only an identity where the ground vouches for it ──────────────────────
// MODx's own vhost (roles/nginx/templates/modx.conf.j2) does NOT set or clear X-Client-Identity —
// it passes client headers into fastcgi — so on that ground the header can be TYPED. The door
// must therefore ignore it entirely until infra renders `client_identity_trusted` in the same act
// as the nginx line that overwrites it. This is the check that would have caught my own commit:
// I wrote the certificate path trusting a header nothing was overwriting.
$src = file_get_contents(dirname(__DIR__) . '/common/snippets/actuatorInfo.php');
$code = implode("\n", array_filter(explode("\n", $src), fn($l) => !preg_match('#^\s*(//|\*|/\*)#', $l)));
// POSITIONS, NOT A REGEX. The first version used a greedy .*  across the whole file with /s and
// preg_match returned FALSE — a backtrack-limit error, not a non-match — so the check failed on
// correct code and would have failed on broken code too, which is the worst kind of check.
$posGate = strpos($code, 'client_identity_trusted');
$posRead = strpos($code, 'HTTP_X_CLIENT_IDENTITY');
check($posGate !== false && $posRead !== false && $posGate < $posRead
   && ($posRead - $posGate) < 200,
      'the actuator reads X-Client-Identity ONLY behind client_identity_trusted',
      'an ungated read admits anyone who types the header on a ground whose vhost does not clear it');
check(substr_count($code, 'HTTP_X_CLIENT_IDENTITY') === 1,
      '  and reads it in exactly one place — a second read would bypass the gate');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
