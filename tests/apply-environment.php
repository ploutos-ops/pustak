<?php
/**
 * apply-environment — the comparison setup/apply-environment.php writes from, with no database.
 *
 * settings() refuses a file that does not say which environment it is or what differs in it;
 * plan() lists exactly the values that differ; line() never prints a credential.
 *
 *   php tests/apply-environment.php
 */
declare(strict_types=1);
require dirname(__DIR__) . '/setup/lib/ApplyEnvironment.php';
use Pustak\Setup\ApplyEnvironment as A;

$pass = 0; $fail = 0;
function check(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; if ($detail !== '') echo "        $detail\n"; }
}
function refuses($app, string $needle, string $label): void {
    try { A::settings($app); check(false, $label, 'accepted'); }
    catch (\RuntimeException $e) { check(strpos($e->getMessage(), $needle) !== false, $label, $e->getMessage()); }
}

$good = ['environment' => 'staging', 'whoami' => 'x', 'settings' => [
    'system'   => ['estate_env' => 'staging', 'storage.modx_token' => 'abc'],
    'contexts' => ['worldview' => ['http_host' => 'worldview.modx.internal.test', 'site_url' => 'https://worldview.modx.internal.test/']],
]];

refuses(null, 'does not return an array', 'a file that returns nothing is refused');
refuses(['settings' => []], "names no 'environment'", 'no environment is refused');
refuses(['environment' => ' '], "names no 'environment'", 'a blank environment is refused');
refuses(['environment' => 'staging'], "has no 'settings'", 'no settings is refused');
refuses(['environment' => 'staging', 'settings' => ['users' => []]], 'only', 'an unknown section is refused');
refuses(['environment' => 'staging', 'settings' => ['system' => ['a' => 1]]], 'not a string', 'a value that is not a string is refused');
refuses(['environment' => 'staging', 'settings' => ['contexts' => ['mgr' => []]]], 'not a context', 'the manager context is refused');
refuses(['environment' => 'staging', 'settings' => ['contexts' => ['web' => 'x']]], 'not a map', 'a context that is not a map is refused');

$want = A::settings($good);
check($want['system']['estate_env'] === 'staging' && isset($want['contexts']['worldview']), 'a well-formed file is read whole');

$same = ['system' => $want['system'], 'contexts' => $want['contexts']];
check(A::plan($want, $same) === [], 'nothing differs: the plan is empty');

$have = ['system' => ['estate_env' => 'production'],
         'contexts' => ['worldview' => ['http_host' => 'worldview.modx.internal.test', 'site_url' => 'https://intranet.example.org/']]];
$plan = A::plan($want, $have);
check(count($plan) === 3, 'three values differ: one changed, one missing, one context URL', json_encode($plan));
check(in_array(['system', 'estate_env', 'production', 'staging'], $plan, true), 'a changed value carries from and to');
check(in_array(['system', 'storage.modx_token', null, 'abc'], $plan, true), 'a missing row is a change from null');
check(!in_array('http_host', array_column($plan, 1), true), 'an equal value is not in the plan');

$secret = A::line(['system', 'storage.modx_token', null, 'abc']);
check(strpos($secret, 'abc') === false && strpos($secret, 'not printed') !== false, 'a token is never printed', $secret);
check(strpos(A::line(['worldview', 'sso.oidc_client_secret', 'old', 'new']), 'new') === false, 'a client secret is never printed');
check(strpos(A::line(['system', 'estate_env', 'production', 'staging']), "'production' -> 'staging'") !== false, 'a plain value is printed from -> to');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
