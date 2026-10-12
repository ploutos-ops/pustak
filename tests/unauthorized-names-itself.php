<?php
/**
 * unauthorized-names-itself — a refused page must say who was refused, and must never be a loop.
 * Under the failures-name-themselves standing rule.
 *
 * THE BUG THIS LOCKS DOWN. `unauthorized_page` and `login_page` were the same resource, so a
 * signed-in non-member was bounced: refused -> sign-in -> "already signed in" -> site_start ->
 * refused. No sentence was ever shown, so there was nothing for the person to report and nothing
 * for the operator to search a log for. The owner hit it on staging on 2026-08-12.
 *
 * WHAT IS ASSERTED, and every one of these is a behaviour rather than a string:
 *   - anonymous  -> a redirect to login_page, carrying the ORIGINAL path as returnUrl
 *   - signed in  -> NO redirect, ever. A redirect here is the loop, whatever it says.
 *   - signed in  -> the account is named on the screen, ACC-01 is on the screen AND in the log
 *   - signed in  -> no sign-in link is offered (it cannot help and it re-enters the bounce)
 *   - the misconfigurations answer with their own codes instead of an empty href or a self-forward
 *   - returnUrl is same-site only, because the snippet hands it back out as a query parameter
 *
 * NO DATABASE AND NO MODx INSTALL. The unit under test is a decision about the visitor's session;
 * standing up MODx to ask it would test the database instead. The fakes below are the smallest
 * surface the snippet actually touches, which is also the list of things that would break it.
 *
 * A known-answer control runs FIRST: the harness is shown to be capable of failing before any
 * result from it is believed. A green suite over a snippet that never executed is the failure this
 * repo has already had (tests/element-names-bind.sh, and the four mutation drills that mutated
 * nothing).
 *
 *   php tests/unauthorized-names-itself.php
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
function check(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; if ($detail !== '') echo "        $detail\n"; }
}

// ── the smallest MODx the snippet touches ──────────────────────────────────────────────────────
class modX { const LOG_LEVEL_WARN = 2; const LOG_LEVEL_ERROR = 1; }
class modResource {
    private array $f;
    public function __construct(array $f) { $this->f = $f; }
    public function get($k) { return $this->f[$k] ?? null; }
}
class FakeProfile {
    private array $f;
    public function __construct(array $f) { $this->f = $f; }
    public function get($k) { return $this->f[$k] ?? null; }
}
class FakeUser {
    private array $f; private $profile;
    public function __construct(array $f, $profile = null) { $this->f = $f; $this->profile = $profile; }
    public function get($k) { return $this->f[$k] ?? null; }
    public function getOne($what) { return $what === 'Profile' ? $this->profile : null; }
}
class FakeContext {
    private string $key;
    public function __construct(string $key) { $this->key = $key; }
    public function get($k) { return $k === 'key' ? $this->key : null; }
}
class FakeModx {
    private array $opts; private $user;
    public $resource; public $context;
    public array $logs = []; public array $redirects = [];
    public function __construct(array $opts, $user, $resource, string $ctx = 'family') {
        $this->opts = $opts; $this->user = $user;
        $this->resource = $resource; $this->context = new FakeContext($ctx);
    }
    public function getOption($k) { return $this->opts[$k] ?? null; }
    public function getUser() { return $this->user; }
    public function getChunk($name) { return $name === 'Bootstrap.5.Head' ? '<head></head>' : ''; }
    public function log($level, $msg) { $this->logs[] = $msg; }
    /** Mirrors modX::makeUrl's shape closely enough for the assertions: id + query args. */
    public function makeUrl($id, $ctx = '', $args = '', $scheme = '') {
        $url = 'https://home.example/resource/' . (int) $id;
        if (is_array($args) && $args) { $url .= '?' . http_build_query($args); }
        return $url;
    }
    public function sendRedirect($url) { $this->redirects[] = $url; }
}

$SNIPPET = dirname(__DIR__) . '/common/snippets/unauthorizedNotice.php';
if (!is_file($SNIPPET)) {
    fwrite(STDERR, "REFUSING: no common/snippets/unauthorizedNotice.php — run this from the repo.\n");
    exit(2);
}
$CODE = file_get_contents($SNIPPET);

/** Run the snippet body with $modx bound, exactly as MODx evaluates it, and return its output. */
function run(FakeModx $modx, string $uri, string $code): string {
    $_SERVER['REQUEST_URI'] = $uri;
    $out = (function () use ($modx, $code) { return eval($code); })();
    return (string) $out;
}

// A signed-in visitor, a member of nothing that matters here.
$member = function (string $email) {
    return new FakeUser(['id' => 7, 'username' => 'a-member'], new FakeProfile(['email' => $email]));
};
$WIRED = ['login_page' => 101, 'logout_page' => 102];   // the shape setup/12 leaves behind
$THIS  = new modResource(['id' => 103]);                 // the refusal page itself

echo "unauthorizedNotice — a refusal names itself, and is never a loop\n\n";

