<?php
/**
 * build-transport.php — the in-MODx parts of this repository as a MODx Extra: one transport package
 * that Package Management installs on a stock MODx 3.
 *
 *     MODX_ROOT=<any MODx 3 docroot> php tools/build-transport.php --source DIR --version V [--out DIR]
 *
 * --source is a tree laid out like this repository — normally what tools/publish-tree.sh archived,
 * so the package carries only files that passed the publish gate (tools/build-transport.sh does
 * exactly that). From it the package takes:
 *   common/chunks/*          chunks      (name = file name less .html, as import.py names them —
 *   common/snippets/*        snippets     only .html/.php are stripped, so claude.js stays claude.js)
 *   common/plugins/<name>    plugins, each bound to the events its <name>.events file lists
 *   common/templates/*       templates (name = file name less .html)
 *   common/tvs.json          template variables, each bound after install to the templates it names
 *   common/lib/              files, placed at core/components/pustak/lib/ — where every element
 *                            above requires them from
 *   docs/content-vocabulary.md   the package's readme: the words a page may use
 *   LICENSE                  the package's licence, when the tree holds one
 * Settings and contexts are not in it: they are the estate's, written by setup/.
 *
 * Elements are matched BY NAME on install (the same rule import.py applies), filed under the
 * category `pustak`, and their bodies are the files' bytes. MODX_ROOT names a MODx install only
 * because xPDO builds packages through one; nothing is written to its database.
 *
 * --version is the package's version, e.g. 1.0.0-pl; there is no default, because the number a
 * reader installs is a published fact and a guessed one would be published too.
 */

use MODX\Revolution\modCategory;
use MODX\Revolution\modChunk;
use MODX\Revolution\modPlugin;
use MODX\Revolution\modPluginEvent;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modX;
use MODX\Revolution\Transport\modPackageBuilder;
use xPDO\Transport\xPDOTransport;

const PKG = 'pustak';

$opt = getopt('', ['source:', 'version:', 'out:']);
$fail = function (string $msg, int $rc = 2) { fwrite(STDERR, "build-transport: $msg\n"); exit($rc); };
$source = rtrim($opt['source'] ?? '', '/') ?: $fail('--source DIR is required (the tree publish-tree.sh archived)');
$version = $opt['version'] ?? $fail('--version is required, e.g. 1.0.0-pl — there is no default');
if (!preg_match('/^(\d+\.\d+\.\d+)-([a-z]+\d*)$/', $version, $vm)) {
    $fail("--version '$version' is not <major>.<minor>.<patch>-<release>, e.g. 1.0.0-pl");
}
if (!is_dir("$source/common")) $fail("$source holds no common/");
$root = rtrim(getenv('MODX_ROOT') ?: '', '/');
if (!$root || !is_file("$root/config.core.php")) $fail("MODX_ROOT ('$root') is not a MODx docroot");

require "$root/config.core.php";
require MODX_CORE_PATH . 'vendor/autoload.php';
$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('ECHO');

$builder = new modPackageBuilder($modx);
$out = rtrim($opt['out'] ?? getcwd(), '/') . '/';
if (!is_dir($out)) $fail("--out $out is not a directory");
$builder->directory = $out;
$builder->createPackage(PKG, $vm[1], $vm[2]);
$builder->registerNamespace(PKG, false, true, '{core_path}components/' . PKG . '/');

$category = $modx->newObject(modCategory::class);
$category->set('category', PKG);

