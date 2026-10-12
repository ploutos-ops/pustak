<?php
/*
 * Write what this installation's core/config/application.php says differs between environments
 * (docs/apply-environment.md). Run it last after a restore or a new install.
 *
 *     MODX_ROOT=<docroot> php setup/apply-environment.php           write, print each change
 *     MODX_ROOT=<docroot> php setup/apply-environment.php --check   write nothing; exit 1 on a difference
 *
 * Exit 0 done (or no difference), 1 a difference under --check, 2 refused (nothing written).
 * Run as the user that owns the docroot's cache (www-data on a ground).
 */
$check = in_array('--check', $argv, true);
$root = getenv('MODX_ROOT') ?: '';
if (!$root || !is_file($root . '/config.core.php')) {
    fwrite(STDERR, "REFUSED: no config.core.php under '" . ($root ?: '(MODX_ROOT unset)') . "'. Nothing was written.\n");
    exit(2);
}
require $root . '/config.core.php';
require __DIR__ . '/lib/ApplyEnvironment.php';

use Pustak\Setup\ApplyEnvironment;
use MODX\Revolution\modContext;
use MODX\Revolution\modContextSetting;
use MODX\Revolution\modSystemSetting;

$file = MODX_CORE_PATH . 'config/application.php';
try {
    $want = ApplyEnvironment::settings(is_file($file) ? include $file : null);
} catch (\RuntimeException $e) {
    fwrite(STDERR, "REFUSED: {$e->getMessage()} ($file). Nothing was written.\n");
    exit(2);
}
$env = (include $file)['environment'];

require MODX_CORE_PATH . 'vendor/autoload.php';
$modx = new \MODX\Revolution\modX();
$modx->initialize('mgr');
$modx->setLogLevel(\MODX\Revolution\modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('ECHO');

$missing = array_filter(array_keys($want['contexts']), fn($c) => !$modx->getObject(modContext::class, ['key' => $c]));
if ($missing) {
    fwrite(STDERR, "REFUSED: application.php names context(s) this database does not hold: "
        . implode(', ', $missing) . ". Nothing was written.\n");
    exit(2);
}

$have = ['system' => [], 'contexts' => []];
foreach ($want['system'] as $k => $_) {
    $o = $modx->getObject(modSystemSetting::class, ['key' => $k]);
    $have['system'][$k] = $o ? (string)$o->get('value') : null;
}
foreach ($want['contexts'] as $ctx => $pairs) {
    foreach ($pairs as $k => $_) {
        $o = $modx->getObject(modContextSetting::class, ['context_key' => $ctx, 'key' => $k]);
        $have['contexts'][$ctx][$k] = $o ? (string)$o->get('value') : null;
    }
}

$changes = ApplyEnvironment::plan($want, $have);
$n = count($want['system']) + array_sum(array_map('count', $want['contexts']));
echo "apply-environment: '$env', $n value(s) declared, " . count($changes) . " differ\n";
foreach ($changes as $c) {
    echo '  ' . ApplyEnvironment::line($c) . "\n";
}
if ($check) {
    exit($changes ? 1 : 0);
}

foreach ($changes as [$scope, $k, $from, $to]) {
    if ($scope === 'system') {
        $o = $modx->getObject(modSystemSetting::class, ['key' => $k]);
        if (!$o) {
            $o = $modx->newObject(modSystemSetting::class);
            $o->fromArray(['key' => $k, 'xtype' => 'textfield', 'namespace' => 'core', 'area' => ''], '', true);
        }
    } else {
        $o = $modx->getObject(modContextSetting::class, ['context_key' => $scope, 'key' => $k]);
        if (!$o) {
            $o = $modx->newObject(modContextSetting::class);
            $o->fromArray(['context_key' => $scope, 'key' => $k, 'xtype' => 'textfield', 'namespace' => 'core', 'area' => ''], '', true);
        }
    }
    $o->set('value', $to);
    if (!$o->save()) {
        fwrite(STDERR, "FAILED: could not save $scope $k — the values above it are written, this one and those after are not.\n");
        exit(2);
    }
}
if ($changes) {
    $modx->getCacheManager()->refresh();
}
echo "apply-environment: done, " . count($changes) . " written" . ($changes ? ', cache refreshed' : '') . "\n";
