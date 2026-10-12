/**
 * contextBase — the address of a context of THIS MODx, as MODx itself holds it.
 *
 *   [[!contextBase? &context=`corporate`]]   ->  https://<that context's own name>   (no trailing slash)
 *
 * WHY IT EXISTS. A page in one context links to a page in another — the green Corp button
 * (common/chunks/kuber.js). The address it opened came from a setting, `corp.base_url`, which was a
 * SECOND copy of a fact MODx already holds: every context has its own `site_url`. Two copies need a
 * list somewhere that keeps them in step on every ground, and on 2026-10-06 staging's list was
 * found a rename behind. This reads the one copy, so there is nothing to keep in step.
 *
 * CALL IT UNCACHED. On a ground that answers a context on more than one name, the CURRENT
 * context's address is decided per request (plugin edgeAddress); a cached call would freeze the
 * first name to render the page.
 *
 * ANSWERS NOTHING rather than guess: no context given, the manager, a key no context has, or a
 * context whose site_url is no http(s) address. The caller leaves its control undecorated — a dead
 * button is worse than an absent one (kuber.js states the same rule).
 */
$key = trim((string) ($scriptProperties['context'] ?? ''));
if ($key === '' || $key === 'mgr') {
    return '';
}
// The current context answers with the address of THIS request, which edgeAddress may have set.
if ($modx->context && (string) $modx->context->get('key') === $key) {
    $url = (string) $modx->getOption('site_url');
} else {
    $ctx = $modx->getContext($key);
    $url = $ctx ? (string) $ctx->getOption('site_url') : '';
}
$url = trim($url);
return preg_match('#^https?://[^/\s]+#i', $url) ? rtrim($url, '/') : '';
