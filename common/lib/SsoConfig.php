<?php
/**
 * SsoConfig — WHICH identity provider does THIS context sign people in against, and with what.
 *
 * The owner's rule, 2026-08-11: **names in the internal zone authenticate against the estate's OIDC
 * provider; public names authenticate against Google.** It is not a preference — Google will not
 * accept a redirect URI on a name it cannot resolve publicly, so an internal-zone context has no way to
 * complete a Google sign-in at all. An earlier change moved the `claude` context to an internal-zone name, where it
 * serves pages and cannot sign anybody in; that is what this closes.
 *
 * WHY A CLASS RATHER THAN TWO COPIES OF THE SAME `getOption` CALLS. GoogleSSOStart builds the
 * authorization redirect and GoogleSSOCallback exchanges the code, and the provider, the client id
 * and the redirect_uri MUST be identical in both or every sign-in fails with a mismatch and no
 * useful message. Those two files already carry a comment begging the next person to keep two
 * `$redirectUri` lines byte-identical. One more pair of endpoints each would have made four.
 *
 * PER CONTEXT, NOT PER INSTALL — the constraint infra stated, and the reason the previous
 * attempt was abandoned. One MODx serves four contexts and they will not all be internal, so
 * pointing the install at a different provider would have taken `family` and `corporate` with it.
 * Nothing clever is needed for that: `$modx->getOption()` already resolves a `modx_context_setting`
 * over the system value, so writing these per context is the whole mechanism. What was missing was
 * a client id and secret that are not Google's, and a stated choice of provider.
 *
 * WHAT DOES NOT CHANGE: `sso.auto_provision` and `sso.provision_usergroup` behave identically whichever provider a
 * context uses. The identity SOURCE changes; the authorisation rules do not.
 *
 * Settings, all readable as context settings:
 *   sso.provider               'google' (default, and what every public context uses) | 'oidc'
 *   sso.oidc_issuer            the estate provider's issuer, exactly as it appears in `iss`
 *   sso.oidc_auth_endpoint     authorization endpoint
 *   sso.oidc_token_endpoint    token endpoint
 *   sso.oidc_userinfo_endpoint OPTIONAL; read only if the id_token carries no email
 *   sso.oidc_jwks_uri          OPTIONAL; the provider's published signing keys. Absent, it is read
 *                              from the issuer's discovery document, because a conforming client
 *                              discovers rather than being hand-fed six URLs.
 *   sso.oidc_client_id         this context's client at that provider
 *   sso.oidc_client_secret     its secret
 *
 * The pre-existing sso.auth_endpoint / sso.token_endpoint / sso.issuer trio still works and is
 * unchanged: it is the bench's mock-oidc arrangement and predates this. A context
 * that sets `sso.provider=oidc` uses the `oidc_*` settings; one that does not is on the old path
 * exactly as before. Two ways to say a similar thing is a wart, and it is deliberate for one
 * release rather than a migration nobody asked for — see the note at the bottom of this file.
 */
declare(strict_types=1);

class SsoConfig
{
    /** @var string 'google' | 'oidc' — what this context actually resolved to. */
    public $provider = 'google';
    public $authEndpoint = '';
    public $tokenEndpoint = '';
    public $userinfoEndpoint = '';
    /** @var string JWKS endpoint. Only the oidc path has one; Google's is inside GoogleToken. */
    public $jwksUri = '';
    public $clientId = '';
    public $clientSecret = '';
    /** @var string[] every spelling of `iss` that is acceptable for this provider. */
    public $issuers = [];
    /**
     * @var string non-empty when this context CANNOT sign anyone in, and why — a short sentence for
     *             the log. The caller turns it into a coded refusal; this class never renders.
     */
    public $fault = '';

