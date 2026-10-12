<?php
/**
 * sso-provider-per-context — WHICH identity provider a context signs people in against.
 *
 * The owner's rule, 2026-08-11: names in the internal zone authenticate against the estate's OIDC
 * provider; public names authenticate against Google. Not a preference — Google will not accept a
 * redirect URI on a name it cannot resolve publicly, so a context on an internal name serves pages and
 * cannot sign anybody in.
 *
 * WHAT THIS PROVES, and the first item is the one that matters most:
 *   - PRODUCTION IS UNTOUCHED. A public host with no new settings resolves to exactly the Google
 *     endpoints, client and issuers it resolved to before this change existed.
 *   - the choice is PER CONTEXT — the constraint infra stated, and the reason the previous
 *     attempt was abandoned: one MODx serves four contexts and they will not all be internal.
 *   - an internal-zone context on sso.provider=oidc gets ITS OWN client id and secret and the estate
 *     issuer, not Google's.
 *   - the zone guard REFUSES rather than silently falling back: sso.provider=oidc on a public host
 *     is a stated fault, because substituting a different identity provider without saying so is
 *     the class of thing this estate keeps getting caught by.
 *   - a missing setting NAMES ITSELF (the failures-name-themselves rule, one repo over).
 *   - the callback URL has ONE definition, so the two halves of a sign-in cannot disagree.
 *
 * A known-answer control runs FIRST, so a harness that can only pass is as visible as one that can
 * only fail.
 *
 *   php tests/sso-provider-per-context.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../common/lib/SsoConfig.php';

$pass = 0; $fail = 0;
function check(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; if ($detail !== '') echo "        $detail\n"; }
}

/**
 * The smallest thing SsoConfig actually needs: getOption(). Deliberately a stub rather than a real
 * modX — the unit under test is a settings resolver, and standing up MODx to ask it which string it
 * returns would test the database instead.
 */
class FakeModx {
    private array $opts;
    public function __construct(array $opts) { $this->opts = $opts; }
    public function getOption($key) { return $this->opts[$key] ?? null; }
}

$PUBLIC = [
    'http_host'              => 'www.example.org',
    'site_url'               => 'https://www.example.org/',
    'sso.google_client_id'   => 'g-client',
    'sso.google_client_secret' => 'g-secret',
    'sso.callback_path'      => 'sso-callback',
    'estate.internal_zone'   => '.internal.test',
];
$INTERNAL = [
    'http_host'                  => 'ctx.app.internal.test',
    'site_url'                   => 'http://ctx.app.internal.test/',
    'sso.google_client_id'       => 'g-client',
    'sso.google_client_secret'   => 'g-secret',
    'sso.callback_path'          => 'sso-callback',
    'sso.provider'               => 'oidc',
    'sso.oidc_issuer'            => 'http://oidc.app.internal.test',
    'sso.oidc_auth_endpoint'     => 'http://oidc.app.internal.test/authorize',
    'sso.oidc_token_endpoint'    => 'http://oidc.app.internal.test/token',
    'sso.oidc_userinfo_endpoint' => 'http://oidc.app.internal.test/userinfo',
    'sso.oidc_jwks_uri'          => 'http://oidc.app.internal.test/jwks',
    'sso.oidc_client_id'         => 'modx-ctx',
    'sso.oidc_client_secret'     => 'ctx-secret',
    'estate.internal_zone'       => '.internal.test',
];

// ── 0. the control ─────────────────────────────────────────────────────────────────────────────
// If the resolver could only ever answer "google", every assertion below would pass while proving
// nothing. Prove first that BOTH answers are reachable.
$c0a = SsoConfig::forContext(new FakeModx($PUBLIC));
$c0b = SsoConfig::forContext(new FakeModx($INTERNAL));
check($c0a->provider === 'google' && $c0b->provider === 'oidc',
      'control: the resolver reaches BOTH providers',
      "public={$c0a->provider} internal={$c0b->provider}");

// ── 1. production is untouched ─────────────────────────────────────────────────────────────────
$c = SsoConfig::forContext(new FakeModx($PUBLIC));
check($c->provider === 'google'
   && $c->clientId === 'g-client' && $c->clientSecret === 'g-secret'
   && $c->authEndpoint === 'https://accounts.google.com/o/oauth2/v2/auth'
   && $c->tokenEndpoint === 'https://oauth2.googleapis.com/token'
   && $c->issuers === ['accounts.google.com', 'https://accounts.google.com']
   && $c->fault === '',
      'a public context resolves to Google, byte for byte as before',
      json_encode([$c->provider, $c->clientId, $c->authEndpoint, $c->tokenEndpoint, $c->issuers]));

