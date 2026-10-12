<?php
/**
 * edge-context — MODx serves the context the edge names, and every other request gets the estate's
 * 404 page. Pure PHP; the lookup is a stand-in, so no database is needed.
 *
 *   php tests/edge-context.php
 *
 * The refusal path calls exit, so each refusal case runs in a child PHP process and the test reads
 * its status line and body — the same bytes a browser would get.
 */
$lib = dirname(__DIR__) . '/common/lib/EdgeContext.php';
require $lib;

$pass = 0;
$fail = 0;
function check(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; if ($detail !== '') { echo "          $detail\n"; } }
}

$have = ['family', 'corporate', 'worldview', 'claude', 'web', 'mgr'];
$exists = static fn(string $k): bool => in_array($k, $have, true);

echo "edge-context — the context comes from the edge, and nobody else gets in\n\n";

check(edge_context_key(['HTTP_X_TENANT' => 'worldview'], $exists) === 'worldview',
      'a declared context in the header is served');
check(edge_context_key(['HTTP_X_TENANT' => ' family '], $exists) === 'family',
      '  surrounding spaces are ignored');
check(edge_context_key(['HTTP_HOST' => 'worldview.example'], $exists) === null,
      'no header is refused — the host is never read');
check(edge_context_key(['HTTP_X_TENANT' => 'nosuch'], $exists) === null,
      'a context this database does not have is refused');
check(edge_context_key(['HTTP_X_TENANT' => 'mgr'], $exists) === null,
      "MODx's manager context is never served through the site door");
check(edge_context_key(['HTTP_X_TENANT' => 'web'], $exists) === null,
      "MODx's empty default context is never served either");
check(edge_context_key(['HTTP_X_TENANT' => "family' OR 1=1"], $exists) === null,
      'a malformed key is refused before any lookup');
$asked = false;
edge_context_key(['HTTP_X_TENANT' => '../etc'], static function () use (&$asked) { $asked = true; return true; });
check(!$asked, '  and the database is not even asked about it');

// ── the refusal, as a browser receives it ─────────────────────────────────────────────────────
$page = dirname(__DIR__) . '/common/lib/no-one-here-20261012.html';
$want = '5f141321ff4a1d89302c14756c472a6d8faf1119e9096e5184d8c85f7278925f';
check(is_file($page) && hash_file('sha256', $page) === $want,
      'the 404 body is the dated page, unchanged',
      is_file($page) ? 'sha256 ' . hash_file('sha256', $page) : 'file missing');

$tmp = tempnam(sys_get_temp_dir(), 'edgectx');
file_put_contents($tmp, '<?php require ' . var_export($lib, true) . ';'
    . ' edge_context_refuse();');
$cgi = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));
$out = $cgi !== '' ? shell_exec(escapeshellarg($cgi) . ' -q ' . escapeshellarg($tmp) . ' 2>&1') : '';
if ($out === null || $out === '') {
    // no php-cgi on this ground: the CLI does not print headers, so read the status another way
    $body = shell_exec('php ' . escapeshellarg($tmp));
    check($body === file_get_contents($page), 'refusing sends the page, byte for byte, and stops');
} else {
    check(stripos($out, 'Status: 404') !== false, 'refusing sends status 404', substr($out, 0, 120));
    check(strpos($out, 'There is no one by that name here.') !== false, '  with the page as the body');
    check(stripos($out, 'Cache-Control: no-store') !== false, '  and it is not cached');
}
unlink($tmp);

// ── a program loading MODx is not a visitor ─────────────────────────────────────────────────────
$api = tempnam(sys_get_temp_dir(), 'edgeapi');
file_put_contents($api, '<?php define("MODX_API_MODE", true); '
    . 'class_exists("MODX\\\\Revolution\\\\modX") || eval("namespace MODX\\\\Revolution; class modX { function getCount(\\$c, \\$w) { return 0; } }"); '
    . '$modx = new MODX\\Revolution\\modX(); echo var_export(require ' . var_export($lib, true) . ', true);');
$apiOut = shell_exec('php ' . escapeshellarg($api) . ' 2>&1');
check(trim((string) $apiOut) === "'web'",
      'a script in MODX_API_MODE with no header gets web, not the 404 page', substr((string) $apiOut, 0, 160));
unlink($api);

// ── the control: this test can tell a served request from a refused one ────────────────────────
check(edge_context_key(['HTTP_X_TENANT' => 'worldview'], $exists) !== edge_context_key([], $exists),
      'control: a declared context and no header decide DIFFERENTLY');

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
