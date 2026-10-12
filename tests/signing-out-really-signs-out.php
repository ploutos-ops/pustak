<?php
/**
 * signing-out-really-signs-out.php — what the sign-out actually ends, and what it says.
 *
 *     php tests/signing-out-really-signs-out.php
 *
 * The owner, 2026-08-14: *"i cannot logout when manager is logged in. not sure if that is a feature or
 * a bug"*. It is a bug, and it had two halves.
 *
 * THE HALF THAT WAS TRUE FOR EVERYONE. `GoogleSSOLogout` removed the context token and nothing
 * else, leaving every key the SSO callback had written in the session — `sso.verified_email`,
 * which tableRenderer's `&auth` asserts to kuber, and `sso.id_token`, which the bookkeeping-names
 * plugin presents. Signing out left an identity behind that this estate would still vouch for.
 *
 * THE MANAGER HALF. A manager session lives in the `mgr` context and the sign-out ends one
 * context, the web one. That is deliberate — ending `mgr` would log him out of the manager
 * mid-edit — but nothing said so, so the site simply kept recognising him.
 *
 * WHY THIS SUITE IS STATIC. Both halves are session behaviour inside a running MODx, and asserting
 * them for real needs a browser, a manager login and a Google sign-in. What can be asserted here
 * is that the code does the two things it must and does not do the one it must not — and those are
 * exactly the properties that would rot silently, because nothing about the old code LOOKED wrong.
 * Every check below is drilled: each fails when its line is removed.
 */

$pass = 0;
$fail = 0;
function ok(string $l): void   { global $pass; $pass++; echo "  ok    $l\n"; }
function bad(string $l, string $d = ''): void {
    global $fail; $fail++; echo "  FAIL  $l\n"; if ($d !== '') echo "        $d\n";
}
function check(bool $c, string $l, string $d = ''): void { $c ? ok($l) : bad($l, $d); }

$root = dirname(__DIR__);
// Comment lines stripped before anything is located: three checks of mine were fooled by their own
// documentation on 2026-08-14, and this file documents heavily on purpose.
$code = function (string $rel) use ($root): string {
    $lines = file($root . '/' . $rel, FILE_IGNORE_NEW_LINES);
    return implode("\n", array_filter($lines, fn($l) => !preg_match('#^\s*(//|\*|/\*|\{\*)#', $l)));
};

$logout = $code('common/snippets/GoogleSSOLogout.php');
$start  = $code('common/snippets/GoogleSSOStart.php');
$chunk  = file_get_contents($root . '/common/chunks/Bootstrap.5.googleLogin.html');

echo "signing-out-really-signs-out — the sign-out ends the identity, and names what it cannot end\n\n";

// ── 1. the identity this site issued is dropped ────────────────────────────────────────────────
foreach (['sso.verified_email', 'sso.id_token', 'sso.id_token_exp'] as $key) {
    check(strpos($logout, "'$key'") !== false,
          "logout drops $key",
          "it survives a sign-out, and downstream still vouches for the reader with it");
}

// ── 2. and it does NOT end the manager session ─────────────────────────────────────────────────
// The one thing this fix must not do. A sign-out that logs him out of the manager mid-edit is a
// worse defect than the one being fixed, so the absence is asserted as deliberately as the
// presence.
check(!preg_match("#removeSessionContext\(\s*['\"]mgr['\"]#", $logout),
      "and it does NOT end the manager session — that would log him out mid-edit",
      'the manager session is ended somewhere in the sign-out');
check(strpos($logout, "isAuthenticated('mgr')") !== false,
      '  but it NOTICES one, so the reader can be told',
      'without this the sign-out cannot say why the site still knows them');

// ── 3. the reader is told, on the page they land on ────────────────────────────────────────────
check(strpos($logout, "mgr=open") !== false,
      'the sign-out carries the fact to the page it redirects to');
check(strpos($start, "'mgr'") !== false && strpos($start, "notice") !== false,
      'the sign-in page turns it into a sentence');
check(strpos($chunk, '$notice') !== false,
      'and the card renders that sentence');

// ── 4. the control: a notice must not appear on an ordinary visit ──────────────────────────────
// Without this, every check above would pass on a page that shouts at every visitor.
check((bool) preg_match('#\{if \$notice\?\}#', $chunk),
      'the notice is conditional — an ordinary sign-in page carries none',
      'a card that always shows it trains the reader to ignore it');

// ── 5. the sign-in page must not bounce a merely-KNOWN user ────────────────────────────────────
// The owner, testing this on production: 'with manager open, when i sign out, i go to home'. The
// sign-out was correct; GoogleSSOStart's loop guard was not. It redirected anyone getUser()
// returned — and a manager session returns him — so the page he was sent to forwarded him onward
// before it could render the notice. The guard has to ask whether the visitor is authenticated in
// THIS context, which is exactly what the sign-out just undid.
$start = $code('common/snippets/GoogleSSOStart.php');
check(strpos($start, 'isAuthenticated(') !== false,
      "the sign-in page's loop guard asks isAuthenticated(context)",
      'a bare getUser() check bounces anyone with any session, including a manager one');
check(!preg_match('#if \s*\(\s*\$current\s*&&\s*\(int\)\s*\$current->get\(.id.\)\s*>\s*0\s*\)#', $start),
      '  and not merely whether a user is known',
      'the old shape is back: known != entitled to this context');

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
