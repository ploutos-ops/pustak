<?php
/* Regenerate resource URIs path-aware (use_alias_path=1). Reports collisions. Run as www-data. */
// No fallback: /srv/local/modx was the dev bench and it is gone (2026-08-14).
$root = getenv('MODX_ROOT') ?: ($argv[1] ?? '');
if (!$root || !is_file($root . '/config.core.php')) {
    fwrite(STDERR, "REFUSING: no config.core.php under '" . ($root ?: '(nothing)') . "'.\n"
                 . "          Set MODX_ROOT or pass the docroot. No URI was regenerated.\n");
    exit(2);
}
require $root . '/config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';

$modx = new \MODX\Revolution\modX();
$modx->initialize('mgr');
$modx->setLogLevel(\MODX\Revolution\modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('ECHO');

echo "use_alias_path=" . $modx->getOption('use_alias_path') . "\n";

/* Every context, read from the DB — a hardcoded list silently skipped `claude` (added later by
   setup/11) and would skip the next context added the same way. A URI pass that misses a context
   is invisible: nothing errors, the URIs are simply never regenerated. */
$contexts = [];
foreach ($modx->getIterator(\MODX\Revolution\modContext::class) as $ctx) {
    $contexts[] = $ctx->get('key');
}
sort($contexts);
$grand_dupe = 0;
foreach ($contexts as $ctx) {
    $seen = [];       // uri => first id
    $dupes = 0; $updated = 0; $total = 0;
    $c = $modx->newQuery(\MODX\Revolution\modResource::class);
    $c->where(['context_key' => $ctx]);
    $c->sortby('parent', 'ASC'); $c->sortby('menuindex', 'ASC');
    $resources = $modx->getIterator(\MODX\Revolution\modResource::class, $c);
    foreach ($resources as $r) {
        $total++;
        if ($r->get('uri_override')) { continue; }        // respect manual overrides
        $alias = $r->get('alias');
        $uri = $r->getAliasPath($alias);                  // path-aware per use_alias_path
        if (isset($seen[$uri])) { $dupes++; }
        else { $seen[$uri] = $r->get('id'); }
        if ($uri !== $r->get('uri')) {
            $r->set('uri', $uri);
            $r->save();
            $updated++;
        }
    }
    $grand_dupe += $dupes;
    echo sprintf("  %-10s total=%d updated=%d duplicate_uri=%d\n", $ctx, $total, $updated, $dupes);
}
echo "TOTAL duplicate URIs across contexts: $grand_dupe\n";

/* rebuild caches now that uris are correct */
$modx->getCacheManager()->refresh();
echo "cache refreshed\n";
