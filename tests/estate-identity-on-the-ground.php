<?php
/**
 * estate-identity-on-the-ground.php — the certificate MODx proves itself with is really there,
 * and really readable BY THE PROCESS THAT USES IT.
 *
 *     $MODX_PHP_RUN <this file> <docroot>
 *
 * WHY THIS EXISTS AND WHY IT COULD NOT EXIST BEFORE. A request asked infra for two things: place
 * /etc/estate-identity/modx/{cert,key}.pem on both modx_web grounds AND make them readable by
 * deliverStorageFile, which runs as the container's www-data — not the host user of that name —
 * and render `estate_identity_dir` into core/config/application.php. Both were delivered on
 * 2026-08-13 and I could not tick either: MODx answers HTTP identically whether the plugin took
 * the certificate path or the bearer fallback, which was deliberate when I built it, and from a
 * dev box there is no way to see inside the container. Asking infra to paste a log line is not a
 * verification; it is their word twice.
 *
 * What changed that: the checkout is bind-mounted into modx-staging, so this suite runs
 * INSIDE the container, where `www-data` means the image's user and the identity directory is
 * either present or it is not.
 *
 * READABILITY IS COMPUTED, NOT ASSUMED. Running as root, is_readable() answers yes to almost
 * everything, so it would pass over exactly the defect ask 1 names. This reads the owner, group
 * and mode and decides for www-data's own uid/gid — and if the process IS www-data it also does
 * the direct read, because a computed answer that disagrees with a real one is worth knowing.
 *
 * SEPARATE FROM tests/storage-identity.php on purpose: that suite proves the LIBRARY chooses the
 * certificate path over the bearer path, against fixtures, anywhere. This one proves the GROUND
 * carries what the library needs. A green library on a ground with no certificate is the shape
 * that request was filed to close.
 */

$pass = 0;
$fail = 0;
function ok(string $l): void   { global $pass; $pass++; echo "  ok    $l\n"; }
function bad(string $l, string $d = ''): void {
    global $fail; $fail++; echo "  FAIL  $l\n"; if ($d !== '') echo "        $d\n";
}

// BOTH SHAPES, because I got this wrong on the ground's first run: application.php lives at
// <docroot>/core/config/application.php, and I copied an idiom from application-badge.php whose
// $root was already the core directory. The refusal now names every path it tried, so the next
// person to hit it is told where it looked rather than left to guess (2026-08-14).
$root = ($argv[1] ?? '') ?: (getenv('MODX_ROOT') ?: '');
$tried = [];
$appFile = '';
foreach (['/core/config/application.php', '/config/application.php'] as $rel) {
    $tried[] = $root . $rel;
    if ($root !== '' && is_file($root . $rel)) { $appFile = $root . $rel; break; }
}
if ($appFile === '') {
    fwrite(STDERR,
        "  REFUSING: no application.php under '" . ($root ?: '(nothing)') . "'.\n"
      . "            Tried: " . implode(' and ', $tried ?: ['(nothing — no docroot given)']) . "\n"
      . "            This suite is about a GROUND — it reads the rendered config and the identity\n"
      . "            files beside it. No assertion below has run: a statement about the shell,\n"
      . "            not about the tree.\n");
    exit(2);
}

echo "estate-identity-on-the-ground — the certificate is there, and readable by the user that uses it\n\n";

// ── (1) the render, which is ask 2 ─────────────────────────────────────────────────────────────
$cfg = require $appFile;
$dir = is_array($cfg) ? ($cfg['estate_identity_dir'] ?? null) : null;

