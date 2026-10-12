<?php
/**
 * EdgeContext — which MODx context a request is for, taken from the edge, and the estate's 404 when
 * there is none.
 *
 * WHERE IT RUNS. Stock `index.php` creates `$modx`, then requires the docroot's `config.context.php`
 * for a context key, then calls `$modx->initialize($key)`. On every ground with a web server that
 * file is rendered by infra's `roles/modx-web`, and it becomes one line:
 *
 *     <?php return require MODX_CORE_PATH . 'components/pustak/lib/EdgeContext.php';
 *
 * So the decision lives here, in MODx's own software, and the rendered file carries no list.
 *
 * THE DECISION.
 *   - The context is `HTTP_X_TENANT`, which nginx sets from the name's `upstream_key` and overwrites
 *     on every request (drilled with a forged caller value on the bench). The HOST IS
 *     NEVER READ — the owner, 2026-09-14: "someone else provides mapping of domain to tenant".
 *   - It is served only if it is a context this database has, and is not MODx's own `mgr` (the
 *     manager has its own entry point) or `web` (MODx's empty default, which nobody declares).
 *   - Anything else — no header, an unknown key, a malformed one — gets the estate's one answer for
 *     a name nobody serves: status 404 and `no-one-here-20261012.html` as the body, unchanged (the
 *     owner: "we should always respond to the doorbell"). MODx is not started for it.
 *
 * WHY THE DATABASE IS ASKED. MODx given a context key it does not have starts with NO context and
 * then fails somewhere later (measured 2026-09-15: initialize('nosuch') returns true with
 * $modx->context === null). One indexed count on modx_context is the price of refusing at the door.
 *
 * THE PAGE is kept beside this file and carries everything inline, so a refusal never depends on
 * reaching another host. tests/edge-context.php asserts its sha256; a new page is a new dated file
 * and a one-line change below.
 */

const EDGE_CONTEXT_NOT_SERVED = ['mgr', 'web'];
const EDGE_CONTEXT_PAGE = 'no-one-here-20261012.html';

/**
 * The context key to serve, or null. Pure apart from the lookup, so the test can drive it.
 *
 * @param array    $server  $_SERVER
 * @param callable $exists  fn(string $key): bool — does this database have the context?
 */
function edge_context_key(array $server, callable $exists): ?string
{
    $key = trim((string) ($server['HTTP_X_TENANT'] ?? ''));
    if ($key === '' || !preg_match('/^[A-Za-z0-9_-]{1,100}$/', $key)) {
        return null;
    }
    if (in_array($key, EDGE_CONTEXT_NOT_SERVED, true)) {
        return null;
    }
    return $exists($key) ? $key : null;
}

/** Send the estate's 404 page and stop. */
function edge_context_refuse(): void
{
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    readfile(__DIR__ . '/' . EDGE_CONTEXT_PAGE);
    exit;
}

// Required from config.context.php, which index.php requires in its own scope, so `$modx` is the
// instance index.php just made. Required by a test, `$modx` is absent and the test calls the
// functions above directly.
//
// A PROGRAM IS NOT A VISITOR. Scripts that load MODx through index.php — clear-cache.php, the suites,
// infra's converge refreshing the cache inside the container — define MODX_API_MODE first and send
// no header. They are refused nothing: they get the header's context if one is valid, otherwise
// MODx's own `web`, exactly what they got before, and then initialize whatever context they need.
// No request from the web can define a PHP constant, so this is not a way around the door. The
// suite found it on the first run (2026-09-15): four suites got this 404 page back from their own
// harness, and so would production's converge have.
if (isset($modx) && $modx instanceof \MODX\Revolution\modX) {
    $edgeKey = edge_context_key($_SERVER, static function (string $key) use ($modx): bool {
        return $modx->getCount(\MODX\Revolution\modContext::class, ['key' => $key]) === 1;
    });
    if ($edgeKey !== null) {
        return $edgeKey;
    }
    if (defined('MODX_API_MODE') && MODX_API_MODE) {
        return 'web';
    }
    edge_context_refuse();
}
return null;