    /**
     * Resolve the provider for the CURRENT context.
     *
     * @param modX $modx
     */
    public static function forContext($modx): self
    {
        $c = new self();

        $host = strtolower((string) $modx->getOption('http_host'));
        // THE INTERNAL ZONE IS DATA, not code: the setting `estate.internal_zone` (the estate's
        // private DNS suffix, written by setup/02 from the estate file). A name in it resolves
        // nowhere outside the estate, so Google cannot issue a callback to it and the estate's own
        // OIDC provider signs it in. NO DEFAULT: an installation that declares no zone has no
        // internal names, so every host counts as public and an oidc context is refused below —
        // closed, and the refusal names the missing setting.
        $zone = strtolower(trim((string) $modx->getOption('estate.internal_zone')));
        if ($zone !== '' && $zone[0] !== '.') $zone = '.' . $zone;
        $internal = $zone !== '' && substr($host, -strlen($zone)) === $zone;
        $zoneSaid = $zone !== '' ? "the internal zone ($zone)" : 'the internal zone (estate.internal_zone is not set, so no name is internal)';

        $want = strtolower(trim((string) $modx->getOption('sso.provider'))) ?: 'google';

        if ($want === 'oidc' && !$internal) {
            // REFUSE, do not quietly fall back to Google.
            //
            // Falling back would be *safe* — Google is the stronger provider — and it is still the
            // wrong behaviour: substituting a different identity provider than the one configured,
            // silently, is the exact class of thing this estate keeps getting caught by. A context
            // whose configuration cannot be honoured says so.
            //
            // This state cannot arise from setup/12-sso.py, which refuses to write oidc settings
            // against a public host. It can only be reached by hand, and by hand is precisely when
            // somebody needs to be told.
            $c->fault = "sso.provider=oidc on the PUBLIC host '$host'. The rule: names in $zoneSaid "
                      . "use the estate OIDC provider, public names use Google. Google cannot issue "
                      . "a callback to a private name, and a public name must not be pointed at an "
                      . "internal provider.";
            return $c;
        }

        if ($want !== 'oidc' && $internal) {
            // THE MIRROR, AND IT IS THE ONE THAT ACTUALLY HAPPENED. The owner, 2026-08-14:
            // the claude bench context sends sign-in to Google. Measured: 302 to accounts.google.com with
            // a redirect_uri on an internal-zone name — which Google REFUSES, always, for any private
            // name. So this configuration cannot complete a sign-in; it can only end on Google's
            // error page, where nothing names the estate or the setting that caused it.
            //
            // The guard above refuses oidc-on-public because substituting a provider silently is
            // wrong. This one refuses google-on-internal for the stronger reason: it does not
            // work at all, and the failure it produces is unreadable.
            //
            // It arises from a GROUND that has not been told which contexts are internal —
            // setup/12-sso.py writes sso.provider=oidc only for the contexts named in
            // MODX_SSO_OIDC_CONTEXTS, so an unset variable leaves every context on Google,
            // including the ones served on internal-zone names.
            $c->fault = "sso.provider is '$want' on the INTERNAL host '$host'. The rule: names in "
                      . "$zoneSaid use the estate OIDC provider. Google cannot issue a callback to a "
                      . "private name, so this sign-in cannot complete — set MODX_SSO_OIDC and "
                      . "MODX_SSO_OIDC_CONTEXTS for this context and re-run setup/12-sso.py.";
            return $c;
        }

        if ($want === 'oidc') {
            $c->provider         = 'oidc';
            $c->authEndpoint     = trim((string) $modx->getOption('sso.oidc_auth_endpoint'));
            $c->tokenEndpoint    = trim((string) $modx->getOption('sso.oidc_token_endpoint'));
            $c->userinfoEndpoint = trim((string) $modx->getOption('sso.oidc_userinfo_endpoint'));
            $c->jwksUri          = trim((string) $modx->getOption('sso.oidc_jwks_uri'));

            $c->clientId         = trim((string) $modx->getOption('sso.oidc_client_id'));
            $c->clientSecret     = trim((string) $modx->getOption('sso.oidc_client_secret'));
            $issuer              = trim((string) $modx->getOption('sso.oidc_issuer'));
            $c->issuers          = $issuer !== '' ? [$issuer] : [];
            // NOT REQUIRED AS A SETTING — DISCOVERED IF ABSENT.
            //
            // A later change made the signature check mandatory, and my first cut made the JWKS URL a sixth
            // setting somebody had to render. That is the wrong shape twice over: a conforming OIDC
            // client DISCOVERS a provider from its issuer rather than being hand-fed six URLs, and
            // making it a setting turned "verify the token" into a change request on another agent
            // — a gate on work that was otherwise finished.
            //
            // So: the setting wins when it is set (deterministic, no network, and how production
            // would be pinned), and the issuer's own discovery document answers when it is not. A
            // provider that publishes neither cannot be verified against, and THAT is the fault —
            // which is a true statement about the provider rather than about our configuration.
            if ($c->jwksUri === '' && $issuer !== '') {
                $c->jwksUri = self::discoverJwks($issuer);
            }


            // EVERY field, named individually. "SSO is not configured" is the sentence this whole
            // estate spent 2026-08-11 removing from another application; a missing setting
            // must say which one.
            $missing = [];
            foreach ([
                'sso.oidc_issuer'         => $issuer,
                'sso.oidc_auth_endpoint'  => $c->authEndpoint,
                'sso.oidc_token_endpoint' => $c->tokenEndpoint,
                'sso.oidc_client_id'      => $c->clientId,
                'sso.oidc_client_secret'  => $c->clientSecret,
            ] as $k => $v) {
                if ($v === '') { $missing[] = $k; }
            }
            if ($missing) {
                $c->fault = 'sso.provider=oidc but these are empty for this context: '
                          . implode(', ', $missing);
            }
            return $c;
        }

        // ── google, and this branch must behave EXACTLY as it did before the OIDC path ──────────────────
        // Production runs down here. The bench's mock-oidc arrangement also runs
        // down here, through the pre-existing sso.auth_endpoint/token_endpoint/issuer overrides,
        // which are honoured only inside the private zone. Nothing about either changed.
        $c->provider      = 'google';
        $c->clientId      = (string) $modx->getOption('sso.google_client_id');
        $c->clientSecret  = (string) $modx->getOption('sso.google_client_secret');
        $c->authEndpoint  = 'https://accounts.google.com/o/oauth2/v2/auth';
        $c->tokenEndpoint = 'https://oauth2.googleapis.com/token';
        $c->issuers       = ['accounts.google.com', 'https://accounts.google.com'];
        if ($internal) {
            $c->authEndpoint  = (string) $modx->getOption('sso.auth_endpoint')  ?: $c->authEndpoint;
            $c->tokenEndpoint = (string) $modx->getOption('sso.token_endpoint') ?: $c->tokenEndpoint;
            $alt              = trim((string) $modx->getOption('sso.issuer'));
            if ($alt !== '') { $c->issuers[] = $alt; }
        }
        if ($c->clientId === '' || $c->clientSecret === '') {
            $c->fault = 'sso.google_client_id / sso.google_client_secret not configured';
        }
        return $c;
    }

