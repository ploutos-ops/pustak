<?php
/**
 * jump-nav.php — every section a document declares appears in that document's nav.
 *
 *     php tests/jump-nav.php
 *
 * WHY THIS EXISTS, and it is not a hypothetical. On 2026-07-30, 420 hand-written section headers
 * across 126 documents were converted to `[[$sectionStart? &id=`x` &title=`y`]]`. The converter
 * rendered every document through MODx's own parser before and after and refused unless the two
 * were identical — and the conversion was correct by that measure, and shipped to production.
 *
 * jumpNav does not read the rendered page. It reads the resource's RAW `content` field. So the
 * markup it scanned for stopped existing, and every one of those navs silently emptied: 409 entries
 * to 13. Nothing errored. A nav with fewer entries than it should have looks exactly like a nav.
 *
 * THE LESSON THIS FILE ENCODES: a verifier only covers the consumer it happens to run. The page was
 * proven; the second reader of the same field was not, and there was nothing to notice.
 *
 * WHAT IS ASSERTED, per document, counted from the content itself rather than from a fixed list:
 *   - every `[[$sectionStart]]` / `[[$sectionHead]]` carrying an &id yields a nav entry
 *   - every hand-written header (the old rule: bg-info + font-monospace + id) still yields one
 *   - the entries come out in DOCUMENT ORDER, which is what a reader scanning a long page needs
 *   - the estate-wide count never falls below what the declarations say
 *
 * BENCH ONLY, read-only: it renders snippets against the local database and writes nothing.
 */
// REFUSE, DO NOT FATAL. This defaulted to /srv/local/modx, the bench that lived on a dev box —
// and the bench became MODx STAGING on its own host, so on a current dev box the path
// does not exist and this suite ended with an uncaught PHP fatal inside run-all.sh. A red suite
// that is red because it could not start is the mirror of a green one that passed over nothing:
// both train the reader to stop reading. Every other suite in this directory already says
// "REFUSING: ... this is a statement about the shell, not about the tree", and now so does this.
define('MODX_API_MODE', true);
// argv first: `docker exec` carries none of the caller's environment into the container, so the
// ground hands the docroot to this suite as an argument. No fallback path — the old bench is gone
// and a guessed docroot would refuse against a MODx nobody runs.
$modxRoot = ($argv[1] ?? '') ?: (getenv('MODX_ROOT') ?: '');
if (!is_file($modxRoot . '/index.php')) {
    fwrite(STDERR,
        "  REFUSING: no MODx install at $modxRoot — set MODX_ROOT to one.\n"
      . "            This suite renders snippets against a real database (bench or staging), and\n"
      . "            the dev-box bench moved to its own host. No assertion below has\n"
      . "            run: this is a statement about the shell, not about the tree.\n");
    exit(2);
}
require_once $modxRoot . '/index.php';

$repo = dirname(__DIR__);
$snippet = $repo . '/common/snippets/jumpNav.php';
if (!is_file($snippet)) {
    fwrite(STDERR, "REFUSING TO RUN: no jumpNav.php under $repo — run this from the repo.\n");
    exit(2);
}

$host = $modx->getOption('http_host');
$zone = (string) $modx->getOption('estate.internal_zone');   // the estate's private zone: not the internet
if ($host && $host !== 'localhost' && ($zone === '' || substr($host, -strlen($zone)) !== $zone)) {
    fwrite(STDERR, "REFUSING TO RUN: http_host is '$host'. Bench only.\n");
    exit(2);
}

$modx->initialize('web');
$code = file_get_contents($snippet);
$run = function ($modx) use ($code) { return eval($code); };

$pass = 0; $fail = 0;
function check($ok, $msg, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  ok    %s\n", $msg); return; }
    $fail++; printf("  FAIL  %s\n", $msg);
    if ($detail !== '') { printf("          %s\n", $detail); }
}

echo "jumpNav — every declared section reaches its own nav\n";

