<?php
/**
 * Application.php — what THIS application presents as its own identity.
 *
 * Every player in the estate sends its badge as `X-Application-Badge` on every
 * call it makes to another application, read from one value called `WHOAMI`. Four of the five read
 * it from an environment variable. MODx cannot, and this file is the answer to "then what does it
 * read it from" so infra has something to render.
 *
 * WHY NOT AN ENVIRONMENT VARIABLE — measured, not assumed. `grep getenv` over the PHP in this
 * repository returns hits only in tests/ and two CLI scripts: the application PHP-FPM serves reads
 * no environment at all. A badge rendered there produces no error, no log line and no header. It
 * would look done and be inert.
 *
 * WHY NOT A SYSTEM SETTING, which is what I said earlier and am correcting here. Settings
 * live in `modx_system_settings`, and `restore_modx_dev` loads production's dump whole — that is
 * exactly how production's `kuber.jwt_private_key` ended up on a dev bench (measured
 * 2026-08-02) and how four credentials landed in a transcript. Putting the badge there
 * recreates a defect the estate is currently building a scrub to undo. A badge is per environment;
 * a mechanism that copies production's onto every bench is the wrong mechanism, whatever else is
 * true of it.
 *
 * WHY NOT config.inc.php — MODX's own upgrader rewrites it. Nor config.context.php, which is
 * the host -> context map infra generates from setup/domains.py: two unrelated facts in one file is
 * the thing this estate keeps paying for.
 *
 * SO: `core/config/application.php`, a new file, rendered by infra alongside the three it already
 * templates for modx-prod. It returns an array. It is under `core/`, which carries MODX's own
 * deny rule, so it is not web-readable; it is not in the database, so no restore carries it; it is
 * not stock, so no upgrade rewrites it.
 *
 *     <?php
 *     return ['whoami' => 'the badge value for THIS environment'];
 *
 * ABSENT MEANS OMITTED, NOT REFUSED — and that is dev's settled semantics for this header
 * (`withBadge()`: absent = header omitted = today's behaviour), reused rather than
 * re-decided. It is deliberately NOT the rule another estate application uses, where an absent badge refuses the
 * call: there the badge is one half of the only credential the caller has, so a call without it
 * cannot succeed. Here the badge is additive to a token path that already works and enforcement is
 * off, so refusing on absence would break a working production the day it shipped and buy nothing.
 * The day kuber requires the badge at the door, the refusal comes from the door, which
 * is where a policy about who may call belongs.
 *
 * NO DEFAULT AND NO COMPOSITION. This file never invents a value, never appends an environment to
 * one, and never falls back to a name. The player never knows its ground.
 */
declare(strict_types=1);

namespace Pustak\Modx;

final class Application
{
    /** Cached across a request; a page may render many kuber-backed tables. */
    private static ?array $config = null;

    /**
     * The badge this application presents, or null if none is rendered here.
     *
     * @param string|null $corePath MODX_CORE_PATH; injected so tests need no MODX.
     */
    public static function whoami(?string $corePath = null): ?string
    {
        if (self::$config === null) {
            $base = $corePath ?? (defined('MODX_CORE_PATH') ? MODX_CORE_PATH : '');
            $file = rtrim($base, '/') . '/config/application.php';
            $loaded = null;
            if ($base !== '' && is_readable($file)) {
                /** @noinspection PhpIncludeInspection */
                $loaded = include $file;
            }
            self::$config = is_array($loaded) ? $loaded : [];
        }
        $badge = self::$config['whoami'] ?? null;
        if (!is_string($badge)) {
            return null;
        }
        $badge = trim($badge);
        return $badge === '' ? null : $badge;
    }

    /**
     * The outbound identity headers for a call to another application: the badge if there is one,
     * nothing if there is not. Never a placeholder, never a name standing in for a value.
     *
     * @return string[] curl-shaped `Header: value` strings, ready to merge with the caller's own.
     */
    public static function badgeHeaders(?string $corePath = null): array
    {
        $badge = self::whoami($corePath);
        return $badge === null ? [] : ['X-Application-Badge: ' . $badge];
    }

