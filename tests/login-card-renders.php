<?php
/**
 * login-card-renders — the sign-in card compiles, and it never draws Google's mark for a door that
 * does not go to Google.
 *
 * WHY THIS EXISTS. The card was restyled on 2026-09-12 (the owner: "the login screens are pretty dull.
 * can we make consistent like trump or kuber") and the restyle put a BRANCH in it: the provider's
 * real four-colour artwork where the icon is Google's, the snippet's bootstrap icon otherwise. A
 * branch in a template is a thing that can be wrong in one arm only, and the wrong arm here is the
 * defect coming back — a button that promises Google and goes to the estate provider. The owner
 * met that himself on 2026-08-20: "when i click on google, it says cannot connect." It was never
 * going to Google.
 *
 * It also catches the other failure the restyle risked: a Fenom template that does not COMPILE
 * takes the sign-in page down, and nothing else in this suite renders a chunk.
 *
 * Uses pdoTools' own vendored Fenom — the same engine MODx renders it with, not a stand-in.
 *
 *   php tests/login-card-renders.php          (needs $MODX_ROOT for the vendored Fenom)
 */

$root = dirname(__DIR__);
$modxRoot = getenv('MODX_ROOT') ?: '/srv/local/modx';
$fenom = $modxRoot . '/core/components/pdotools/vendor/fenom/fenom/src/Fenom.php';

if (!is_file($fenom)) {
    fwrite(STDERR, "REFUSING: no vendored Fenom at $fenom, so the card cannot be rendered with the\n"
        . "          engine MODx uses. This is a statement about the ground, not about the chunk.\n"
        . "          Set MODX_ROOT to an installed MODx.\n");
    exit(2);
}

require $fenom;
Fenom::registerAutoload();

$tmp = sys_get_temp_dir() . '/login-card-' . getmypid();
@mkdir($tmp . '/tpl', 0o700, true);
@mkdir($tmp . '/cache', 0o700, true);
copy($root . '/common/chunks/Bootstrap.5.googleLogin.html', $tmp . '/tpl/login.tpl');
register_shutdown_function(function () use ($tmp) {
    foreach (['tpl/login.tpl', 'tpl', 'cache'] as $p) { @is_dir("$tmp/$p") ? @rmdir("$tmp/$p") : @unlink("$tmp/$p"); }
    foreach (glob("$tmp/cache/*") ?: [] as $f) { @unlink($f); }
    @rmdir("$tmp/cache"); @rmdir($tmp);
});

$pass = 0;
$fail = 0;
function check(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; if ($detail !== '') { echo "          $detail\n"; } }
}

$f = Fenom::factory($tmp . '/tpl', $tmp . '/cache', Fenom::FORCE_COMPILE);
$render = function (array $vars) use ($f) {
    return $f->fetch('login.tpl', $vars + ['site_name' => 'Claude', 'startUrl' => '/sso-login?sso=go']);
};

echo "login-card-renders — the card compiles, and the mark matches the door\n\n";

// ── 1. it compiles at all, in the shape a caller passing nothing produces ──────────────────────
$bare = $render([]);
check(strpos($bare, 'Claude') !== false && strpos($bare, '/sso-login?sso=go') !== false,
      'the card renders with only site_name and startUrl',
      'Fenom produced nothing usable from the chunk');

// A caller that passes neither label nor icon must behave EXACTLY as before those placeholders
// existed — that is the compatibility promised when they were added.
check(strpos($bare, 'Continue with Google') !== false && strpos($bare, '#EA4335') !== false,
      'a caller passing no label or icon still gets the Google wording and mark');

// ── 2. Google's door draws Google's mark ───────────────────────────────────────────────────────
$google = $render(['btn_icon' => 'bi-google', 'btn_label' => 'Continue with Google']);
check(strpos($google, '#EA4335') !== false && strpos($google, '#4285F4') !== false,
      'the Google door draws the four-colour mark, not an icon-font glyph');
check(strpos($google, '<i class="bi bi-google"') === false,
      '  and does not ALSO draw the old glyph beside it');

