<?php
/**
 * context-base — a link from one context to another reads the OTHER context's own address from
 * MODx, and no setting holds a second copy of it.
 *
 * A Corp button opened an address kept in the setting `corp.base_url`.
 * That was a second copy of a fact MODx already holds per context, kept in step by a hand-typed
 * list per ground — and staging's was found a rename behind. The snippet contextBase reads the one
 * copy. This suite runs the snippet's own body against a stubbed $modx, the way
 * tests/unauthorized-names-itself.php does, and reads the tree for any reader of the old setting.
 *
 *   php tests/context-base.php
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
function check(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; if ($detail !== '') echo "        $detail\n"; }
}

class FakeContext {
    private string $key; private array $opts;
    public function __construct(string $key, array $opts) { $this->key = $key; $this->opts = $opts; }
    public function get($k) { return $k === 'key' ? $this->key : null; }
    public function getOption($k, $options = null, $default = null) { return $this->opts[$k] ?? $default; }
}
class FakeModx {
    public $context; private array $contexts; private array $request;
    /** $stored: context key => its stored site_url. $request: what this request's settings say. */
    public function __construct(string $current, array $stored, array $request) {
        foreach ($stored as $k => $url) { $this->contexts[$k] = new FakeContext($k, ['site_url' => $url]); }
        $this->context = $this->contexts[$current]; $this->request = $request;
    }
    public function getContext($key) { return $this->contexts[$key] ?? null; }
    public function getOption($k, $options = null, $default = null) { return $this->request[$k] ?? $default; }
}

$SNIPPET = dirname(__DIR__) . '/common/snippets/contextBase.php';
$CODE = (string) file_get_contents($SNIPPET);
function ask(string $code, FakeModx $modx, array $scriptProperties): string {
    return (string) (function () use ($modx, $code, $scriptProperties) { return eval($code); })();
}

// A reader on family, come by a NEW name the stored setting does not hold.
$stored = ['family' => 'https://family.example.test/', 'corporate' => 'https://corporate.example.test/',
           'relative' => '/', 'mgr' => 'https://family.example.test/manager/'];
$modx = new FakeModx('family', $stored, ['site_url' => 'https://family.stage.example.test/']);

echo "contextBase — another context's address is that context's own\n";
check(ask($CODE, $modx, ['context' => 'corporate']) === 'https://corporate.example.test',
      'another context: its own site_url, no trailing slash', ask($CODE, $modx, ['context' => 'corporate']));
check(ask($CODE, $modx, ['context' => 'family']) === 'https://family.stage.example.test',
      'the current context: the address of THIS request, not the stored one',
      ask($CODE, $modx, ['context' => 'family']));
check(ask($CODE, $modx, ['context' => 'no-such-context']) === '', 'a key no context has: nothing');
check(ask($CODE, $modx, ['context' => 'mgr']) === '', 'the manager: nothing');
check(ask($CODE, $modx, ['context' => '']) === '' && ask($CODE, $modx, []) === '', 'no context given: nothing');
check(ask($CODE, $modx, ['context' => 'relative']) === '', 'a site_url that is no http(s) address: nothing');

// THE CONTROL (P11). Break the snippet two ways and require the assertions above to notice.
$guessing = str_replace("\$url = \$ctx ? (string) \$ctx->getOption('site_url') : '';",
                        "\$url = \$ctx ? (string) \$ctx->getOption('site_url') : 'https://guess.example.test/';", $CODE, $n1);
$slashed  = str_replace("rtrim(\$url, '/')", '$url', $CODE, $n2);
check($n1 === 1 && ask($guessing, $modx, ['context' => 'no-such-context']) !== '',
      'control: a snippet that guesses an address for an unknown context is seen');
check($n2 === 1 && ask($slashed, $modx, ['context' => 'corporate']) !== 'https://corporate.example.test',
      'control: a snippet that keeps the trailing slash is seen');

echo "the tree — nothing reads the second copy\n";
$common = dirname(__DIR__) . '/common';
$readers = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($common, FilesystemIterator::SKIP_DOTS)) as $f) {
    if (strpos((string) file_get_contents((string) $f), '[[++corp.base_url]]') !== false
        || preg_match("/getOption\\(\\s*['\"]corp\\.base_url/", (string) file_get_contents((string) $f))) {
        $readers[] = substr((string) $f, strlen($common) + 1);
    }
}
check($readers === [], 'no element under common/ reads corp.base_url', implode(', ', $readers));
$two = (string) file_get_contents(dirname(__DIR__) . '/setup/02-system-settings.py');
check((bool) preg_match("/RETIRED = \\([^)]*'corp\\.base_url'/s", $two), 'setup/02 retires corp.base_url');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
