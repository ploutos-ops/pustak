<?php
/**
 * bench-server.php — the router for `php -S` in front of a MODx BENCH.
 *
 * WHY THIS EXISTS. The owner, 2026-08-19: "how do i get to see your local modx?" — and, 2026-08-20,
 * ruling out the answer we had been building: skunkworks is not in the business of nginx, and may
 * not ask for a listener on somebody's box to read its own bench. A dev bench needs no web server
 * it does not own. `php -S` is the whole edge: one process, one port, started and stopped by the
 * agent that owns the tree.
 *
 *     BENCH_TENANT=<context> PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:"$PORT" -t "$MODX_ROOT" \
 *         "$REPO"/setup/bench-server.php
 *
 * One server per port, one context per server: the port->context pairs are `estate.json`
 * `domains.bench_ports`.
 *
 * The example names VARIABLES and not a ground, and that is `suites-accept-their-ground.sh`
 * check "the old bench path is hardcoded in a shipped file" doing its job: this block used to
 * print one box's docroot and one agent's home. An example is copied more often than it is read,
 * so a literal path here is a literal path in somebody's shell tomorrow.
 *
 * WHAT THE ROUTER HAS TO DO, and each line is a thing that breaks without it:
 *
 *  1. SERVE REAL FILES AS FILES. `return false` hands the request back to the built-in server, so
 *     assets, css and images come off disk with their own content types. Without it every image
 *     is routed into index.php and comes back as HTML.
 *
 *  2. TURN A FRIENDLY URL INTO `q`. MODx's front controller reads `$_REQUEST['q']` — nginx does it
 *     with `rewrite ^/(.*)$ /index.php?q=$1`, and there is no nginx here. `modRequest.php` takes
 *     `q` VERBATIM with no base_url stripping, so the value is the path with its LEADING SLASH
 *     REMOVED and nothing else touched.
 *
 *  3. BE THE EDGE FOR THE TENANT HEADER. `config.context.php` requires EdgeContext.php, which
 *     takes the context from HTTP_X_TENANT and never reads the Host. nginx sets that header from
 *     the name's `upstream_key` and OVERWRITES what a caller sent; here the router does the same —
 *     a caller's X-Tenant is discarded and BENCH_TENANT, the one context this server was started
 *     for, is put in its place. A server started without BENCH_TENANT sends no header and every
 *     page answers the estate's 404, which is the refusal a visitor for no context gets.
 *
 *  4. NEVER SERVE core/. The host vhosts deny it; a bench that does not is a bench where a
 *     misconfiguration cannot be caught before it ships, which is the only reason a bench exists.
 *
 * WHAT IT DOES NOT DO: no TLS, no client certificates, no access rules. Everything that decides
 * WHO may read a page is MODx's own and unchanged — the pages are protected by the access model and an
 * anonymous request still 302s to sso-login. This is a door onto a bench, bound to loopback,
 * reached over an ssh forward.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');

// 4) core/ is never served — before anything else, and it answers like the vhosts do.
if (preg_match('#^/core(/|$)#i', $path)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "403 — core/ is not served from a bench either.\n";
    return true;
}

// 1) a real file is a real file.
$candidate = $docroot . $path;
if ($path !== '/' && is_file($candidate)) {
    return false;
}

// 1b) A DIRECTORY WITH ITS OWN index.php IS ITS OWN APPLICATION — /manager and /connectors are
// MODx's, not pages of the site. Without this they fall through to the front controller, which
// looks up `manager/` as a resource alias and answers the context's 404: the manager is present
// on disk and unreachable, and the 404 comes from the site rather than from the missing thing.
// nginx gets this for free from `try_files $uri $uri/`; php -S does not.
if ($path !== '/' && is_dir($candidate) && is_file(rtrim($candidate, '/') . '/index.php')) {
    if (substr($path, -1) !== '/') {          // /manager -> /manager/, as any web server does
        header('Location: ' . $path . '/', true, 301);
        return true;
    }
    $_SERVER['SCRIPT_NAME'] = rtrim($path, '/') . '/index.php';
    require rtrim($candidate, '/') . '/index.php';
    return true;
}

// 2) friendly URL -> q, exactly as the nginx rewrite produces it: leading slash removed, verbatim.
$q = ltrim($path, '/');
if ($q !== '') {
    $_GET['q'] = $q;
    $_REQUEST['q'] = $q;
}

// 3) the tenant header is the edge's, never the caller's (the same overwrite nginx does).
unset($_SERVER['HTTP_X_TENANT']);
$tenant = (string) getenv('BENCH_TENANT');
if ($tenant !== '') {
    $_SERVER['HTTP_X_TENANT'] = $tenant;
}
require $docroot . '/index.php';
return true;
