<?php
/**
 * verify-transport.php — after the pustak package is installed, is what MODx holds what the package
 * was built from?
 *
 *     MODX_ROOT=<docroot it was installed on> php tools/verify-transport.php --source DIR
 *
 * --source is the tree build-transport.php built from. For every chunk, snippet and plugin there the
 * installed element of that name must exist with exactly the file's bytes, every plugin must be bound
 * to exactly its .events, every template must hold its file's bytes and carry exactly the template
 * variables common/tvs.json binds to it, every common/lib file must be at core/components/pustak/lib/ byte for
 * byte, and every chunk must render through MODx's own parser without leaving a tag behind.
 * tests/content-vocabulary.py --tree-only, run on the same tree, proves every word marked `ours` is
 * one of those files; the two together are "the vocabulary is installed".
 *
 * Exit 0 pass, 1 fail, 2 refused.
 */

use MODX\Revolution\modChunk;
use MODX\Revolution\modPlugin;
use MODX\Revolution\modPluginEvent;
use MODX\Revolution\modResource;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modTemplateVarTemplate;
use MODX\Revolution\modX;

$opt = getopt('', ['source:']);
$source = rtrim($opt['source'] ?? '', '/');
$root = rtrim(getenv('MODX_ROOT') ?: '', '/');
if (!$source || !is_dir("$source/common")) { fwrite(STDERR, "verify-transport: --source DIR holds no common/\n"); exit(2); }
if (!$root || !is_file("$root/config.core.php")) { fwrite(STDERR, "verify-transport: MODX_ROOT ('$root') is not a MODx docroot\n"); exit(2); }
require "$root/config.core.php";
require MODX_CORE_PATH . 'vendor/autoload.php';
$modx = new modX();
$modx->initialize('web');

$passed = $failed = 0;
$check = function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed) {
    if ($ok) { $passed++; return; }
    $failed++;
    echo "  FAIL  $label\n" . ($detail ? "        $detail\n" : '');
};

// THE DATABASE FORM (canon.py db_body): LF line endings, no trailing newline — what the element
// deploy and setup/ write, so an element installed by this package and one deployed by them are the
// same bytes, and the protection verifier finds no one-byte drift.
$dbBody = function (string $b): string {
    $b = str_replace(["\r\n", "\r"], "\n", $b);
    return str_ends_with($b, "\n") ? substr($b, 0, -1) : $b;
};
$elementName = fn(string $f) => preg_replace('/\.(html|php)$/', '', basename($f));   // import.py's rule
foreach ([['chunks', modChunk::class], ['snippets', modSnippet::class]] as [$dir, $class]) {
    foreach (array_filter(glob("$source/common/$dir/*"), 'is_file') as $f) {
        $name = $elementName($f);
        $o = $modx->getObject($class, ['name' => $name]);
        $check($o !== null, "$dir/$name is installed");
        if ($o) $check($o->get('snippet') === $dbBody(file_get_contents($f)), "$dir/$name body is the file's bytes (database form)");
    }
}
foreach (glob("$source/common/plugins/*") as $f) {
    if (str_ends_with($f, '.events')) continue;
    $name = basename($f);
    $o = $modx->getObject(modPlugin::class, ['name' => $name]);
    $check($o !== null, "plugin $name is installed");
    if (!$o) continue;
    $check($o->get('plugincode') === $dbBody(file_get_contents($f)), "plugin $name body is the file's bytes (database form)");
    $want = array_values(array_filter(array_map('trim', file("$f.events"))));
    $have = array_map(fn($e) => $e->get('event'), $modx->getCollection(modPluginEvent::class, ['pluginid' => $o->get('id')]));
    sort($want); sort($have);
    $check($want === $have, "plugin $name is bound to its .events", 'want ' . implode(',', $want) . ' have ' . implode(',', $have));
}
$decl = is_file("$source/common/tvs.json") ? (json_decode(file_get_contents("$source/common/tvs.json"), true) ?: []) : [];
foreach (array_filter(glob("$source/common/templates/*"), 'is_file') as $f) {
    $name = $elementName($f);
    $o = $modx->getObject(modTemplate::class, ['templatename' => $name]);
    $check($o !== null, "template $name is installed");
    if (!$o) continue;
    $check($o->get('content') === $dbBody(file_get_contents($f)), "template $name body is the file's bytes (database form)");
    $want = [];
    foreach ($decl as $tv => $d) { if ($tv[0] !== '$' && in_array($name, $d['templates'] ?? [], true)) $want[] = $tv; }
    $have = [];
    foreach ($modx->getCollection(modTemplateVarTemplate::class, ['templateid' => $o->get('id')]) as $row) {
        $v = $modx->getObject(modTemplateVar::class, $row->get('tmplvarid'));
        if ($v) $have[] = $v->get('name');
    }
    sort($want); sort($have);
    $check($want === $have, "template $name carries its template variables", 'want ' . implode(',', $want) . ' have ' . implode(',', $have));
}
foreach ($decl as $tv => $d) {
    if ($tv[0] === '$') continue;
    $o = $modx->getObject(modTemplateVar::class, ['name' => $tv]);
    $check($o !== null && $o->get('type') === ($d['type'] ?? 'text'), "template variable $tv is installed as {$d['type']}");
}
foreach (glob("$source/common/lib/*") as $f) {
    $at = MODX_CORE_PATH . 'components/pustak/lib/' . basename($f);
    $check(is_file($at) && file_get_contents($at) === file_get_contents($f), 'lib/' . basename($f) . ' is at core/components/pustak/lib/');
}
// Each chunk through MODx's own parser: a chunk or snippet the package does not carry stays a tag.
// Placeholders ([[+id]]) and fields ([[*pagetitle]]) stay too, rightly — no resource is being
// rendered and no caller passed them — so only element tags are counted.
$modx->getParser();
// Snippets the chunks call (SimpleSearchForm among them) read the current resource; render as the
// site's start page, which every install has.
$modx->resource = $modx->getObject(modResource::class, (int) $modx->getOption('site_start', null, 1));
$modx->resourceIdentifier = $modx->resource ? $modx->resource->get('id') : null;
foreach (array_filter(glob("$source/common/chunks/*"), 'is_file') as $f) {
    $name = $elementName($f);
    $out = "[[\$$name]]";
    $modx->parser->processElementTags('', $out, true, false, '[[', ']]', [], 10);
    preg_match_all('/\[\[!?(\$?)([A-Za-z][A-Za-z0-9_.-]*)/', $out, $m);
    $left = array_unique($m[2]);
    $check(!$left, "chunk $name renders every chunk and snippet it calls", 'left as tags: ' . implode(', ', $left));
}
echo "verify-transport: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
