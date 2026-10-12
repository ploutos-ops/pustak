<?php
/**
 * protected-absorb.php — the PHP half of tests/protected-absorb.sh; not a suite on its own.
 *
 *     php tests/protected-absorb.php <docroot> save    <content-string>
 *     php tests/protected-absorb.php <docroot> cleanup <id> [<id>...]
 *
 * Creates a Static Resource whose `content` is exactly <content-string> — THE SHAPE THE MANAGER
 * MAKES: no media source field, just whatever path string the editor browsed or typed (core's own
 * getSourceFile comment: static resources have no media source support). The first version of this
 * helper set 'source' => 2 on the object and the suite went green on a field no manager save ever
 * carries — the drill proved the helper, not the manager. Then it fires OnDocFormSave THROUGH
 * $modx->invokeEvent — the same dispatch a manager save uses (protectOnSave's compatibility watch
 * cites the processor lines that fire it). What is deliberately NOT exercised is the manager's
 * permission plumbing: the seam under test is event -> plugin -> storage door -> content swap, and
 * that seam is identical however the event was fired.
 *
 * Prints one parseable line per fact; the .sh owns every assertion. Exit 0 = ran; the FACTS say
 * whether the plugin acted — so the same helper serves the green arm, the negative control, and
 * the drilled-red arm (plugin disabled) without carrying an opinion about which it is in.
 */
declare(strict_types=1);

define('MODX_API_MODE', true);

$root = $argv[1] ?? '';
$mode = $argv[2] ?? '';
if (!$root || !is_file($root . '/index.php')
        || !in_array($mode, ['save', 'resave', 'cleanup', 'plant', 'unplant', 'door', 'release'], true)) {
    fwrite(STDERR, "usage: php tests/protected-absorb.php <docroot> save|resave|cleanup|plant|unplant|door|release ...\n");
    exit(2);
}
require_once $root . '/index.php';
/** @var MODX\Revolution\modX $modx */
$modx->initialize('web');

if ($mode === 'cleanup') {
    foreach (array_slice($argv, 3) as $id) {
        $r = $modx->getObject(MODX\Revolution\modResource::class, (int) $id);
        if ($r) { $r->remove(); echo "removed {$id}\n"; }
    }
    exit(0);
}

