/**
 * GoogleSSOCallback — complete the Google OAuth 2.0 / OIDC login. Lives on /sso/callback in each
 * SSO-gated context.
 *
 * Flow (authorization-code, server-side):
 *   1. Verify the `state` returned by Google matches the one this session issued (CSRF).
 *   2. Exchange the `code` for tokens at Google's token endpoint over TLS (authenticated with the
 *      client secret) — the id_token thus comes DIRECTLY from Google over an authenticated TLS
 *      channel, so per Google's guidance signature re-verification is not required; we still
 *      validate the claims (aud / iss / exp / nonce) as defense in depth.
 *   3. Require email_verified, then apply the OPTIONAL allow-list clamp (off by default).
 *   4. Map the verified email to a MODx user (find; provision only if sso.auto_provision=1). This
 *      is THE gate: with auto_provision off, no MODx user for the email => denied.
 *   5. Establish the MODx web session for THIS context (addSessionContext) + fire OnWebLogin.
 *   6. Redirect to the stored same-site returnUrl, else the userHome router.
 *
 * Access model — plain MODx ("wall not curtain"): SSO proves WHO you are; access is decided
 * by MODx as always — a valid active user + resource-group/RBAC. Managing who gets in and what they
 * see is done in the MODx manager (create the user with their Google email; put them in groups),
 * never a separate list. The allow-list settings below are an optional temporary clamp, not the
 * everyday control.
 *
 * Requires server egress to accounts.google.com / oauth2.googleapis.com for the token exchange.
 *
 * Config (system settings, namespace `sso`):
 *   sso.provider               — 'google' (default) or 'oidc'. PER CONTEXT: one MODx serves
 *                                four contexts and they will not all be internal. Which settings
 *                                below apply is decided by this, in the estate library's
 *                                SsoConfig.php, which GoogleSSOStart reads too so the two halves of
 *                                one sign-in cannot disagree.
 *   sso.google_client_id, sso.google_client_secret — the shared project_sso web client
 *   sso.oidc_issuer / sso.oidc_auth_endpoint / sso.oidc_token_endpoint /
 *   sso.oidc_userinfo_endpoint / sso.oidc_jwks_uri / sso.oidc_client_id / sso.oidc_client_secret
 *                              — the estate's OIDC provider, for a context on an internal-zone name.
 *                                The owner's rule: internal names authenticate against the
 *                                estate provider, public names against Google — because Google will
 *                                not issue a callback to a name it cannot resolve publicly.
 *   sso.auto_provision         — 1 to create a MODx user on first valid login, else unknown = deny (0)
 *   sso.provision_usergroup    — user-group name new users join (default: Members)
 *                                THESE FOUR ARE UNCHANGED BY THE PROVIDER CHOICE, deliberately:
 *                                the identity SOURCE moves, the authorisation rules do not.
 *   sso.auth_endpoint / sso.token_endpoint / sso.issuer — the bench's mock-oidc overrides on the
 *                                GOOGLE path. Predates sso.provider and is unchanged.
 *                                HONOURED ONLY INSIDE THE INTERNAL ZONE, so a bench can be pointed
 *                                at mock-oidc and production cannot.
 *
 * Reference codes: SSO-00 configuration, 01 state, 02 provider error, 03 no code, 05 token
 * exchange, 06 no id_token, 07/08 malformed, 09 iss, 10 aud, 11 exp, 12 nonce, 13 email,
 * 14 provisioning, 15 session, 16/17 userinfo, 18 signature, 19 no JWKS. SSO-04 is RETIRED — its check moved into SsoConfig
 * and answers SSO-00 now; the number is not reused, because a code that meant one thing last month
 * and another this month is worse than no code.
 */

/**
 * TWO KINDS OF OUTCOME, and telling them apart is the point of this block.
 *
 * Until 2026-07-28 every one of the eighteen ways this snippet can stop returned the same sentence
 * — "Sign-in could not be completed" — and wrote the real reason to the MODx error log. That log is
 * on production, which means the person who hit the problem could not see it, and neither could I.
 * The owner signed in with the wrong one of six Google accounts and the screen gave him nothing to act
 * on; the answer ("no MODx user for <the signed-in address>") existed only in a log he could not read.
 *
 *   $deny  — the visitor's own situation, not a fault: their address is not recognised, not on the
 *            allow-list, or their account is inactive. NAME THE ADDRESS THEY USED and offer a way
 *            back to the account chooser. This is not account enumeration: it is their own
 *            Google-verified address handed back to the person who just proved they own it, and it
 *            says nothing about any other account. Logged at WARN, because nothing is broken.
 *
 *   $fail  — something is actually wrong (replayed callback, token exchange, malformed id_token).
 *            Detail still goes only to the log, but the screen carries a short reference code so a
 *            report like "it says SSO-05" is diagnosable without log access at all.
 */
