/**
 * GoogleSSOStart — begin the Google OAuth 2.0 (OpenID Connect) login.
 *
 * Placed on each context's login / unauthorized page (replaces the [[!Login]] form for SSO).
 * An anonymous user first sees a branded per-tenant sign-in screen (the Bootstrap.5.googleLogin
 * chunk, rendered through pdoTools/Fenom); its button re-enters this snippet with `?sso=go`, which
 * is when the OAuth redirect to Google actually fires. On return, GoogleSSOCallback (on the callback
 * resource) completes the login. The button step (vs a silent auto-redirect) is what
 * gives each context a visible, brandable login page and avoids bouncing straight off-site.
 *
 * Authorization-code flow (server-side): response_type=code, the code is exchanged for an
 * id_token in the callback over TLS directly against Google's token endpoint. The redirect_uri
 * is the PUBLIC https edge for this context (infra terminates TLS); it MUST byte-match a URI
 * registered on the shared Google OAuth client — Google rejects any other.
 *
 * Config (system settings, namespace `sso`, set by setup/12-sso.py):
 *   sso.google_client_id      — the shared project_sso web client id
 *
 * No user input is trusted here except returnUrl, which is stored server-side (session) and only
 * ever used as a same-site path by the callback — never reflected.
 */

// Already signed in? Don't re-run OAuth — go straight to the home router. This guards against a
// redirect loop: after the SSO repoint, login_page IS this resource, so anything that sends a
// logged-in user to login_page would otherwise re-enter the OAuth dance.
// AUTHENTICATED IN THIS CONTEXT, not merely KNOWN. The loop this guards against is a user who can
// already read the site being sent back to the sign-in page; a manager session is neither of those
// things here. The owner, 2026-08-14, testing sign-out on production: 'with manager open, when i sign out,
// i go to home'. That is this line — getUser() still returns him from the mgr session, so the
// sign-out sent him to sso-login and sso-login bounced him straight to /home, so he never saw the
// card or the notice saying his manager session was still open. The sign-out was working; the
// sign-in page was hiding it.
$current = $modx->getUser();
$ctxKey  = (string) $modx->context->get('key');
if ($current && (int) $current->get('id') > 0 && $current->isAuthenticated($ctxKey)) {
    $modx->sendRedirect($modx->makeUrl((int) $modx->getOption('site_start'), '', '', 'full'));
    return '';
}

// WHICH PROVIDER THIS CONTEXT USES. Google for public names, the estate's OIDC provider for
// internal-zone names — the owner's rule, and not a preference: Google will not accept a redirect URI on a
// name it cannot resolve publicly, so an internal-zone context cannot complete a Google sign-in at all.
// One resolver, shared with GoogleSSOCallback, because the provider and client id chosen here and
// there must be identical or the token exchange fails with a mismatch and no useful message.
require_once rtrim(MODX_CORE_PATH, '/') . '/components/pustak/lib/SsoConfig.php';
$sso = SsoConfig::forContext($modx);
if ($sso->fault !== '') {
    // NAME THE FAULT IN THE LOG, and give the screen something to quote. "Single sign-on is not
    // configured" was the whole message here, for four different causes.
    $modx->log(modX::LOG_LEVEL_ERROR, '[GoogleSSOStart] [SSO-00] ' . $sso->fault);
    return 'Single sign-on is not configured for this site. Please contact the administrator '
         . 'and quote <strong>SSO-00</strong>.';
}
$clientId = $sso->clientId;

// Where the user was originally heading (deep-link return-to-original after auth). Kept
// server-side so it can never be turned into an open-redirect via the query string.
$returnUrl = '';
if (!empty($_REQUEST['returnUrl'])) {
    $candidate = (string) $_REQUEST['returnUrl'];
    // Same-site only: a root-relative path, never a scheme/host and never protocol-relative.
    if ($candidate[0] === '/' && strpos($candidate, '//') !== 0) {
        $returnUrl = $candidate;
    }
}

