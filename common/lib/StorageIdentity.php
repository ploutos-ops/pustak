<?php
/**
 * StorageIdentity — how this application proves WHO IT IS to the storage door.
 *
 * The door authenticates its callers by CLIENT CERTIFICATE now. A bearer token is a secret that
 * travels: it lives in a system setting, `restore_modx_dev` copies production's dump whole, and
 * anything holding a copy is `modx` as far as the door is concerned. A certificate cannot be
 * copied off the ground it was placed on, and the identity it asserts is `modx@<environment>` —
 * reads span production plus our own, writes pin to our own pair.
 *
 * WHY THIS IS A CLASS AND NOT SIX LINES INSIDE THE PLUGIN, which is where it was first written.
 * The plugin needs a MODX request, a resource and a static-resource render to reach those six
 * lines, so the only test that could be written against them was a grep of the source — and this
 * repository has already been taught what a grep of the source is worth: `failures-name-themselves`
 * passed twice over deleted code because it matched the comment explaining it. The decision
 * is the thing that must be right, so the decision is what is testable: give it a directory and a
 * token and it tells you what curl should carry, with no MODX in the room.
 *
 * WHERE THE KEY IS is a per-GROUND fact and comes from `core/config/application.php`, not from a
 * system setting — Application.php's docblock carries the reason and it is not theoretical: that
 * is how production's `kuber.jwt_private_key` once reached a dev bench.
 *
 * THE FILES ARE NEVER CACHED. infra renews the certificate on the ground every 8 hours and
 * replaces the files in place (2026-08-13). Only the PATH is remembered; curl opens the file
 * on each call. Caching the bytes would work for eight hours and then fail in a way nobody would
 * connect to this line.
 */

namespace Pustak\Modx;

final class StorageIdentity
{
    /** Named so a caller can say WHICH path it took without re-deriving the answer. */
    public const CERTIFICATE = 'certificate';
    public const NONE        = 'none';
    // BEARER is GONE. It existed so this change needed no deploy-ordering gate: while
    // the door still accepted a token, a caller with no certificate could still fetch a file. On
    // 2026-08-14 infra removed modx's row from STORAGE_SYSTEM_TOKENS on its own — drilled both
    // ways at the door that minute: modx's bearer 401, modx's certificate 200 from inside the
    // container — so the fallback now degrades to nothing but a slower 401. A path that cannot
    // succeed must not be attempted: it turns a configuration fault into a door refusal, which is
    // the wrong place to read the reason.

    /**
     * What curl must carry to prove this caller's identity, and which path that is.
     *
     * @param string|null $identityDir  Application::config('estate_identity_dir') — the rendered
     *                                  per-ground value, or null when infra has not rendered it.
     * @param string|null $unused       the retired `storage.modx_token`. Accepted and IGNORED so a
     *                                  ground that still renders the setting, and the plugin that
     *                                  still passes it, keep working while both are cleaned up —
     *                                  a signature change here would be a second deploy-ordering
     *                                  gate, which is exactly what the bearer path existed to avoid.
     * @return array{0: string, 1: array<int,mixed>, 2: string|null}
     *         [ which path, curl options, a line for the log or null ]
     */
    public static function curlOptions(?string $identityDir, ?string $unused = null): array
    {
        $cert = $identityDir !== null && trim($identityDir) !== ''
            ? rtrim(trim($identityDir), '/') . '/cert.pem' : '';
        $key = $cert !== '' ? dirname($cert) . '/key.pem' : '';

        if ($cert !== '' && is_readable($cert) && is_readable($key)) {
            // NO Authorization header on this path, deliberately. Presenting both would leave the
            // bearer path alive and untested: the door would accept the token, the certificate
            // could be wrong or expired, and nobody would find out until the token was withdrawn.
            return [self::CERTIFICATE, [CURLOPT_SSLCERT => $cert, CURLOPT_SSLKEY => $key], null];
        }

        // NO CERTIFICATE IS A REFUSAL NOW, not a fallback. It fails CLOSED: the caller says why
        // in its own log line, and no request leaves the box carrying nothing that can identify it.
        // ABSENT AND UNREADABLE ARE DIFFERENT FAULTS WITH DIFFERENT OWNERS, and the advice that
        // follows them is different too. The first version of this said "place the certificate and
        // the file will serve" for both — which is right when the file is missing and actively
        // misleading when it is present and the process cannot open it. Measured on the bench
        // 2026-08-18: the certificate was exactly where it should be, and the php-fpm pool ran as
        // user=skunkworks with group=www-data against a directory that is root:skunkworks 0750, so
        // every check failed and the message told the reader to place a file that was already there.
        $missing = $cert !== '' && !file_exists($cert);
        $why = $cert === ''
            ? 'estate_identity_dir is not rendered in core/config/application.php'
            : ($missing
                ? "$cert does not exist"
                : (!is_readable($cert)
                    ? "$cert exists but THIS PROCESS cannot read it — check the file's owner and "
                      . 'group against the user AND GROUP the web pool runs as, not just the user'
                    : (!file_exists(dirname($cert) . '/key.pem')
                        ? dirname($cert) . '/key.pem does not exist'
                        : dirname($cert) . '/key.pem exists but THIS PROCESS cannot read it — same '
                          . 'check as above')));
        return [self::NONE, [],
            "storage: this caller can prove nothing to the door — $why. The bearer path was "
            . 'removed when modx lost its STORAGE_SYSTEM_TOKENS row on 2026-08-14, so there is no '
            . 'second way in. No request was made.'];
    }
}
