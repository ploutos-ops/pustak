<?php
/**
 * clear-cache.php — regenerate MODx's caches, and DO NOT take the error log with them.
 *
 *     php clear-cache.php <docroot>          (or MODX_ROOT=<docroot> php clear-cache.php)
 *
 * `$modx->cacheManager->refresh()` with no arguments empties everything under `core/cache` —
 * including `core/cache/logs`, which is where MODx writes its error log. That is not a cosmetic
 * loss. A MODx with no error log is precisely how two earlier defects both hid: `sendRedirect('')` logs a
 * line and returns, so the only evidence a page was silently not redirecting was a log entry — and
 * the tool everyone runs after every change was deleting it. `tests/clear-cache-reaches-contexts.sh`
 * has asserted "the log directory survives a cache clear" since it was written; it failed today, and
 * it was right.
 *
 * So the partitions are named explicitly and `logs` is not among them. Anything MODx adds later that
 * is not listed here simply is not cleared by this script — the safe direction. The unsafe direction
 * is the wildcard that one day sweeps up something that matters, which is what this replaces.
 *
 * The two-second lag is NOT this script's doing and cannot be fixed here: the caches are PHP files
 * and PHP-FPM serves them through opcache, which re-checks a file's timestamp at most once every
 * `opcache.revalidate_freq` seconds. See docs/workflow.md — anything that changes a setting and then
 * verifies over HTTP must wait that window out.
 */
define('MODX_API_MODE', true);

// THE DOCROOT IS NOT A CONSTANT. This said `/srv/local/modx/index.php` — one ground's docroot,
// typed. On the staging ground the docroot is /opt/modx/html and this script simply could not run:
// four suites failed with "clear-cache.php failed:" and nothing else, because the path it wanted
// was not there (hanuman's fourth run, 2026-08-14).
//
// argv[1] first, because `docker exec` carries none of the caller's environment into the
// container — so a containerised run passes the root it was given, and a shell run keeps working
// unchanged through MODX_ROOT. Neither present is a REFUSAL that names both, never a guess: a
// cache clear against the wrong install reports success for work it did not do.
$root = $argv[1] ?? getenv('MODX_ROOT') ?: '';
if (!$root || !is_file($root . '/index.php')) {
    fwrite(STDERR, "REFUSING: no MODx index.php under '" . ($root ?: '(nothing)') . "'.\n"
                 . "          Pass the docroot as the first argument, or set MODX_ROOT.\n"
                 . "          Nothing was cleared.\n");
    exit(2);
}
require_once $root . '/index.php';

$modx->initialize('web');

// FULL refresh, because a partial one is not equivalent: MODx REBUILDS system settings and the
// context maps as part of refresh(), and naming a subset of providers left a page unable to read a
// setting at all (caught immediately by tests/clear-cache-reaches-contexts.sh, which is what that
// suite's first assertion is for). So the log is carried around the refresh instead of the refresh
// being narrowed around the log.
$core = $modx->getOption('core_path');
$logs = $core . 'cache/logs';
$keep = $core . 'cache-logs-keep';

if (is_dir($logs) && !@rename($logs, $keep)) {
    fwrite(STDERR, "clear-cache: could not move core/cache/logs aside; refusing to refresh, because "
                 . "a refresh would delete it and a MODx with no error log is how two earlier "
                 . "faults both hid.\n");
    exit(2);
}

$modx->cacheManager->refresh();

if (is_dir($keep) && !@rename($keep, $logs)) {
    fwrite(STDERR, "clear-cache: the cache was refreshed but core/cache/logs could not be put back "
                 . "(it is at $keep). MODx is logging nowhere until it is.\n");
    exit(2);
}
if (!is_dir($logs)) {
    @mkdir($logs, 0775, true);
}
if (!is_dir($logs)) {
    fwrite(STDERR, "clear-cache: core/cache/logs is missing and could not be created — MODx would "
                 . "log nothing.\n");
    exit(2);
}

echo "Cache cleared.\n";