// A public context is NOT offered the bench overrides even if somebody sets them.
$withOverrides = $PUBLIC + ['sso.auth_endpoint' => 'http://mock-oidc.app.internal.test/authorize',
                            'sso.token_endpoint' => 'http://mock-oidc.app.internal.test/token',
                            'sso.issuer' => 'http://mock-oidc.app.internal.test'];
$c = SsoConfig::forContext(new FakeModx($withOverrides));
check($c->authEndpoint === 'https://accounts.google.com/o/oauth2/v2/auth'
   && !in_array('http://mock-oidc.app.internal.test', $c->issuers, true),
      'the bench overrides do NOT reach a public host',
      $c->authEndpoint . ' ' . json_encode($c->issuers));

// ── 2. an internal context gets the estate provider, with its OWN client ───────────────────────
$c = SsoConfig::forContext(new FakeModx($INTERNAL));
check($c->provider === 'oidc'
   && $c->clientId === 'modx-ctx' && $c->clientSecret === 'ctx-secret'
   && $c->authEndpoint === 'http://oidc.app.internal.test/authorize'
   && $c->tokenEndpoint === 'http://oidc.app.internal.test/token'
   && $c->userinfoEndpoint === 'http://oidc.app.internal.test/userinfo'
   && $c->jwksUri === 'http://oidc.app.internal.test/jwks'
   && $c->issuers === ['http://oidc.app.internal.test']
   && $c->fault === '',
      'an internal-zone context resolves to the estate OIDC provider with its own client',
      json_encode([$c->provider, $c->clientId, $c->authEndpoint, $c->issuers]));

check($c->clientId !== 'g-client' && !in_array('accounts.google.com', $c->issuers, true),
      "Google's client and issuers are NOT carried into the oidc path", $c->clientId);

// ── 3. per CONTEXT, not per install ────────────────────────────────────────────────────────────
// The two resolutions above came from two settings maps, which is exactly what a MODx context
// setting is. This asserts the property the ticket asked for: the same install, two contexts, two
// answers, neither disturbing the other.
$a = SsoConfig::forContext(new FakeModx($INTERNAL));
$b = SsoConfig::forContext(new FakeModx($PUBLIC));
check($a->provider === 'oidc' && $b->provider === 'google',
      'two contexts of one install resolve independently');

// ── 4. the zone guard REFUSES, it does not fall back ───────────────────────────────────────────
$crossed = $PUBLIC + ['sso.provider' => 'oidc',
                      'sso.oidc_issuer' => 'http://oidc.app.internal.test',
                      'sso.oidc_auth_endpoint' => 'http://oidc.app.internal.test/authorize',
                      'sso.oidc_token_endpoint' => 'http://oidc.app.internal.test/token',
                      'sso.oidc_client_id' => 'x', 'sso.oidc_client_secret' => 'y'];
$c = SsoConfig::forContext(new FakeModx($crossed));
check($c->fault !== '' && strpos($c->fault, 'PUBLIC') !== false,
      'sso.provider=oidc on a PUBLIC host is refused, not silently sent to Google', $c->fault);
check(strpos($c->fault, 'www.example.org') !== false,
      'the refusal names the host it refused', $c->fault);

// ── 4b. AND THE MIRROR, which is the one that actually happened ────────────────────────────────
// The owner, 2026-08-14: a bench context sent sign-in to Google — 302 to accounts.google.com with a
// redirect_uri on an internal-zone name, which Google refuses for any private name. That sign-in cannot
// complete; it can only end on Google's error page, naming neither the estate nor the setting.
$stranded = $INTERNAL;
unset($stranded['sso.provider']);                     // exactly what a ground with no
$c = SsoConfig::forContext(new FakeModx($stranded));  // MODX_SSO_OIDC_CONTEXTS produces
check($c->fault !== '' && strpos($c->fault, 'INTERNAL') !== false,
      'the default provider on an INTERNAL host is refused, not sent to a Google that cannot answer',
      $c->fault);
check(strpos($c->fault, 'MODX_SSO_OIDC_CONTEXTS') !== false,
      '  and the refusal names the variable that fixes it', $c->fault);

