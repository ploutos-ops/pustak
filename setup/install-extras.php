<?php
/**
 * Headless extension install (Layer 2) — proven on faltu-1, MODx 3.2.1-pl 2026-07-12.
 * Installs the Extras our elements depend on (pdoTools -> [[pdoMenu]]/Fenom, SimpleSearch -> search)
 * from the modx.com provider, with NO manager UI. Login was RETIRED: auth is Google SSO only
 * (GoogleSSOStart/Callback/Logout snippets); no [[!Login]] form, so the Login Extra is not installed.
 * Run as www-data:
 *   sudo -u www-data php setup/install-extras.php
 * Idempotent-ish: re-running re-downloads + re-installs the same signatures.
 */
// This was a HARDCODE, not even a default — the dev bench that stopped existing when the
// bench became staging (2026-08-14).
$root = getenv('MODX_ROOT') ?: ($argv[1] ?? '');
if (!$root || !is_file($root . '/config.core.php')) {
    fwrite(STDERR, "REFUSING: no config.core.php under '" . ($root ?: '(nothing)') . "'.\n"
                 . "          Set MODX_ROOT or pass the docroot. Nothing was installed.\n");
    exit(2);
}
require $root . '/config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';

$modx = new \MODX\Revolution\modX();
$modx->initialize('mgr');
$modx->setLogLevel(\MODX\Revolution\modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('ECHO');

$provider = $modx->getObject(\MODX\Revolution\Transport\modTransportProvider::class, 1); // modx.com
if (!$provider) { echo "NO PROVIDER id=1\n"; exit(2); }
echo "provider: " . $provider->get('name') . " " . $provider->get('service_url') . "\n";

foreach (['pdoTools', 'SimpleSearch'] as $name) {
    echo "\n=== $name ===\n";
    $versions = $provider->latest($name);           // array of {version,signature,location,...}
    if (!is_array($versions) || !$versions) { echo "latest() returned: "; var_export($versions); echo "\n"; continue; }
    usort($versions, fn($a, $b) => version_compare($b['version'] ?? '0', $a['version'] ?? '0'));
    $node = $versions[0];
    $sig = $node['signature'] ?? '';
    $loc = $node['location'] ?? '';
    echo "chosen version=" . ($node['version'] ?? '?') . " signature=$sig\n";
    if (!$sig || !$loc) { echo "missing sig/location\n"; continue; }
    $package = $provider->transfer($sig, null, ['location' => $loc]); // downloads + creates modTransportPackage
    if (!$package) { echo "transfer() FAILED (download/auth?)\n"; continue; }
    echo "downloaded: " . $package->get('signature') . "\n";
    echo $package->install() ? "INSTALLED OK\n" : "install() returned false\n"; // model method: no manager-permission check
}
echo "\ndone\n";