// ── 3. THE ONE THAT MATTERS: another provider draws no Google artwork and says no Google word ──
$estate = $render(['btn_icon' => 'bi-box-arrow-in-right', 'btn_label' => 'Estate sign-in']);
check(strpos($estate, '#EA4335') === false && strpos($estate, '#34A853') === false,
      'a NON-Google door draws NO Google artwork — the defect the owner met himself',
      'the button promises Google and goes somewhere else');
check(stripos($estate, 'google') === false,
      '  and the word Google appears nowhere on it',
      'a reader is still told Google');
check(strpos($estate, 'bi-box-arrow-in-right') !== false && strpos($estate, '>Estate sign-in<') !== false,
      '  it draws the icon and label the snippet resolved');

// ── 4. the manager notice still renders, and only when there is one ────────────────────────────
$notice = $render(['notice' => 'Your MANAGER session is still open.']);
check(strpos($notice, 'alert-warning') !== false && strpos($notice, 'MANAGER session') !== false,
      'a manager-session notice renders as an alert');
check(strpos($bare, 'alert-warning') === false,
      '  and an ordinary visit carries none');

// ── 6. a WHOLE PAGE, centred — the sso-login resources have template 0 (2026-09-14) ─────────────
// Production served the card as a bare fragment: no <html>, no stylesheet, nothing centred.
check(stripos($bare, '<!doctype html>') !== false && strpos($bare, '</html>') !== false,
      'the chunk is a complete HTML document, not a fragment',
      'template-0 resources send this chunk as the whole response');
check(strpos($bare, '[[$Bootstrap.5.Head]]') !== false,
      '  it carries the shared head, which loads the estate stylesheet');
check(strpos($bare, 'min-vh-100') !== false && strpos($bare, 'align-items-center') !== false
      && strpos($bare, 'justify-content-center') !== false,
      '  and centres the card in the viewport, as trump\'s sign-in screen does');
check(strpos($bare, '>[[++estate_app_name]]<') !== false && strpos($bare, '>Claude<') !== false,
      'the heading is the application\'s name (estate_app_name, from the manifest) and the line under it is the context\'s own name');
check(stripos($bare, 'trump') === false,
      'nothing from trump appears on the page (the owner: "i do not want to show Trump")');

// ── 7. the logo is the company's own, and the bird is the default ─────────────────
check(strpos($bare, '<img') === false,
      'the chunk types no default logo: with none passed it draws no image');
$start = file_get_contents(dirname(__DIR__) . '/common/snippets/GoogleSSOStart.php');
check(strpos($start, "getOption('estate_cdn_url'), '/') . '/brand/20260730/favicon.svg'") !== false,
      'the bird ("if empty, then bird") is GoogleSSOStart\'s default, built from the estate_cdn_url setting');
$wv = $render(['logo' => 'https://cdn.example.org/brand/worldview/20260914/worldview.png', 'site_name' => 'Worldview']);
check(strpos($wv, 'brand/worldview/20260914/worldview.png') !== false
      && strpos($wv, 'brand/20260730/favicon.svg') === false,
      'a context with its own logo shows that logo instead of the bird');

// ── 8. a Privacy Policy link only where one is configured ──────────────────────────────────────
check(strpos($bare, 'Privacy Policy') === false,
      'no privacy_url, no link — a link to nothing is worse than none');
$pp = $render(['privacy' => 'https://example.invalid/privacy']);
check(strpos($pp, 'href="https://example.invalid/privacy"') !== false && strpos($pp, 'Privacy Policy') !== false,
      'with privacy_url set, the Privacy Policy link renders to it');

// ── 5. the control: this test can tell the arms apart ──────────────────────────────────────────
// Without this, every assertion above would pass against a chunk that ignored btn_icon entirely
// and always drew the same thing.
check($google !== $estate,
      'control: the two providers render DIFFERENTLY',
      'the chunk renders one output for every provider, so nothing above proves anything');
check($wv !== $bare && $pp !== $bare,
      'control: a logo and a privacy link each change the output',
      'the chunk ignores the new placeholders, so sections 7 and 8 prove nothing');

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