$chunks = [];
// THE DATABASE FORM (canon.py db_body): LF line endings, no trailing newline — what the element
// deploy and setup/ write, so an element installed by this package and one deployed by them are the
// same bytes, and the protection verifier finds no one-byte drift.
$dbBody = function (string $b): string {
    $b = str_replace(["\r\n", "\r"], "\n", $b);
    return str_ends_with($b, "\n") ? substr($b, 0, -1) : $b;
};
$elementName = fn(string $f) => preg_replace('/\.(html|php)$/', '', basename($f));
foreach (array_filter(glob("$source/common/chunks/*"), 'is_file') as $f) {
    $o = $modx->newObject(modChunk::class);
    $o->fromArray(['name' => $elementName($f), 'snippet' => $dbBody(file_get_contents($f))], '', true, true);
    $chunks[] = $o;
}
$snippets = [];
foreach (array_filter(glob("$source/common/snippets/*"), 'is_file') as $f) {
    $o = $modx->newObject(modSnippet::class);
    $o->fromArray(['name' => $elementName($f), 'snippet' => $dbBody(file_get_contents($f))], '', true, true);
    $snippets[] = $o;
}
$plugins = [];
foreach (glob("$source/common/plugins/*") as $f) {
    if (str_ends_with($f, '.events')) continue;
    $name = basename($f);
    if (!is_file("$f.events")) $fail("plugin $name has no $name.events — a plugin bound to nothing does nothing");
    $o = $modx->newObject(modPlugin::class);
    $o->fromArray(['name' => $name, 'plugincode' => $dbBody(file_get_contents($f))], '', true, true);
    $events = [];
    foreach (array_filter(array_map('trim', file("$f.events"))) as $event) {
        $e = $modx->newObject(modPluginEvent::class);
        $e->fromArray(['event' => $event, 'priority' => 0, 'propertyset' => 0], '', true, true);
        $events[] = $e;
    }
    $o->addMany($events, 'PluginEvents');
    $plugins[] = $o;
}
$templates = [];
foreach (array_filter(glob("$source/common/templates/*"), 'is_file') as $f) {
    $o = $modx->newObject(modTemplate::class);
    $o->fromArray(['templatename' => $elementName($f), 'content' => $dbBody(file_get_contents($f))], '', true, true);
    $templates[] = $o;
}
// Template variables, and which templates carry each. The binding row needs both ids, which only
// exist on the target, so it is made after install by a resolver that looks both up BY NAME.
$tvs = []; $bind = [];
if (is_file("$source/common/tvs.json")) {
    $decl = json_decode(file_get_contents("$source/common/tvs.json"), true);
    if (!is_array($decl)) $fail('common/tvs.json is not a JSON object');
    $names = array_map($elementName, array_filter(glob("$source/common/templates/*"), 'is_file'));
    foreach ($decl as $name => $tv) {
        if ($name[0] === '$') continue;
        foreach ($tv['templates'] ?? [] as $t) {
            if (!in_array($t, $names, true)) $fail("common/tvs.json binds $name to template $t, which common/templates/ does not hold");
        }
        $o = $modx->newObject(modTemplateVar::class);
        $o->fromArray(['name' => $name, 'caption' => (string) ($tv['caption'] ?? $name),
                       'type' => (string) ($tv['type'] ?? 'text'), 'description' => (string) ($tv['description'] ?? ''),
                       'display' => 'default', 'default_text' => ''], '', true, true);
        $tvs[] = $o;
        $bind[$name] = array_values($tv['templates'] ?? []);
    }
}
$category->addMany($chunks, 'Chunks');
$category->addMany($snippets, 'Snippets');
$category->addMany($plugins, 'Plugins');
$category->addMany($templates, 'Templates');
$category->addMany($tvs, 'TemplateVars');
$counts = ['chunks' => count($chunks), 'snippets' => count($snippets), 'plugins' => count($plugins),
           'templates' => count($templates), 'tvs' => count($tvs)];