$loginUrl = function () use ($modx) {
    // The login entry sends prompt=select_account, so this lands on the chooser. If it does not
    // resolve, emit no link rather than a dead one — an empty href is the dead-link fault in miniature.
    $id = (int) $modx->getOption('login_page');
    if ($id <= 0) { return ''; }
    $url = $modx->makeUrl($id, '', '', 'full');
    return is_string($url) ? $url : '';
};

$deny = function ($logMsg, $email, $headline, $detail) use ($modx, $loginUrl) {
    $modx->log(modX::LOG_LEVEL_WARN, '[GoogleSSOCallback] ' . $logMsg);
    @http_response_code(403);
    $e    = htmlspecialchars((string) $email, ENT_QUOTES, 'UTF-8');
    $url  = $loginUrl();
    $link = $url !== ''
        ? '<p><a class="btn btn-primary" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
          . '">Sign in with a different account</a></p>'
        : '';
    return '<div class="sso-denied">'
         . '<h3>' . $headline . '</h3>'
         . '<p>You signed in as <strong>' . $e . '</strong>.</p>'
         . '<p>' . $detail . '</p>'
         . $link
         . '<p class="text-muted">If you believe this address should have access, contact the '
         . 'administrator and quote the address above.</p>'
         . '</div>';
};

$fail = function ($logMsg, $ref = 'SSO-00') use ($modx) {
    $modx->log(modX::LOG_LEVEL_ERROR, '[GoogleSSOCallback] [' . $ref . '] ' . $logMsg);
    // Detail stays out of the browser; the reference code is what makes a report actionable.
    @http_response_code(403);
    $r = htmlspecialchars($ref, ENT_QUOTES, 'UTF-8');
    return '<div class="sso-failed">'
         . '<h3>Sign-in could not be completed</h3>'
         . '<p>Please try again. If it keeps happening, contact the administrator and quote '
         . '<strong>' . $r . '</strong>.</p>'
         . '</div>';
};

$modx->startSession();

// --- 1. CSRF state (single-use) ---------------------------------------------------------------
// The sign-in this callback finishes is the one its state names (see the start snippet for why a
// session holds several). Taken and burned regardless of outcome, so each state is single-use.
$gotState = (string) ($_GET['state'] ?? '');
$pending  = is_array($_SESSION['sso.pending'] ?? null) ? $_SESSION['sso.pending'] : [];
$started  = ($gotState !== '' && isset($pending[$gotState]) && is_array($pending[$gotState]))
          ? $pending[$gotState] : null;
unset($pending[$gotState]);
$_SESSION['sso.pending'] = $pending;
if ($started !== null && (time() - (int) ($started['at'] ?? 0)) >= 600) {
    $started = null;                                  // older than any real round trip: refused
}

// WHERE THE READER WAS HEADING, TAKEN NOW — BEFORE ANYTHING TOUCHES THE SESSION.
// A signed-out visit to the claude context's /capability on its internal-zone name arrives here with sso.returnUrl set by
// the start snippet, and the owner still landed on /home. The value was read at step 6, AFTER
// runProcessor('security/login'), and MODx's login replaces the session — so by the time this
// asked for it, it was reading a session that had never held it. The redirect then fell through
// to the userHome router, which is exactly the /home he saw. Every deep link shared by the
// internal-zone name lost its destination this way, and nothing about it looked broken.
$returnUrl = (string) ($started['returnUrl'] ?? '');
if ($started === null) {
    return $fail('state mismatch (possible CSRF, or a stale/replayed callback)', 'SSO-01');
}

if (!empty($_GET['error'])) {
    return $fail('Google returned error=' . preg_replace('/[^a-z_]/', '', (string) $_GET['error']), 'SSO-02');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    return $fail('no authorization code in callback', 'SSO-03');
}