    /**
     * The caller named by their CLIENT CERTIFICATE, or null.
     *
     * The owner ruled on 2026-08-14: the application badge is deleted in favour of the
     * certificate — one identity mechanism rather than two. A badge is a shared secret held by
     * every acceptor, so any acceptor could impersonate the presenter; a certificate is held only
     * by the presenter and nginx verifies the chain before this code runs.
     *
     * THE APPLICATION VALIDATES NOTHING. nginx sets `X-Client-Identity: CN=<app>@<environment>`
     * only on a verified chain and overwrites it on every request, so a caller cannot type an
     * identity into a request. That property is the whole security of this design — which is why
     * the suite drills a forged header rather than trusting the sentence.
     *
     * Returns the application part of the CN ('monitoring' from 'CN=monitoring@production'), so
     * the caller lists at each door stay the same words they are today.
     */
    public static function acceptedClient(?string $identity, ?array $allowed = null): ?string
    {
        if (!is_string($identity)) {
            return null;
        }
        $identity = trim($identity);
        if ($identity === '') {
            return null;                          // no verified certificate: nginx leaves it empty
        }
        // A DN can carry several RDNs in either order; take the CN and nothing else.
        if (!preg_match('#(?:^|,)\s*CN\s*=\s*([^,/]+)#i', $identity, $m)) {
            return null;
        }
        $cn = trim($m[1]);
        // <application>@<environment>. The environment is not checked here — a door that cares
        // reads it from its own rendered config, exactly as it does today; this returns WHO.
        $app = strpos($cn, '@') === false ? $cn : substr($cn, 0, strpos($cn, '@'));
        $app = trim($app);
        if ($app === '') {
            return null;
        }
        if ($allowed !== null && !in_array($app, $allowed, true)) {
            return null;                          // admitted at another door is not admitted here
        }
        return $app;
    }

    /**
     * The INBOUND half: which caller, if any, presented this badge.
     *
     * `core/config/application.php` carries an `accept` map keyed by WHO IS CALLING — infra's
     * shape, and the right one: a badge identifies its holder, so the thing being looked up is a
     * name, and a flat `APPLICATION_BADGE_MONITORING` key would be an env-var name wearing a PHP
     * costume.
     *
     * FAILS CLOSED, and that is not a preference here. modx serves the PUBLIC INTERNET
     * (example.org, 200 anonymous, measured 2026-08-04) where trump, kuberb and rakhwala sit
     * behind a private zone first. For them a door that opens when its credential is missing is a
     * degraded state; for this application it is a public disclosure. No `accept` map, an empty
     * one, or an empty stored value means NOBODY is recognised — never everybody.
     *
     * `hash_equals` because `===` on strings leaks length and prefix through timing, and this is
     * the only place in this repository where an attacker chooses one side of a comparison.
     *
     * @param string|null $presented the raw X-Application-Badge header, or null if absent.
     * @param string[]|null $allowed  callers permitted for THIS door; null means any accepted
     *        caller. Passing a list is how a caller admitted for one purpose does not silently
     *        gain another — dev's ACTUATOR_CALLERS-is-not-SEAM_CALLERS finding, which costs
     *        nothing to honour now and cannot be retrofitted after the second caller exists.
     * @return string|null the caller's name, or null if the badge matches nobody.
     */
    // acceptedCaller() — the BADGE acceptor — was deleted on 2026-08-14. The owner ruled
    // that the application badge goes in favour of the certificate: a badge is a shared secret held
    // by every acceptor, so any acceptor could impersonate the presenter. acceptedClient() below is
    // the whole of caller identity now. The badge map may still be rendered in application.php for
    // other readers; nothing here consults it.

    public static function config(string $key, ?string $corePath = null): ?string
    {
        self::whoami($corePath);                 // populates self::$config from the rendered file
        $v = self::$config[$key] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    /** Tests only — the cache is per-process and a test changes the file under it. */
    public static function resetForTests(): void
    {
        self::$config = null;
    }
}