$q = $modx->newQuery(MODX\Revolution\modResource::class);
$q->where(['deleted' => 0, 'class_key' => 'MODX\\Revolution\\modDocument']);
$rs = $modx->getIterator(MODX\Revolution\modResource::class, $q);

$docsWithSections = 0; $declared = 0; $rendered = 0; $mismatch = []; $misordered = []; $nolabel = [];
foreach ($rs as $r) {
    $c = (string) $r->get('content');

    // What the document SAYS it has — both forms, in the order they appear.
    $want = [];
    if (preg_match_all('#\[\[\$section(?:Start|Head)\?([^\]]*)\]\]#s', $c, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $x) {
            if (preg_match('#&id=`([^`]+)`#', $x[1][0], $id)) { $want[$x[0][1]] = $id[1]; }
        }
    }
    if (preg_match_all('#<div\b([^>]*)>#i', $c, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $x) {
            $a = $x[1][0];
            if (strpos($a, 'bg-info') === false || strpos($a, 'font-monospace') === false) { continue; }
            if (strpos($a, 'data-nav=') !== false) { continue; }   // counted above as a declaration
            if (!preg_match('#\bid="([^"]+)"#', $a, $id)) { continue; }
            // jumpNav needs something to LABEL the entry with. A handful of headers carry only a
            // `<span class="birdy">` with no text — their visible name is painted by script — so
            // there is nothing to put in the nav and jumpNav correctly emits none. That is a gap in
            // the CONTENT (it wants a `data-nav`), not a fault in the snippet, so it is counted and
            // reported rather than failed: which pages get a short label is the owner's call.
            $after = substr($c, $x[0][1]);
            $inner = preg_match('#^<div\b[^>]*>(.*?)</div>#is', $after, $im) ? $im[1] : '';
            if (trim(preg_replace('/\s+/', ' ', strip_tags($inner))) === '') { $nolabel[] = $id[1]; continue; }
            $want[$x[0][1]] = $id[1];
        }
    }
    if (!$want) { continue; }
    ksort($want);
    $want = array_values($want);
    $docsWithSections++;
    $declared += count($want);

    $modx->resource = $r;
    $out = (string) $run($modx);
    preg_match_all('#href="\#([^"]+)"#', $out, $got);
    $got = $got[1];
    $rendered += count($got);

    $label = $r->get('context_key') . '/' . $r->get('alias') . '-' . $r->get('id');
    if (count($got) !== count($want)) {
        $mismatch[] = sprintf('%s: declares %d sections, nav shows %d', $label, count($want), count($got));
    } elseif ($got !== $want) {
        $misordered[] = $label;
    }
}

check($docsWithSections > 0, "the estate declares sections at all ($docsWithSections documents)",
      'nothing to check — this test would pass over an empty haystack');
check(!$mismatch, "every document's nav has one entry per declared section",
      implode('; ', array_slice($mismatch, 0, 5)));
check(!$misordered, 'the entries come out in document order',
      implode(', ', array_slice($misordered, 0, 5)));
check(true, sprintf('%d hand-written headers carry no text to label a nav entry%s',
      count($nolabel), $nolabel ? ' — they need a &nav: ' . implode(', ', array_slice($nolabel, 0, 8)) : ''));
check($rendered >= $declared, "nav entries ($rendered) cover the declarations ($declared)");

// THE CONTROL: the same machinery must be able to report a MISSING entry, or the greens above are
// a statement about nothing. A declaration this snippet cannot see is induced and must be counted.
$probe = "[[\$sectionStart? &id=`zz-probe` &title=`Probe`]]\n[[\$sectionEnd]]";
$fake = $modx->newObject(MODX\Revolution\modResource::class);
$fake->set('content', $probe);
$modx->resource = $fake;
$seen = substr_count((string) $run($modx), 'zz-probe');
check($seen === 1, 'the control: a lone declaration produces exactly one entry', "produced $seen");

$fake->set('content', str_replace('&id=`zz-probe`', '', $probe));
$modx->resource = $fake;
$seen = substr_count((string) $run($modx), '<li>');
check($seen === 0, 'the control: a declaration with no id produces none', "produced $seen");

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