// --- config -----------------------------------------------------------------------------------
// WHICH PROVIDER THIS CONTEXT USES — resolved by the same class GoogleSSOStart used, so the
// client id and the redirect_uri cannot drift between the two halves of one sign-in.
require_once rtrim(MODX_CORE_PATH, '/') . '/components/pustak/lib/SsoConfig.php';
$sso = SsoConfig::forContext($modx);
if ($sso->fault !== '') {
    return $fail($sso->fault, 'SSO-00');
}
$clientId     = $sso->clientId;
$clientSecret = $sso->clientSecret;
// ONE GATE (the owner 2026-10-05: "let us say i want to get rid of this additional list" /
// "please do"). Who signs in is decided by MODx alone: an active user for the verified address
// (auto_provision off, so an unknown address is refused, never created) and that user's membership.
// The sso.allowed_emails / sso.allowed_domains clamp that sat in front of it is gone.
// ONE definition, shared with GoogleSSOStart. Every conforming provider compares the
// redirect_uri sent at authorisation with the one sent at token exchange; this used to be the same
// two lines in both files, kept identical by a comment asking nicely.
$redirectUri  = SsoConfig::redirectUri($modx);

// --- 2. exchange code for tokens --------------------------------------------------------------
// WHICH endpoint, and WHICH issuers are acceptable, both come from SsoConfig. THE GUARD is
// unchanged and now states a rule rather than a testing affordance: a non-Google provider is
// accepted ONLY inside the estate's private DNS zone.
//
// The test is the ZONE SUFFIX, not a hostname — the internal zone resolves nowhere outside the estate, so it
// is a property of the network rather than a name anyone has to maintain. Hardcoding
// one bench hostname here would be the known fault (a domain written into code), whereas asking "is
// this host in the private zone" stays true as contexts are added, renamed or moved.
//
// An earlier version of this guard tested for plain http instead. It would have failed silently the
// day the bench is given TLS — which is an open ask — by disabling the mock exactly when someone had
// just made the bench more production-like. The owner caught it.
//
// THIS SNIPPET DOES NOT VERIFY THE id_token SIGNATURE. On the Google path that is Google's own
// stated position: the token arrives on a direct server-to-server POST to Google's endpoint over
// TLS. The OIDC path inherits the reasoning WITHOUT the TLS, because the estate's internal zone is
// plain http today — a weaker assumption, written down here rather than left to be discovered. The
// fix is one parameter on the estate's single JWT verifier and is an open ask; see the note at
// the foot of SsoConfig.php.
$tokenEndpoint = $sso->tokenEndpoint;
$issuersOk     = $sso->issuers;
$ch = curl_init($tokenEndpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
$body = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$cerr = curl_error($ch);
curl_close($ch);
if ($body === false || $http !== 200) {
    return $fail("token exchange failed (http=$http, curl=$cerr)", 'SSO-05');
}
$tok = json_decode($body, true);
if (!is_array($tok) || empty($tok['id_token'])) {
    return $fail('token response has no id_token', 'SSO-06');
}

// --- VERIFY the id_token on the oidc path, parse-and-trust on the Google one ------------------
// The asymmetry is deliberate and is the whole reason kuber was asked for one parameter.
//
// GOOGLE: unchanged. The token arrived on a direct server-to-server POST to Google's own endpoint
// over TLS, which is Google's own stated position, and production runs down this branch.
//
// OIDC: the same reasoning WITHOUT the TLS, because the estate's internal zone is plain http. So
// the signature is checked against the provider's published JWKS before a single claim is
// believed — through `Kuber\Core\GoogleToken`, the estate's ONE verifier, which now takes an
// issuer list (kuber-app ed78a3ab). No second implementation exists, and that is on purpose.
if ($sso->provider === 'oidc') {
    require_once rtrim(MODX_CORE_PATH, '/') . '/components/pustak/lib/GoogleToken.php';
    $jwksUri = $sso->jwksUri;
    if ($jwksUri === '') {
        // Neither configured nor discoverable. REFUSE — never fall through to parse-and-trust,
        // which would silently downgrade the one control this branch exists for.
        return $fail('no JWKS URL: sso.oidc_jwks_uri is empty and ' . implode(',', $sso->issuers)
                   . ' published no jwks_uri in its discovery document', 'SSO-19');
    }
    $why = null;
    $verifiedEmail = \Kuber\Core\GoogleToken::verify(
        (string) $tok['id_token'],
        [$clientId],
        $why,
        function () use ($jwksUri) {
            $c = curl_init($jwksUri);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                                   CURLOPT_HTTPHEADER => ['Accept: application/json']]);
            $b = curl_exec($c);
            $ok = curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200;
            curl_close($c);
            $j = ($ok && is_string($b)) ? json_decode($b, true) : null;
            return is_array($j) ? $j : null;
        },
        $sso->issuers
    );
    if ($verifiedEmail === null) {
        // The token did not verify. NOT a $deny — the visitor did nothing wrong and there is
        // nothing for them to fix, so it is a fault with a code and the reason goes to the log.
        return $fail('id_token failed verification against ' . $jwksUri . ': ' . (string) $why, 'SSO-18');
    }
}

