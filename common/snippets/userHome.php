/**
 * userHome — send a signed-in user to their home for THIS context.
 *
 * Each context's site_start is this router rather than a page, so "the family site" means the same
 * URL for everyone and lands each person where they belong.
 *
 * WHY IT IS WRITTEN THIS DEFENSIVELY. The first version was three lines and computed one id:
 * homePages[ctx] if the profile had one, else nav_root_id. It then handed that id straight to
 * makeUrl() and the result straight to sendRedirect(). Every user's profile pointed at a home that
 * had been deleted — 13/27/41, the duplicate 'home' twins, while the live ones are 12/26/40 — so
 * makeUrl() returned an empty string, and MODx's sendRedirect() answers an empty URL by logging
 * "Attempted to redirect to an empty URL" and RETURNING. No redirect, no error on screen: the
 * router rendered itself, empty, and looked like a page that had simply lost its content.
 *
 * A dangling id must never be silence. So:
 *
 *   - candidates are tried IN ORDER — the user's own home first, then the context's nav_root_id —
 *     and the first one that actually resolves wins. A stale profile entry can no longer take the
 *     landing page down with it; it just falls through to the context default.
 *   - a candidate must EXIST, in this context, undeleted and published. Checked against the
 *     resource itself rather than inferred from makeUrl() returning something, because those are
 *     different questions: a resource with no alias also yields an empty URL.
 *   - a candidate equal to the page we are on is skipped. Redirecting a router to itself is an
 *     infinite loop, and nav_root_id is one manager edit away from being this resource's id.
 *   - if NOTHING resolves the failure is loud: every candidate is named in the MODx error log and
 *     the page carries a comment saying so. Rendering blank is what hid this for months.
 *
 * The same reasoning applies to the anonymous branch: an unresolvable login_page used to redirect
 * to nowhere, which reads as "the login page is broken" rather than "login_page is misconfigured".
 */

/** The first id that is a real, reachable page in this context — or 0 if none of them are. */
$firstUsable = function (array $ids, $ctx, $here) use ($modx) {
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id <= 0 || $id === $here) {
            continue;
        }
        $res = $modx->getObject('modResource', [
            'id'          => $id,
            'context_key' => $ctx,
            'deleted'     => 0,
            'published'   => 1,
        ]);
        if (!$res) {
            continue;
        }
        $url = $modx->makeUrl($id, '', '', 'full');
        if ($url !== '') {
            return $id;
        }
    }
    return 0;
};

$ctx  = $modx->context->get('key');
$here = $modx->resource ? (int) $modx->resource->get('id') : 0;
$user = $modx->getUser();

if ($user->get('id') == 0) {
    $loginId = $firstUsable([(int) $modx->getOption('login_page')], $ctx, $here);
    if ($loginId) {
        $modx->sendRedirect($modx->makeUrl($loginId, '', '', 'full'));
        return '';
    }
    $modx->log(modX::LOG_LEVEL_ERROR,
        "userHome: context '$ctx' has no usable login_page (tried "
        . (int) $modx->getOption('login_page') . ') — anonymous visitors cannot sign in.');
    return '<!-- userHome: login_page does not resolve for this context -->';
}

$profile = $user->getOne('Profile');
// MODx's modUserProfile.extended is a JSON-typed field: get() returns it already
// decoded as an array. json_decode() on an array throws a TypeError in PHP 8 (the
// double-decode bug). Decode only if MODx handed back a raw string.
$extended = $profile ? $profile->get('extended') : [];
if (is_string($extended)) { $extended = json_decode($extended, true); }
$extended = is_array($extended) ? $extended : [];
$homePages = $extended['homePages'] ?? [];

// The user's own home first, the context default second. Both are candidates, not commitments.
$candidates = [];
if (isset($homePages[$ctx])) {
    $candidates[] = (int) $homePages[$ctx];
}
$candidates[] = (int) $modx->getOption('nav_root_id');

$docId = $firstUsable($candidates, $ctx, $here);
if ($docId) {
    // FORWARDED, NOT REDIRECTED (the owner, 2026-10-05: "all my home pages have /home in the url").
    // The reader asked for the site; they get their home page AT the site's own address. A
    // redirect put /home (or /london) in the address bar of every visit. sendForward serves the
    // home resource under this request, so the address stays where the reader typed it.
    $modx->sendForward($docId);
    return '';
}

$tried = implode(', ', array_map('intval', $candidates)) ?: 'none';
$modx->log(modX::LOG_LEVEL_ERROR,
    "userHome: no home resolves for user '" . $user->get('username') . "' in context '$ctx'. "
    . "Tried: $tried. Each must exist in this context, be published and not deleted.");
return '<!-- userHome: no home page resolves for this user in this context (tried ' . $tried
     . ') — see the MODx error log -->';
