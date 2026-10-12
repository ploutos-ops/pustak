/**
 * loginWidget — navbar login/logout control (SSO-only; replaces the [[!Login]] widget role).
 * Call uncached: [[!loginWidget]] — it must reflect the live session on every request.
 *
 * &variant=`estate` -> the estate title bar's own classes. Anything else, including the
 *               default, renders as it always has: this snippet is called TWICE on a page, once in
 *               the bar and once in the footer, and the bar's classes are styled for a dark bar.
 *
 * Logged in  -> the user's name + a logout link (logout_page runs GoogleSSOLogout).
 * Logged out -> a sign-in link to login_page (GoogleSSOStart), carrying returnUrl so the user
 *               returns to where they were after Google.
 */

$estate = ($modx->getOption('variant', $scriptProperties, '') === 'estate');
$whoClass  = $estate ? 'estate-bar-who' : 'navbar-text me-2';
$exitClass = $estate ? 'estate-bar-exit' : '';

$user = $modx->user;
if ($user && (int) $user->get('id') > 0) {
    $profile = $user->getOne('Profile');
    $name = $profile ? trim((string) $profile->get('fullname')) : '';
    if ($name === '') {
        $name = (string) $user->get('username');
    }
    $logout = $modx->makeUrl((int) $modx->getOption('logout_page'), '', '', 'full');
    // THE ESTATE'S OWN CLASSES: the greeting, then sign out, in that order — rule 6 of
    // claude/shared/design/estate-title-bar.md. The bar's stylesheet on the CDN styles both; this
    // snippet contributes no styling of its own.
    // Hover the name for the address this session is signed in as (the owner, 2026-10-04: two accounts
    // share his name, and only one of them is entitled to kuber's values). The Google-verified address
    // the sign-in stamped, else the account's own email; a Bootstrap tooltip, not the title box.
    $email = trim((string) ($_SESSION['sso.verified_email'] ?? ''));
    if ($email === '' && $profile) {
        $email = trim((string) $profile->get('email'));
    }
    $tip = $email !== '' ? ' data-bs-toggle="tooltip" data-bs-placement="bottom" title="' . htmlspecialchars($email, ENT_QUOTES) . '"' : '';
    return '<span class="' . $whoClass . '"' . $tip . '>' . htmlspecialchars($name, ENT_QUOTES) . '</span>'
         . '<a class="' . $exitClass . '" href="' . $logout . '" title="Sign out" aria-label="Sign out"><i class="bi bi-box-arrow-right"></i></a>';
}

// Same-site return path only (GoogleSSOStart re-validates it server-side before use).
$return = '/' . ltrim((string) ($_SERVER['REQUEST_URI'] ?? ''), '/');
$login = $modx->makeUrl((int) $modx->getOption('login_page'), '', ['returnUrl' => $return], 'full');
return '<a class="' . $exitClass . '" href="' . $login . '" title="Sign in" aria-label="Sign in"><i class="bi bi-box-arrow-in-right"></i></a>';