// --- parse the id_token payload ---------------------------------------------------------------
$parts = explode('.', $tok['id_token']);
if (count($parts) !== 3) {
    return $fail('malformed id_token', 'SSO-07');
}
// JWT segments are base64URL with no padding; re-pad before decoding (unpadded base64_decode is
// unreliable). Google delivered this over TLS from its token endpoint, so we parse-and-trust the
// claims but still validate aud/iss/exp/nonce below.
$seg = strtr($parts[1], '-_', '+/');
$seg .= str_repeat('=', (4 - strlen($seg) % 4) % 4);
$payload = json_decode(base64_decode($seg, true), true);
if (!is_array($payload)) {
    return $fail('unreadable id_token payload', 'SSO-08');
}

// --- 3. validate claims + domain lock ---------------------------------------------------------
$iss = $payload['iss'] ?? '';
if (!in_array($iss, $issuersOk, true)) {
    return $fail('bad iss: ' . $iss, 'SSO-09');
}
if (($payload['aud'] ?? '') !== $clientId) {
    return $fail('aud does not match our client_id', 'SSO-10');
}
if ((int) ($payload['exp'] ?? 0) <= time()) {
    return $fail('id_token expired', 'SSO-11');
}
$expectedNonce = (string) ($started['nonce'] ?? '');
if ($expectedNonce === '' || ($payload['nonce'] ?? '') !== $expectedNonce) {
    return $fail('nonce mismatch (replay?)', 'SSO-12');
}

// A conforming OIDC provider MAY leave email out of the id_token and serve it from /userinfo —
// Google always includes it, so this path never ran before the OIDC path existed. Read only when it is actually
// missing, and only with the access_token this exchange just returned.
if (($payload['email'] ?? '') === '' && $sso->userinfoEndpoint !== '' && !empty($tok['access_token'])) {
    $uch = curl_init($sso->userinfoEndpoint);
    curl_setopt_array($uch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Accept: application/json',
                                   'Authorization: Bearer ' . $tok['access_token']],
    ]);
    $ubody = curl_exec($uch);
    $uhttp = curl_getinfo($uch, CURLINFO_RESPONSE_CODE);
    curl_close($uch);
    $ui = is_string($ubody) ? json_decode($ubody, true) : null;
    if ($uhttp !== 200 || !is_array($ui)) {
        return $fail("id_token carried no email and userinfo failed (http=$uhttp)", 'SSO-16');
    }
    // The SUBJECT must match. Without this check, a userinfo response for a different account
    // would silently replace the identity the id_token proved.
    if (($ui['sub'] ?? null) !== ($payload['sub'] ?? '')) {
        return $fail('userinfo sub does not match the id_token sub', 'SSO-17');
    }
    $payload['email']          = $ui['email'] ?? '';
    $payload['email_verified'] = $ui['email_verified'] ?? false;
}

$email = strtolower(trim((string) ($payload['email'] ?? '')));
$emailVerified = ($payload['email_verified'] ?? false) === true
              || ($payload['email_verified'] ?? '') === 'true';
if ($email === '' || !$emailVerified) {
    return $fail('email missing or not verified', 'SSO-13');
}

// --- 4. map email -> MODx user ----------------------------------------------------------------
$user = null;
$profile = $modx->getObject('modUserProfile', ['email' => $email]);
if ($profile) {
    $user = $modx->getObject('modUser', (int) $profile->get('internalKey'));
}