    /**
     * Read `jwks_uri` out of an issuer's discovery document.
     *
     * Cached in APCu when it is there, for an hour — the URL is a property of the provider and
     * does not move between requests, and a sign-in already makes two calls without adding a third
     * every time. Where APCu is absent the fetch simply happens; correctness does not depend on the
     * cache, which is the only safe way to depend on one.
     *
     * Returns '' on any failure. The caller turns that into a named fault; it must never turn into
     * "skip verification", which is why nothing here has a fallback that produces a usable path.
     */
    private static function discoverJwks(string $issuer): string
    {
        $key = 'sso.jwks.' . md5($issuer);
        if (function_exists('apcu_fetch')) {
            $hit = apcu_fetch($key, $ok);
            if ($ok && is_string($hit) && $hit !== '') { return $hit; }
        }
        $url = rtrim($issuer, '/') . '/.well-known/openid-configuration';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($http !== 200 || !is_string($body)) { return ''; }
        $d = json_decode($body, true);
        $uri = is_array($d) ? trim((string) ($d['jwks_uri'] ?? '')) : '';
        if ($uri !== '' && function_exists('apcu_store')) { apcu_store($key, $uri, 3600); }
        return $uri;
    }

    /**
     * The public callback URL for this context. ONE definition, because Google and every
     * conforming provider compare the redirect_uri sent at authorisation with the one sent at
     * token exchange, and the two files that build it each carried their own copy with a comment
     * asking the next person to keep them identical.
     *
     * @param modX $modx
     */
    public static function redirectUri($modx): string
    {
        $path = trim((string) $modx->getOption('sso.callback_path') ?: 'sso-callback', '/');
        // FROM site_url, WHOLE — not the scheme from site_url and the authority from http_host.
        //
        // Those two are the same string on production and on staging, so this reads as a
        // refactor there and IS byte-identical (tests/sso-provider-per-context.php asserts both
        // fixtures unchanged). They are NOT the same on the local instance, and the reason is
        // structural rather than incidental: `http_host` is the context-RESOLUTION key and must
        // stay `<ctx>.modx.<internal zone>` — 12-sso.py:288-304 refuses to write oidc settings against a
        // public host, this class refuses at request time, and regenerate-seed.sh refuses to build
        // a seed from a ground whose http_host is not in that zone. Meanwhile the address a
        // BROWSER can reach is `localhost:<port>`, which is what site_url says.
        //
        // Building the callback from http_host therefore sent the browser to a name it cannot
        // resolve, and the sign-in could not complete. Measured 2026-08-20.
        //
        // site_url is already the authority every other link on the page is built from
        // (GoogleSSOStart, GoogleSSOCallback and GoogleSSOLogout all use it or makeUrl(...,'full')),
        // so this makes the callback agree with them rather than introducing a new rule.
        $site = rtrim((string) $modx->getOption('site_url'), '/');
        if ($site === '' || parse_url($site, PHP_URL_HOST) === null) {
            // A context with no usable site_url is a misconfiguration, not a case to guess through.
            // Fall back to the old construction so the URL is at least well-formed, and let the
            // provider's own redirect_uri check be the thing that refuses.
            $scheme = parse_url((string) $modx->getOption('site_url'), PHP_URL_SCHEME) ?: 'https';
            return $scheme . '://' . $modx->getOption('http_host') . '/' . $path;
        }
        return $site . '/' . $path;
    }
}