// ── 0. the control ─────────────────────────────────────────────────────────────────────────────
// Prove the harness can see a wrong answer before believing a right one. The snippet is run with a
// deliberately broken expectation; if this "passes", every assertion below is meaningless.
$m = new FakeModx($WIRED, $member('reader@example.com'), $THIS);
$out = run($m, '/family/bank', $CODE);
check(strpos($out, 'THIS STRING IS NOT IN THE SNIPPET') === false,
      'control: the harness reads real output (a string that is not there is not found)');
check($out !== '', 'control: the snippet produced output at all', 'empty output means eval() did nothing');

// ── 1. anonymous — forward to sign-in, carrying the deep link ──────────────────────────────────
$m = new FakeModx($WIRED, null, $THIS);
$out = run($m, '/family/bank/statement-2026', $CODE);
check(count($m->redirects) === 1, 'anonymous: redirected to sign in', json_encode($m->redirects));
check(count($m->redirects) === 1 && strpos($m->redirects[0], '/resource/101') !== false,
      'anonymous: the redirect target is login_page');
check(count($m->redirects) === 1 && strpos($m->redirects[0], urlencode('/family/bank/statement-2026')) !== false,
      'anonymous: the page they asked for survives as returnUrl', $m->redirects[0] ?? '');

// ── 2. an off-site returnUrl is not handed back out ────────────────────────────────────────────
$m = new FakeModx($WIRED, null, $THIS);
run($m, '//evil.example/steal', $CODE);
check(count($m->redirects) === 1 && strpos($m->redirects[0], 'evil.example') === false,
      'anonymous: a protocol-relative REQUEST_URI is not reflected', $m->redirects[0] ?? '');

// ── 3. signed in and refused — THE case that used to loop ──────────────────────────────────────
$m = new FakeModx($WIRED, $member('reader@example.com'), $THIS);
$out = run($m, '/family/bank', $CODE);
check($m->redirects === [], 'signed in: NOT redirected — a redirect here is the loop itself',
      json_encode($m->redirects));
check(strpos($out, 'reader@example.com') !== false, 'signed in: the account is named on screen');
check(strpos($out, 'ACC-01') !== false, 'signed in: the screen carries the reference code');
check(count($m->logs) === 1 && strpos($m->logs[0], 'ACC-01') !== false,
      'signed in: the SAME code is in the log', json_encode($m->logs));
check(count($m->logs) === 1 && strpos($m->logs[0], '/family/bank') !== false,
      'signed in: the log names the refused path', json_encode($m->logs));
check(count($m->logs) === 1 && strpos($m->logs[0], 'family') !== false,
      'signed in: the log names the context');
check(strpos($out, '/resource/101') === false,
      'signed in: no sign-in link is offered — it cannot help and re-enters the bounce');
check(strpos($out, '/resource/102') !== false,
      'signed in: the one action that CAN work (sign out) is offered');

// ── 4. the account has no profile email — fall back, never blank ───────────────────────────────
$m = new FakeModx($WIRED, new FakeUser(['id' => 7, 'username' => 'b-member'], null), $THIS);
$out = run($m, '/worldview/deck', $CODE);
check(strpos($out, 'b-member') !== false, 'no profile email: the username is named instead of nothing');

// ── 5. misconfigurations answer for themselves ─────────────────────────────────────────────────
$m = new FakeModx(['logout_page' => 102], null, $THIS);          // no login_page at all
$out = run($m, '/family/bank', $CODE);
check($m->redirects === [] && strpos($out, 'ACC-02') !== false,
      'no login_page: ACC-02 rather than an empty href', $out);

$m = new FakeModx(['login_page' => 103, 'logout_page' => 102], null, $THIS);  // login_page IS this page
$out = run($m, '/family/bank', $CODE);
check($m->redirects === [] && strpos($out, 'ACC-03') !== false,
      'login_page == this page: ACC-03 rather than forwarding to ourselves', $out);

// ── 6. the visitor is told nothing about the estate's shape ────────────────────────────────────
// The same line GoogleSSOCallback's $deny draws: their own session, and not one word about what
// groups exist or what else is there.
$m = new FakeModx($WIRED, $member('reader@example.com'), $THIS);
$out = run($m, '/family/bank', $CODE);
check(stripos($out, 'familyMember') === false && stripos($out, 'group') === false,
      'the refusal names no group and no membership requirement', $out);

// ── 7. the heading is the application's name from its manifest, escaped ─────────────────────────
// unauthorizedNotice types no name: it prints estate_app_name, which setup/02 writes from
// service.json identity.display_name. Two different values must give two different headings.
$m = new FakeModx($WIRED + ['estate_app_name' => 'Drill <Name>'], $member('reader@example.com'), $THIS);
$out = run($m, '/family/bank', $CODE);
check(strpos($out, '>Drill &lt;Name&gt;</h1>') !== false,
      'the heading prints estate_app_name, HTML-escaped', $out);
$m = new FakeModx($WIRED + ['estate_app_name' => 'Another'], $member('reader@example.com'), $THIS);
check(strpos(run($m, '/family/bank', $CODE), '>Another</h1>') !== false,
      '  and follows the setting when it changes');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
