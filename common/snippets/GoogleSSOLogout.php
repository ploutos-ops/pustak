/**
 * GoogleSSOLogout — end the MODx web session (SSO-only replacement for the Login extra's logout).
 * Placed on each context's logout_page resource as [[!GoogleSSOLogout]].
 *
 * Since the Login package is removed and Google is the only login path, logout is just: drop THIS
 * context's web session and return the user to the login entry. We deliberately do NOT hit Google's
 * logout endpoint — that would sign the user out of their entire Google account, not just this site.
 *
 * Mirrors what modLoginController::logout does internally (removeSessionContext + OnWebLogout), so
 * behaviour is unchanged from the user's point of view: click logout -> session gone -> login screen.
 */

$context = $modx->context->get('key');

$modx->invokeEvent('OnBeforeWebLogout', ['user' => $modx->user]);
$modx->user->removeSessionContext($context);
$modx->invokeEvent('OnWebLogout', ['user' => $modx->user]);

// AND THE IDENTITY THIS SITE ITSELF ISSUED. Dropping the context token was the whole of logout,
// which left every key the SSO callback wrote sitting in the session: sso.verified_email is what
// tableRenderer's &auth asserts to kuber and sso.id_token is what the bookkeeping-names plugin
// presents. A reader who clicked 'sign out' kept an identity this estate would still vouch for on
// the next page that asked. The owner, 2026-08-14: 'i cannot logout when manager is logged in' — the
// manager half is below; this half was true for everyone.
unset(
    $_SESSION['sso.verified_email'],
    $_SESSION['sso.id_token'],
    $_SESSION['sso.id_token_exp'],
    $_SESSION['sso.refresh_token'],
    $_SESSION['sso.returnUrl'],
    $_SESSION['sso.pending']
);

// THE MANAGER SESSION IS LEFT ALONE, AND SAID ALOUD. It lives in the `mgr` context and this
// snippet ends one context — the web one. Ending mgr here would log him out of the manager
// mid-edit, which is worse than the confusion it cures, so leaving it is deliberate. What was not
// deliberate is that nothing SAID so: he signed out with the manager open, the site still knew
// him, and the page offered no reason.
$mgrStillOpen = $modx->user && $modx->user->isAuthenticated('mgr');

// Return to the login entry (GoogleSSOStart); fall back to the site start if login_page is unset.
$target = (int) $modx->getOption('login_page') ?: (int) $modx->getOption('site_start');
$url = $modx->makeUrl($target, '', '', 'full');
if ($mgrStillOpen) {
    // Told, not hidden: anonymous to this site now, still authenticated to the manager, and only
    // the reader can decide whether to end that.
    $url .= (strpos($url, '?') === false ? '?' : '&') . 'mgr=open';
}
$modx->sendRedirect($url);
return '';