/*
 * TWO GAPS, STATED HERE RATHER THAN DISCOVERED LATER.
 *
 * 1. THE OIDC PATH VERIFIES THE id_token SIGNATURE; the Google path still does not, and that
 *    asymmetry is deliberate. Google's token arrives on a direct server-to-server POST to Google's
 *    own endpoint over TLS, which is Google's stated position and is unchanged here because
 *    production runs down it. The OIDC path had the same reasoning WITHOUT the TLS — the estate's
 *    internal zone is plain http — so it now checks RS256 against the provider's published
 *    JWKS before believing a single claim.
 *
 *    It does so through `Kuber\Core\GoogleToken`, the estate's ONE verifier, which kuber gave an
 *    optional issuer list for exactly this (kuber-app ed78a3ab). No second implementation was
 *    written, which is what four rounds were spent making true.
 *
 * 2. `sso.auth_endpoint`/`token_endpoint`/`issuer` (the bench trio) and `sso.oidc_*` now express
 *    overlapping things. The trio is per-INSTALL in practice and Google-flavoured; the oidc set is
 *    per-context and is a provider in its own right. They are kept apart deliberately: collapsing
 *    them would change how every bench signs in, on a ticket that was about making an internal-zone
 *    context able to sign in at all. One of the two should go, and which one is a decision, not a
 *    tidy-up.
 */