if (is_string($dir) && $dir !== '') {
    ok("application.php renders estate_identity_dir ($dir)");
} else {
    bad('application.php does not render estate_identity_dir',
        'without it StorageIdentity falls back to the bearer path and SAYS SO in the log — '
      . 'the fallback works, which is why this needs asserting rather than noticing');
    echo "\n  $pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}

// ── (2) the files, which is the first half of ask 1 ────────────────────────────────────────────
$cert = rtrim($dir, '/') . '/cert.pem';
$key  = rtrim($dir, '/') . '/key.pem';
// WHEN IT IS NOT THERE, SAY WHAT IS. A bare "not found" sends the reader to look themselves, and
// the interesting case is a directory one level off — which is exactly what the first real run of
// this suite found (2026-08-14: the render said /etc/estate-identity/ and the files live
// under an identity-specific subdirectory of it).
$listing = function (string $d): string {
    if (!is_dir($d)) { return "$d is not a directory"; }
    $e = @scandir($d);
    if ($e === false) { return "$d cannot be listed by this process"; }
    $e = array_values(array_diff($e, ['.', '..']));
    $marked = array_map(fn($n) => is_dir("$d/$n") ? "$n/" : $n, $e);
    return $e ? "$d contains: " . implode(' ', $marked) : "$d is empty";
};
foreach ([$cert, $key] as $f) {
    is_file($f) ? ok(basename($f) . ' is present on this ground')
                : bad(basename($f) . " is not at $f",
                      $listing(dirname($f))
                    . (is_dir(dirname($f) . '/modx')
                        ? ' — note the modx/ subdirectory: estate_identity_dir names the DIRECTORY '
                        . 'the two files sit in, so it should end in the identity name'
                        : ''));
}
if ($fail) {
    // THE OTHER HALF OF THE ANSWER. The files may be present and correct while only the rendered
    // PATH is wrong, and those are different asks with different owners (one ask places them,
    // another renders the path). Reporting only "not found" would make infra prove the second half
    // in a separate round, so where an identity-named subdirectory exists, its files are examined
    // here and the readability question is answered anyway.
    $alt = rtrim($dir, '/') . '/modx';
    if (is_dir($alt)) {
        echo "\n  the files ask 1 placed, checked where they actually are ($alt):\n";
        $pwx = function_exists('posix_getpwnam') ? posix_getpwnam($WEB_USER) : false;
        foreach (['cert.pem', 'key.pem'] as $n) {
            $f = "$alt/$n";
            if (!is_file($f)) { echo "    $n: not there either\n"; continue; }
            $st = stat($f); $m = $st['mode'] & 0777;
            $r = $pwx && (($st['uid'] === $pwx['uid'] && ($m & 0400))
                       || ($st['gid'] === $pwx['gid'] && ($m & 0040))
                       || ($m & 0004));
            printf("    %s: present, uid=%d gid=%d mode=%04o -> %s by $WEB_USER%s\n",
                   $n, $st['uid'], $st['gid'], $m,
                   $r ? 'READABLE' : 'NOT readable',
                   ($n === 'key.pem' && ($m & 0004)) ? ' (and world-readable, which it should not be)' : '');
        }
        echo "    so ask 1's placement is testable from here; what is wrong is the rendered path.\n";
    }
    echo "\n  $pass passed, $fail failed\n";
    exit(1);
}

// ── (3) readable BY THE USER THIS PHP ACTUALLY RUNS AS ────────────────────────────────────────
//
// IT WAS HARDCODED TO `www-data` AND THAT IS NOT ALWAYS THE ANSWER. On the MODx grounds php-fpm is
// the container's www-data and the name is right. On skunkworks' own bench the pool runs as
// `skunkworks` (the tree's owner and the web user had to be one account so the cache
// could be cleared), and this suite reported "key.pem is NOT readable by www-data" on a ground where
// www-data is not the reader and the real reader can open both files. A check that names the wrong
// subject reports a defect that does not exist and hides the one that might.
//
// The user is DERIVED, in this order: MODX_WEB_USER if a caller names one, else the user this
// process is actually running as. When the suite runs under the pool itself — which is how
// MODX_PHP_RUN executes it on a ground — that is the same answer www-data was, and it stays correct
// when the pool is somebody else.
//
// AND ROOT IS NEVER THE ANSWER — that clause was missing and it is what made this suite red every
// night on sevak-nj. `ops.sh` runs it as `docker exec ... php`, with no `-u`, so the process
// IS root; the derivation then named root as the subject and the arithmetic below has no uid-0
// case, so it answered "key.pem is NOT readable by root" for a 0600 file owned by uid 33. Two
// things were wrong with that at once, and the second is the worse one:
//
//   * root BYPASSES DAC read permission, so the computed answer was simply false — this file's own
//     next assertion opens both files AS root and succeeds, one line below the FAIL;
//   * and root was never the subject worth asking about. `deliverStorageFile` runs as the pool
//     user. A green "root can read it" would say nothing about www-data, so answering for root is
//     not merely wrong, it is a question with no value either way.
//
// So root is skipped and the container's pool user is the fallback, which is what the docblock at
// the top of this file has said all along. That derivation still works where it was written to:
// on skunkworks' bench the suite runs AS the pool user, so `posix_geteuid()` names it and nothing
// here changes.
$WEB_USER = getenv('MODX_WEB_USER') ?: null;
if ($WEB_USER === null && function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    if (posix_geteuid() !== 0) {
        $me = posix_getpwuid(posix_geteuid());
        $WEB_USER = $me['name'] ?? null;
    }
}
$WEB_USER = $WEB_USER ?: 'www-data';
// The caller is the php-fpm worker, not this process. Ownership and mode are read and the
// question is answered for www-data's uid/gid specifically — inside the container, so this is
// the image's www-data and not a host user that happens to share the name.
$pw = function_exists('posix_getpwnam') ? posix_getpwnam($WEB_USER) : false;
if ($pw === false) {
    bad("there is no '$WEB_USER' user where this suite is running",
        'ask 1 is about the container\'s own www-data; if this ran on the host, the answer below '
      . 'would be about the wrong user entirely');
} else {
    foreach ([$cert, $key] as $f) {
        $st = stat($f);
        $mode = $st['mode'] & 0777;
        // uid 0 first: root bypasses DAC read permission, so every mode/owner clause below is
        // irrelevant for it. Only reachable when a caller NAMES root through MODX_WEB_USER — the
        // derivation above refuses to pick it — and it is here so the arithmetic is never false,
        // not to make root a sensible subject.
        $readable = $pw['uid'] === 0
                 || ($st['uid'] === $pw['uid'] && ($mode & 0400))
                 || ($st['gid'] === $pw['gid'] && ($mode & 0040))
                 || ($mode & 0004);
        $desc = sprintf('uid=%d gid=%d mode=%04o, ' . $WEB_USER . ' is uid=%d gid=%d',
                        $st['uid'], $st['gid'], $mode, $pw['uid'], $pw['gid']);
        $readable ? ok(basename($f) . " is readable by $WEB_USER ($desc)")
                  : bad(basename($f) . " is NOT readable by $WEB_USER", $desc
                      . ' — deliverStorageFile runs as this user, so the plugin would take the '
                      . 'bearer path on a ground that looks correctly configured');
    }
    // And if we happen to BE that user, the computed answer is checked against a real read.
    if (function_exists('posix_geteuid') && posix_geteuid() === $pw['uid']) {
        (is_readable($cert) && is_readable($key))
            ? ok("  and running AS $WEB_USER, both files really do open")
            : bad("running as $WEB_USER, a file the mode says is readable did not open",
                  'the computed answer above disagrees with the filesystem');
    }
}

// ── (4) the key is not world-readable, which nobody asked for and everybody wants ──────────────
$kmode = stat($key)['mode'] & 0777;
($kmode & 0004)
    ? bad(sprintf('key.pem is world-readable (mode %04o)', $kmode),
          'every process on the ground can present MODx\'s identity to the storage door')
    : ok(sprintf('key.pem is not world-readable (mode %04o)', $kmode));

echo "\n  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