if (!$user) {
    if ((int) $modx->getOption('sso.auto_provision') !== 1) {
        return $deny("no MODx user for $email and auto-provision is off", $email,
                 'This address is not recognised',
                 'There is no account on this site for that address. If you have more than one Google account, you may have signed in with a different one than you meant to.');
    }
    // Provision a disabled-password SSO user (login is only ever via Google).
    $user = $modx->newObject('modUser');
    $user->set('username', $email);
    $user->set('active', 1);
    // Random unusable local password (never used — SSO is the only login path).
    $user->set('password', bin2hex(random_bytes(24)));
    $newProfile = $modx->newObject('modUserProfile');
    $newProfile->set('email', $email);
    $newProfile->set('fullname', (string) ($payload['name'] ?? $email));
    $user->addOne($newProfile, 'Profile');
    if (!$user->save()) {
        return $fail("failed to provision user for $email", 'SSO-14');
    }
    // Join the configured member group so RBAC sees them.
    $groupName = (string) $modx->getOption('sso.provision_usergroup') ?: 'Members';
    $group = $modx->getObject('modUserGroup', ['name' => $groupName]);
    if ($group) {
        $member = $modx->newObject('modUserGroupMember');
        $member->set('user_group', $group->get('id'));
        $member->set('member', $user->get('id'));
        $member->save();
    } else {
        $modx->log(modX::LOG_LEVEL_WARN, "[GoogleSSOCallback] provision group '$groupName' not found for $email");
    }
    $modx->log(modX::LOG_LEVEL_INFO, "[GoogleSSOCallback] provisioned SSO user $email");
}

if (!$user->get('active')) {
    return $deny("user $email is inactive", $email,
             'This account is not active',
             'An account exists for that address, but it has been deactivated.');
}

// --- 5. establish the MODx web session for this context + fire the login event ----------------
$ctx = $modx->context->get('key');
$modx->user = $user;
$user->addSessionContext($ctx);
// RECORD THAT THIS ADDRESS WAS PROVIDER-VERIFIED, not merely typed into a profile.
// tableRenderer's &auth asserts `sub` to kuber, and the only claim MODx can honestly make is
// "an identity provider verified this person". A profile email cannot support that claim — it is
// whatever a manager entered — so &auth reads THIS, and a login that did not come through here
// (a manager password login) asserts nobody and renders blank. Written after the session context
// exists, so the two facts are never out of step.
$_SESSION['sso.verified_email'] = $email;
// KEEP THE TOKEN ITSELF (the owner 2026-08-03: "google token, not your session, decides the
// logged in user"). Until today this was discarded here and downstream callers asserted the
// reader's identity from the session above — MODx vouching for someone. The rule now is: get a
// good token, validate it, pass it on; no valid token and this side loses access too.
// Stored raw because a consumer must be able to re-verify it; it never reaches a page (the
// session store is server-side) and it dies with the session.
$_SESSION['sso.id_token'] = (string) $tok['id_token'];
$_SESSION['sso.id_token_exp'] = (int) ($payload['exp'] ?? 0);
// THE RENEWAL KEY: what the ssoRenew plugin trades for a new id_token before this one
// expires. Server-side session only, like the token, and it dies with the session. A provider
// that sent none is logged: that reader will be signed out when the hour is up, as before.
if (!empty($tok['refresh_token'])) {
    $_SESSION['sso.refresh_token'] = (string) $tok['refresh_token'];
} else {
    unset($_SESSION['sso.refresh_token']);
    $modx->log(modX::LOG_LEVEL_ERROR, "[GoogleSSOCallback] the $sso->provider token response for $email "
        . 'carried no refresh_token — this session cannot be renewed and ends when the id_token expires');
}
$modx->invokeEvent('OnWebLogin', [
    'user'       => $user,
    'attributes' => ['rememberme' => 0, 'lifetime' => 0, 'loginContext' => $ctx, 'addContexts' => []],
]);

// --- 6. redirect ------------------------------------------------------------------------------
// $returnUrl was captured above, before the login processor replaced the session.
if ($returnUrl !== '' && $returnUrl[0] === '/' && strpos($returnUrl, '//') !== 0) {
    $modx->sendRedirect($modx->getOption('site_url') . ltrim($returnUrl, '/'));
}
// Default: site_start is the userHome router, which resolves this user's home for this context.
// NOT login_page — after the SSO repoint login_page IS the sso-login (start) resource, so
// redirecting there would bounce back to Google and loop.
$modx->sendRedirect($modx->makeUrl((int) $modx->getOption('site_start'), '', '', 'full'));
return '';