$byName = [xPDOTransport::PRESERVE_KEYS => false, xPDOTransport::UPDATE_OBJECT => true, xPDOTransport::UNIQUE_KEY => 'name'];
$vehicle = $builder->createVehicle($category, [
    xPDOTransport::UNIQUE_KEY => 'category',
    xPDOTransport::PRESERVE_KEYS => false,
    xPDOTransport::UPDATE_OBJECT => true,
    xPDOTransport::RELATED_OBJECTS => true,
    xPDOTransport::RELATED_OBJECT_ATTRIBUTES => [
        'Chunks' => $byName,
        'Snippets' => $byName,
        'Templates' => [xPDOTransport::PRESERVE_KEYS => false, xPDOTransport::UPDATE_OBJECT => true, xPDOTransport::UNIQUE_KEY => 'templatename'],
        'TemplateVars' => $byName,
        'Plugins' => $byName + [
            xPDOTransport::RELATED_OBJECTS => true,
            xPDOTransport::RELATED_OBJECT_ATTRIBUTES => [
                'PluginEvents' => [
                    xPDOTransport::PRESERVE_KEYS => true,
                    xPDOTransport::UPDATE_OBJECT => false,
                    xPDOTransport::UNIQUE_KEY => ['pluginid', 'event'],
                ],
            ],
        ],
    ],
]);
// The library travels as files. The file resolver copies the SOURCE directory itself into the
// target, so it is staged as <tmp>/pustak/lib and lands at core/components/pustak/lib.
$stage = sys_get_temp_dir() . '/pustak-build-' . getmypid();
@mkdir("$stage/" . PKG, 0755, true);
exec('cp -a ' . escapeshellarg("$source/common/lib") . ' ' . escapeshellarg("$stage/" . PKG . '/lib'), $_, $rc);
if ($rc !== 0) $fail("could not stage common/lib");
$vehicle->resolve('file', ['source' => "$stage/" . PKG, 'target' => "return MODX_CORE_PATH . 'components/';"]);
$resolver = "$stage/bind-tvs.php";
file_put_contents($resolver, "<?php\n// Binds each template variable to the templates common/tvs.json names, by name, on install and upgrade.\n"
    . '$bind = ' . var_export($bind, true) . ";\n" . <<<'PHP'
$modx = $transport->xpdo;
if (!in_array($options[xPDO\Transport\xPDOTransport::PACKAGE_ACTION] ?? null,
              [xPDO\Transport\xPDOTransport::ACTION_INSTALL, xPDO\Transport\xPDOTransport::ACTION_UPGRADE], true)) {
    return true;
}
foreach ($bind as $tvName => $templates) {
    $tv = $modx->getObject(MODX\Revolution\modTemplateVar::class, ['name' => $tvName]);
    if (!$tv) { $modx->log(xPDO\xPDO::LOG_LEVEL_ERROR, "pustak: template variable $tvName was not installed"); return false; }
    foreach ($templates as $tName) {
        $t = $modx->getObject(MODX\Revolution\modTemplate::class, ['templatename' => $tName]);
        if (!$t) { $modx->log(xPDO\xPDO::LOG_LEVEL_ERROR, "pustak: template $tName was not installed"); return false; }
        $key = ['tmplvarid' => $tv->get('id'), 'templateid' => $t->get('id')];
        if (!$modx->getObject(MODX\Revolution\modTemplateVarTemplate::class, $key)) {
            $row = $modx->newObject(MODX\Revolution\modTemplateVarTemplate::class);
            $row->fromArray($key + ['rank' => 0], '', true, true);
            $row->save();
        }
    }
}
return true;
PHP
);
$vehicle->resolve('php', ['source' => $resolver]);
$builder->putVehicle($vehicle);

$attributes = [];
if (is_file("$source/docs/content-vocabulary.md")) $attributes['readme'] = file_get_contents("$source/docs/content-vocabulary.md");
if (is_file("$source/LICENSE")) $attributes['license'] = file_get_contents("$source/LICENSE");
$builder->setPackageAttributes($attributes);

$ok = $builder->pack();
exec('rm -rf ' . escapeshellarg($stage));
if (!$ok) $fail('xPDO could not pack the package', 1);
$zip = $out . PKG . "-$version.transport.zip";
if (!is_file($zip)) $fail("packed, but $zip is not there", 1);
printf("build-transport: %s (%d chunks, %d snippets, %d plugins, %d templates, %d template variables, lib %d files)%s\n", $zip,
    $counts['chunks'], $counts['snippets'], $counts['plugins'], $counts['templates'], $counts['tvs'],
    count(glob("$source/common/lib/*")), isset($attributes['license']) ? '' : ' — NO LICENSE in the source tree');