// The filesystem and door arms below exist because ON A STAGING GROUND the suite's shell can do
// neither: core/protected/ is www-data 2775 (the runner cannot plant a file there) and the
// client key is readable only inside the container. Running them here — under $MODX_PHP_RUN, the
// same process class the application runs as — makes the drill MORE faithful, not less: plant is
// what a manager upload does, and door/release present the identity deliverStorageFile presents.
if ($mode === 'plant') {                                    // plant <path-rel-to-MODX_BASE_PATH> ; bytes on STDIN
    $target = MODX_BASE_PATH . ltrim($rel = (string) ($argv[3] ?? ''), '/');
    if ($rel === '' || str_contains($rel, '..')) { fwrite(STDERR, "bad plant path\n"); exit(2); }
    file_put_contents($target, stream_get_contents(STDIN)) !== false
        ? print("planted {$rel}\n") : exit(2);
    exit(0);
}
if ($mode === 'unplant') {
    $target = MODX_BASE_PATH . ltrim($rel = (string) ($argv[3] ?? ''), '/');
    if ($rel === '' || str_contains($rel, '..')) { exit(2); }
    @unlink($target);
    exit(0);
}
if ($mode === 'door' || $mode === 'release') {              // door <sha1> | release <sha1>
    $sha1 = strtolower((string) ($argv[3] ?? ''));
    if (!preg_match('/^[0-9a-f]{40}$/', $sha1)) { fwrite(STDERR, "bad sha1\n"); exit(2); }
    require_once rtrim(MODX_CORE_PATH, '/') . '/components/pustak/lib/Application.php';
    require_once rtrim(MODX_CORE_PATH, '/') . '/components/pustak/lib/StorageIdentity.php';
    [$p, $ident] = \Pustak\Modx\StorageIdentity::curlOptions(
        \Pustak\Modx\Application::config('estate_identity_dir'),
        (string) $modx->getOption('storage.modx_token'));
    if ($p === \Pustak\Modx\StorageIdentity::NONE) { fwrite(STDERR, "no identity on this ground\n"); exit(2); }
    $base = rtrim((string) $modx->getOption('storage.base_url'), '/');
    // Environment off the certificate CN, same one-owner rule as the plugin — the setting is a
    // restored copy on staging and 403'd/mismatched every scoped call there.
    $idDir = \Pustak\Modx\Application::config('estate_identity_dir');
    $crt = @openssl_x509_parse((string) @file_get_contents(rtrim((string) $idDir, '/') . '/cert.pem'));
    $env = is_array($crt) && preg_match('/^modx@([A-Za-z0-9_-]+)$/', (string) ($crt['subject']['CN'] ?? ''), $m)
        ? $m[1] : (string) $modx->getOption('storage.environment');
    $ch = curl_init("$base/v1/files/by-sha1/$sha1?system_id=modx");
    curl_setopt_array($ch, $ident + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($mode === 'door') { echo $code . "\n"; exit(0); }
    $uuid = json_decode((string) $body, true)['uuid'] ?? '';
    if ($code !== 200 || $uuid === '') { echo "release: no hold ($code)\n"; exit(0); }
    $ch = curl_init("$base/v1/files/$uuid?system_id=modx&environment=" . rawurlencode($env) . '&actor=protected-absorb-drill');
    curl_setopt_array($ch, $ident + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => 'DELETE']);
    curl_exec($ch);
    echo 'release: ' . curl_getinfo($ch, CURLINFO_RESPONSE_CODE) . "\n";
    curl_close($ch);
    exit(0);
}

$rel = $argv[3] ?? '';
if ($rel === '') { fwrite(STDERR, "no file given\n"); exit(2); }

if ($mode === 'resave') {
    // The stale-form shape: an EXISTING absorbed resource saved again with the old path string.
    $res = $modx->getObject(MODX\Revolution\modResource::class, (int) $rel);
    $old = $argv[4] ?? '';
    if (!$res || $old === '') { fwrite(STDERR, "resave needs <id> <content>\n"); exit(2); }
    $res->set('content', $old);
    $res->save();
    $modx->invokeEvent('OnDocFormSave', ['id' => $res->get('id'), 'resource' => $res, 'mode' => 'upd']);
    $fresh = $modx->getObject(MODX\Revolution\modResource::class, (int) $rel);
    echo 'id=' . $rel . "\n" . 'content=' . trim((string) $fresh->get('content')) . "\n" . "content_type=0\n";
    exit(0);
}

$res = $modx->newObject(MODX\Revolution\modStaticResource::class);
$res->fromArray([
    'pagetitle'   => 'drill protected-absorb ' . getmypid() . ' ' . substr(sha1($rel), 0, 8),
    'alias'       => 'drill-protected-absorb-' . getmypid() . '-' . substr(sha1($rel), 0, 8),
    'context_key' => 'platform',
    'parent'      => 0,
    'published'   => 0,                                     // a drill row is never a served page
    'searchable'  => 0,
    'cacheable'   => 1,
    'content'     => $rel,                                  // exactly the manager's shape: a string
]);
if (!$res->save()) { fwrite(STDERR, "could not save the drill resource\n"); exit(2); }
$id = (int) $res->get('id');

$modx->invokeEvent('OnDocFormSave', ['id' => $id, 'resource' => $res, 'mode' => 'new']);

$fresh = $modx->getObject(MODX\Revolution\modResource::class, $id);
echo 'id=' . $id . "\n";
echo 'content=' . trim((string) $fresh->get('content')) . "\n";
echo 'content_type=' . (int) $fresh->get('content_type') . "\n";
exit(0);