// Branded sign-in screen: unless the user has clicked through (`?sso=go`), render the per-tenant
// login card and stop here. Its button links back with sso=go (+ the same-site returnUrl), so the
// OAuth redirect below only fires on that explicit click — the login page is visible and brandable
// rather than an instant off-site bounce.
if (($_GET['sso'] ?? '') !== 'go') {
    $args = ['sso' => 'go'];
    if ($returnUrl !== '') {
        $args['returnUrl'] = $returnUrl;
    }
    $startUrl = $modx->makeUrl((int) $modx->resource->get('id'), '', $args, 'full');
    /** @var \ModxPro\PdoTools\CoreTools $pdo */
    $pdo = $modx->services->get(\ModxPro\PdoTools\CoreTools::class);
    // A MANAGER SESSION IS THE ONE THING THAT MAKES 'SIGNED OUT' UNTRUE, so the sign-out sends
    // ?mgr=open and this page says it. Without it the reader signs out, still sees signed-in
    // pages, and has no way to know why — which is what the owner met. The notice is passed to
    // the chunk; a chunk that does not use the placeholder renders exactly as it did.
    $mgrNotice = '';
    if (($_GET['mgr'] ?? '') === 'open') {
        $mgrNotice = 'You are signed out of this site. Your MANAGER session is still open, which '
                   . 'is why some pages may still recognise you — sign out of the manager as well '
                   . 'to be fully signed out.';
    }
    // THE BUTTON MUST NAME THE PROVIDER IT ACTUALLY GOES TO. It said "Sign in with Google" on
    // every context, including the ones SsoConfig points at the estate OIDC provider — so on a bench or
    // an internal-zone context the button promised Google and went somewhere else, and when that somewhere
    // else was unreachable the failure read as "Google is down". The owner, 2026-08-20, on the local
    // instance: "when i click on google, it says cannot connect." It was never going to Google.
    //
    // Resolved from the SAME $sso the redirect uses, so the label cannot disagree with the
    // destination — the two are one fact. Google keeps its icon and its wording; anything else
    // says "estate sign-in", because a mock and a real estate provider are the same door to the
    // person clicking it and naming the vendor would be the same defect one layer down.
    $isGoogle = ($sso->provider === 'google');
    // THE SIGN-IN CARD WEARS THE COMPANY'S OWN LOGO, THE BIRD WHERE IT HAS NONE (the
    // owner, 2026-10-07: "top left and tab icon to be veena and sign-in to show company logo",
    // "each company's own logo", "if empty, then bird"). `site_logo` is written by setup/04 from the
    // estate's `logo` declaration. Only an https URL is used — anything else in the row is ignored
    // rather than rendered into an <img> on a page anyone can load. The bar and the tab stay the veena.
    $logo = trim((string) $modx->getOption('site_logo'));
    if (strpos($logo, 'https://') !== 0) {
        $logo = rtrim((string) $modx->getOption('estate_cdn_url'), '/') . '/brand/20260730/favicon.svg';
    }
    // A privacy link only where one is configured: `privacy_url`, a system or context setting. The
    // estate has no MODx privacy policy page, and a link to nothing is worse than no link.
    $privacy = trim((string) $modx->getOption('privacy_url'));
    return $pdo->getChunk('Bootstrap.5.googleLogin', [
        'site_name' => (string) $modx->getOption('site_name'),
        'startUrl'  => $startUrl,
        'notice'    => $mgrNotice,
        'logo'      => $logo,
        'privacy'   => (strpos($privacy, 'https://') === 0 || strpos($privacy, '/') === 0) ? $privacy : '',
        'btn_label' => $isGoogle ? 'Continue with Google' : 'Estate sign-in',
        'btn_icon'  => $isGoogle ? 'bi-google' : 'bi-box-arrow-in-right',
    ]);
}

$modx->startSession();

// CSRF state + OIDC nonce, both bound to this session and single-use.
//
// ONE ENTRY PER SIGN-IN STARTED, KEYED BY ITS STATE — not one slot per session. The owner,
// 2026-09-23: "the sign in to local modx using oidc stopped working again." A session held ONE
// state, so a second sign-in started in the same browser overwrote the first, and the first came
// back SSO-01 "state mismatch". On the dev box every context is `localhost` on its own port, a
// cookie ignores the port, so all four sites share one session — and a protected page starts a
// sign-in by itself, so any other open tab was enough. Production has one name per context, and
// the same collision still happens with two tabs of one site. Reproduced on the bench before the
// fix: start on 8312, start on 8314, finish 8312 -> SSO-01.
//
// Bounded both ways: an entry older than ten minutes is dropped (a sign-in is a round trip of
// seconds), and no more than ten are kept, oldest first out.
$state = bin2hex(random_bytes(16));
$nonce = bin2hex(random_bytes(16));
$pending = is_array($_SESSION['sso.pending'] ?? null) ? $_SESSION['sso.pending'] : [];
$now = time();
$pending = array_filter($pending, function ($p) use ($now) {
    return is_array($p) && ($now - (int) ($p['at'] ?? 0)) < 600;
});
$pending[$state] = [
    'nonce'     => $nonce,
    'returnUrl' => $returnUrl,
    'ctx'       => $modx->context->get('key'),
    'at'        => $now,
];
$_SESSION['sso.pending'] = array_slice($pending, -10, null, true);

// The public edge for THIS context — see SsoConfig::redirectUri for why the scheme is derived
// rather than written, and why the path is single-sourced from sso.callback_path.
// ONE definition of the callback URL, in SsoConfig, shared with the callback. It used to be
// these two lines in both files with a comment in each asking the next person to keep them
// byte-identical — which is a convention, and a convention is not a mechanism.
$redirectUri  = SsoConfig::redirectUri($modx);

$params = [
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'nonce'         => $nonce,
];
// `access_type` and `prompt` are GOOGLE parameters. A conforming provider is
// entitled to reject a parameter it does not know, and the estate's IdP has no account chooser to
// scope, so they are sent only down the Google path.
// OFFLINE, SO THE TOKEN CAN BE RENEWED. Google's id_token lives an hour and the bookkeeping-names plugin ends
// the session the moment it is stale, so a reader was signed out every hour (the owner, 2026-10-05).
// access_type=offline makes the code exchange carry a refresh_token, which the ssoRenew plugin
// trades for a new id_token before the old one runs out. `consent` is what makes Google hand the
// refresh_token on EVERY sign-in, not only the first one this client ever saw; without it a
// returning reader gets none and is back to the hourly sign-out.
if ($sso->provider === 'google') {
    $params['access_type'] = 'offline';
    $params['prompt']      = 'select_account consent';
}

// The authorization endpoint comes from the resolved provider: Google's, the bench's
// mock-oidc, or the estate's OIDC provider for an internal-zone context. The guard that decides which is
// permitted lives in SsoConfig and is the zone suffix, not a hostname.
$modx->sendRedirect($sso->authEndpoint . '?' . http_build_query($params));
return '';