// ── 5. a missing setting names itself ──────────────────────────────────────────────────────────
$partial = $INTERNAL;
unset($partial['sso.oidc_client_secret'], $partial['sso.oidc_token_endpoint']);
$c = SsoConfig::forContext(new FakeModx($partial));
check($c->fault !== ''
   && strpos($c->fault, 'sso.oidc_client_secret') !== false
   && strpos($c->fault, 'sso.oidc_token_endpoint') !== false,
      'an incomplete oidc context names EVERY missing setting', $c->fault);
check(strpos($c->fault, 'sso.oidc_issuer') === false,
      'and does not name the ones that are present', $c->fault);

// ── 5b. the JWKS URL is DISCOVERED when it is not configured ────────────────────────────
// A conforming OIDC client discovers a provider from its issuer. Making this a sixth setting turned
// "verify the token" into a change request on another agent, which is a gate on finished work.
// Configured wins (deterministic, no network); absent falls to the issuer's discovery document.
$c = SsoConfig::forContext(new FakeModx($INTERNAL));
check($c->jwksUri === 'http://oidc.app.internal.test/jwks',
      'a configured sso.oidc_jwks_uri is used as-is, with no network call', $c->jwksUri);

$nojwks = $INTERNAL; unset($nojwks['sso.oidc_jwks_uri']);
$c = SsoConfig::forContext(new FakeModx($nojwks));
// The issuer here does not resolve, so discovery fails and jwksUri stays empty — and CRUCIALLY the
// context does NOT fault, because an unreachable provider is not a configuration error. The refusal
// happens at the callback (SSO-19), where it is a true statement about the provider.
check($c->jwksUri === '' && $c->fault === '',
      'an unconfigured JWKS URL is not a config fault — discovery is tried, refusal is the callback\'s',
      "jwks='{$c->jwksUri}' fault='{$c->fault}'");

// And with a LIVE provider it really does discover. Skipped rather than failed where the estate's
// provider is unreachable, because a test that needs the network must say which it did.
// MOCK_OIDC_URL names the ground's mock provider (the staging test environment declares it).
$mock = rtrim((string) getenv('MOCK_OIDC_URL'), '/');
$live = $INTERNAL;
unset($live['sso.oidc_jwks_uri']);
$live['sso.oidc_issuer'] = $mock;
$probe = $mock === '' ? false : @file_get_contents($mock . '/.well-known/openid-configuration');
if ($probe === false) {
    echo "  skip  discovery against the live provider (" . ($mock ?: 'MOCK_OIDC_URL unset') . " unreachable)\n";
} else {
    $c = SsoConfig::forContext(new FakeModx($live));
    check($c->jwksUri === $mock . '/jwks',
          'with no setting, the JWKS URL is DISCOVERED from the live issuer', $c->jwksUri);
}

// ── 5b. the internal zone is the SETTING, not a suffix typed in code ───────────────────────────
// Another estate's zone must work with no code change, and no zone must mean no internal name.
$other = array_merge($INTERNAL, ['http_host' => 'ctx.corp.internal', 'site_url' => 'http://ctx.corp.internal/',
                                 'estate.internal_zone' => 'corp.internal']);
$c = SsoConfig::forContext(new FakeModx($other));
check(!$c->fault && $c->provider === 'oidc',
      'a different estate\'s zone (corp.internal) signs its internal names in by oidc', (string) $c->fault);
$nozone = $INTERNAL; unset($nozone['estate.internal_zone']);
$c = SsoConfig::forContext(new FakeModx($nozone));
check($c->fault !== null && strpos($c->fault, 'estate.internal_zone is not set') !== false,
      'with no estate.internal_zone, no name is internal: oidc is refused and the refusal names the setting',
      (string) $c->fault);

// ── 6. one definition of the callback URL ──────────────────────────────────────────────────────
// GoogleSSOStart and GoogleSSOCallback used to build this separately, kept identical by a comment
// asking the next person nicely. Every conforming provider compares the two.
check(SsoConfig::redirectUri(new FakeModx($PUBLIC)) === 'https://www.example.org/sso-callback',
      'callback URL on a public context is https and the configured path',
      SsoConfig::redirectUri(new FakeModx($PUBLIC)));
check(SsoConfig::redirectUri(new FakeModx($INTERNAL)) === 'http://ctx.app.internal.test/sso-callback',
      'callback URL on an internal context follows the context scheme, not a literal https',
      SsoConfig::redirectUri(new FakeModx($INTERNAL)));
// THE TWO ABOVE ARE UNEDITED, DELIBERATELY. Both fixtures have site_url and http_host naming the
// same authority — which is every context on production and on staging — so they are the proof
// that building the callback from site_url changed nothing on any ground that exists today.
//
// The third fixture is the local instance, where they DIVERGE by design: http_host must stay in
// the bench zone (12-sso.py refuses oidc against a public host; regenerate-seed.sh refuses a seed
// from a ground outside it) while the address a browser can reach is a localhost port. Before this
// case the callback named worldview.modx.internal.test and the sign-in could not complete.
$LOCAL = array_merge($INTERNAL, ['http_host' => 'worldview.modx.internal.test',
                                 'site_url'  => 'http://localhost:8314/']);
check(SsoConfig::redirectUri(new FakeModx($LOCAL)) === 'http://localhost:8314/sso-callback',
      'a context RESOLVED on one name and REACHED on another builds its callback from the one a '
    . 'browser can reach',
      SsoConfig::redirectUri(new FakeModx($LOCAL)));
// ...and the port survives. A construction that took only the scheme from site_url would produce
// http://worldview.modx.internal.test/sso-callback and look almost right in a log.
check(strpos(SsoConfig::redirectUri(new FakeModx($LOCAL)), ':8314/') !== false,
      'the callback keeps the PORT — a provider compares redirect_uri byte for byte',
      SsoConfig::redirectUri(new FakeModx($LOCAL)));
// A context with no site_url at all must not produce a malformed URL; it falls back to the old
// construction and lets the provider refuse, rather than emitting '://.../sso-callback'.
$NOSITE = array_merge($INTERNAL, ['site_url' => '']);
check(SsoConfig::redirectUri(new FakeModx($NOSITE)) === 'https://ctx.app.internal.test/sso-callback',
      'an empty site_url falls back to http_host and a well-formed URL, not to nonsense',
      SsoConfig::redirectUri(new FakeModx($NOSITE)));

// ── 7. an unset provider is Google, and so is an unrecognised one ──────────────────────────────
// The default decides what SILENCE means, and silence must mean the safe, unchanged thing.
$c = SsoConfig::forContext(new FakeModx($PUBLIC + ['sso.provider' => '']));
check($c->provider === 'google' && $c->fault === '', 'an empty sso.provider means Google');
// array_merge, NOT `+`. PHP's union operator keeps the LEFT side's value for a key that already
// exists, so `$INTERNAL + ['sso.provider' => 'saml']` is still 'oidc' — this assertion passed
// nothing for its first run and said so, which is the only reason it is written correctly now.
$c = SsoConfig::forContext(new FakeModx(array_merge($INTERNAL, ['sso.provider' => 'saml'])));
check($c->provider === 'google',
      'an unrecognised provider falls to Google rather than half-configuring something else',
      $c->provider);

// ── the deep-link destination is read BEFORE login replaces the session ──────────────────
// The owner: a signed-out visit to an internal context's /capability signed him in and landed him on /home.
// The callback read $_SESSION['sso.returnUrl'] at its redirect step, which runs AFTER
// runProcessor('security/login') — and MODx's login replaces the session, so the read found
// nothing and fell through to the userHome router. An ORDER defect, invisible in every unit that
// tests the pieces, so it is asserted as an order.
// CODE ONLY. Two earlier versions of this check matched the word inside a COMMENT — first the
// snippet's docstring, then the very comment that explains this defect — and both failed on
// correct code. Comment lines are stripped before anything is located. Third time today that a
// check of mine has been fooled by its own documentation (2026-08-14).
$cb = implode("\n", array_filter(
    file(__DIR__ . '/../common/snippets/GoogleSSOCallback.php', FILE_IGNORE_NEW_LINES),
    fn($l) => !preg_match('#^\s*(//|\*|/\*)#', $l)));
// Since 2026-09-23 the destination travels in the PENDING entry its state names (several sign-ins
// can be in flight in one browser), so what must come first is the read of that entry.
$posRead  = strpos($cb, "\$_SESSION['sso.pending']");
// The CALL, not the word: the first version searched for "security/login" and matched the
// docstring at the top of the snippet, so it failed on correct code and would have passed on
// broken code once that line moved. Found by it going red on the fix (2026-08-14).
$posLogin = strpos($cb, 'addSessionContext(');
check($posRead !== false && $posLogin !== false && $posRead < $posLogin,
      'the returnUrl is captured BEFORE the session context changes',
      "read at $posRead, addSessionContext at $posLogin — capture first, so no later session handling can reach it");
check(substr_count($cb, "\$started['returnUrl']") === 1 && substr_count($cb, "sso.returnUrl") === 0,
      '  and it is read in exactly one place, from the pending entry',
      'a second read after login would reintroduce the defect quietly');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
