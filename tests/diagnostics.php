<?php
/**
 * diagnostics — the log reader returns ONLY lines this estate's elements wrote, and the version
 * report keeps its two witnesses apart.
 *
 * THE ASSERTION THAT MATTERS is the negative one: a line MODx wrote, in the same file, adjacent to
 * ours, must not appear in the output. The fixture therefore interleaves a failed SQL statement
 * carrying a value with our own lines — if the filter ever inverts, that row appears and this
 * suite goes red. A suite that only checked "our lines come back" would pass over an endpoint that
 * returned the whole log.
 *
 *   php tests/diagnostics.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../common/lib/Diagnostics.php';

use Pustak\Modx\Diagnostics;

$pass = 0; $fail = 0;
function check(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; if ($detail !== '') echo "        $detail\n"; }
}

$MARKERS = ['[deliverStorageFile]', '[protectOnSave]', '[namesPlugin]'];

// A fixture shaped like the real thing: MODx's own lines around ours, including one that carries
// a value, which is the row that must never come back.
$tmp = tempnam(sys_get_temp_dir(), 'modxlog');
file_put_contents($tmp, implode("\n", [
    '[2026-08-18 09:00:00] (ERROR @ /index.php) [deliverStorageFile] storage: this caller can prove nothing to the door',
    '[2026-08-18 09:00:01] (ERROR @ /index.php) Error 42000 executing statement: SELECT * FROM x WHERE token = \'s3cr3t-value\'',
    '[2026-08-18 09:00:02] (ERROR @ /index.php) [protectOnSave] resource 140 protected',
    '[2026-08-18 09:00:03] (WARN  @ /index.php) PHP deprecated: strlen(): passing null',
    '[2026-08-18 09:00:04] (ERROR @ /index.php) [deliverStorageFile] sha1 7781b4de not resolvable in modx/production (http=404)',
]) . "\n");

echo "log reader:\n";
$r = Diagnostics::ownLines($tmp, $MARKERS);
check($r['exists'] === true, 'an existing log reads');
check($r['matched'] === 3, 'exactly our three lines matched', 'matched=' . $r['matched']);
check($r['scanned'] === 5, 'all five lines were scanned', 'scanned=' . $r['scanned']);

$joined = implode("\n", $r['lines']);
check(strpos($joined, 's3cr3t-value') === false, 'THE SQL LINE WITH A VALUE IS NOT RETURNED');
check(strpos($joined, 'PHP deprecated') === false, "MODx's own deprecation line is not returned");
check(strpos($joined, 'not resolvable in modx/production') !== false, 'our newest line IS returned');
check(str_ends_with($joined, 'http=404)'), 'newest line is last');

// An empty marker list must return NOTHING, never everything. This is the inversion that would
// turn a scoped diagnostic into a log dump, so it is asserted rather than trusted to the reading.
$none = Diagnostics::ownLines($tmp, []);
check($none['lines'] === [] && $none['matched'] === 0, 'NO MARKERS RETURNS NOTHING, not everything');
check(is_string($none['reason']), 'and it says why', var_export($none['reason'], true));

// Absent and unreadable are answers with reasons, not exceptions — AND a missing FILE must never
// read the same as a missing DIRECTORY. One means nothing has been logged; the other means nothing
// CAN be, because `rm -rf core/cache/*` took the log directory with it. Both grounds answered
// "no such file" on 2026-08-18 and that sentence could not tell the two apart, which is the whole
// reason this distinction exists.
$gone = Diagnostics::ownLines($tmp . '.nope', $MARKERS);     // dir exists, file does not
check($gone['exists'] === false && $gone['dir_exists'] === true, 'a missing FILE reports its directory present');
check(strpos($gone['reason'], 'no log file has been written yet') !== false,
      'and says nothing has been logged yet', $gone['reason']);

$nodir = Diagnostics::ownLines('/nonexistent-dir-' . getmypid() . '/logs/error.log', $MARKERS);
check($nodir['dir_exists'] === false, 'a missing DIRECTORY is reported as such');
check(strpos($nodir['reason'], 'LOG DIRECTORY ITSELF IS ABSENT') !== false,
      'AND SAYS ERRORS ARE BEING LOST, not that none occurred', $nodir['reason']);
check($gone['reason'] !== $nodir['reason'], 'the two cases do NOT print the same sentence');

// The tail window: a log far larger than the window returns the END of it, and says it truncated.
$big = tempnam(sys_get_temp_dir(), 'modxbig');
$fh = fopen($big, 'wb');
for ($i = 0; $i < 20000; $i++) {
    fwrite($fh, "[2026-08-18] (ERROR @ /index.php) filler line $i padded padded padded padded padded\n");
}
fwrite($fh, "[2026-08-18] (ERROR @ /index.php) [deliverStorageFile] the last line\n");
fclose($fh);
$t = Diagnostics::ownLines($big, $MARKERS);
check($t['truncated'] === true, 'a log bigger than the window reports truncated');
check($t['window_bytes'] <= Diagnostics::DEFAULT_MAX_BYTES, 'the window is bounded', 'read=' . $t['window_bytes']);
check($t['lines'] === ['[2026-08-18] (ERROR @ /index.php) [deliverStorageFile] the last line'], 'the END of a big log is what comes back');

// maxLines keeps the newest, because a diagnosis reads backwards from the fault.
$many = tempnam(sys_get_temp_dir(), 'modxmany');
$lines = [];
for ($i = 0; $i < 50; $i++) { $lines[] = "[deliverStorageFile] line $i"; }
file_put_contents($many, implode("\n", $lines) . "\n");
$m = Diagnostics::ownLines($many, $MARKERS, 5);
check(count($m['lines']) === 5 && $m['lines'][4] === '[deliverStorageFile] line 49', 'maxLines keeps the NEWEST');
check($m['matched'] === 50, 'and still reports how many actually matched', 'matched=' . $m['matched']);

echo "version:\n";
$agree = Diagnostics::version(fn($k) => $k === 'settings_version' ? '3.2.3-pl' : null, ['full_version' => '3.2.3-pl']);
check($agree['agree'] === true, 'two witnesses agreeing report agree=true');

$disagree = Diagnostics::version(fn($k) => $k === 'settings_version' ? '3.2.2-pl' : null, ['full_version' => '3.2.3-pl']);
check($disagree['agree'] === false, 'A DISAGREEMENT IS VISIBLE, not smoothed away');
check($disagree['settings_version'] === '3.2.2-pl' && $disagree['file_version'] === '3.2.3-pl', 'and both values are named');

$unknown = Diagnostics::version(fn($k) => null, null);
check($unknown['agree'] === null, 'unknown is null, NOT false — "could not compare" is not "they differ"');

$assembled = Diagnostics::version(fn($k) => null, ['version' => '3', 'major_version' => '2', 'minor_version' => '3']);
check($assembled['file_version'] === '3.2.3', 'a versionData without full_version is assembled, never invented');

// ── THE INLINE FALLBACK IN THE SNIPPET MUST BEHAVE IDENTICALLY ────────────────────────────────
// actuatorInfo carries its own copy of ownLines() as a closure, because the library ships by
// infra's ansible role while the snippet ships by skunkworks' verb — so on a ground that has one
// and not the other, the agent that owns MODx must still be able to read the log. Two copies of
// logic is normally the wrong answer; what makes it acceptable is THIS: the closure is extracted
// from the snippet and run against the SAME fixtures as the class, and any divergence goes red.
// Without this block the second copy would drift the first time either was touched.
echo "inline fallback in actuatorInfo:\n";
$snippet = file_get_contents(__DIR__ . '/../common/snippets/actuatorInfo.php');
$begin = strpos($snippet, '// --- BEGIN inline diagnostics fallback');
$end   = strpos($snippet, '// --- END inline diagnostics fallback');
check($begin !== false && $end !== false && $end > $begin,
      'the snippet still carries the marked fallback block');

if ($begin !== false && $end !== false && $end > $begin) {
    $block = substr($snippet, $begin, $end - $begin);
    $eq    = strpos($block, '$inlineOwnLines');
    $inline = null;
    // eval the closure assignment alone — no MODX, no $modx, nothing else from the snippet.
    eval(substr($block, strpos($block, '$inlineOwnLines =')));
    check(is_callable($inlineOwnLines ?? null), 'the fallback closure is callable in isolation');

    // Re-run every fixture in this file through BOTH implementations and compare whole results.
    $fixtures = [
        ['label' => 'the real-shaped log',      'path' => $tmp2 = tempnam(sys_get_temp_dir(), 'mx'), 'markers' => $MARKERS, 'max' => 200],
        ['label' => 'no markers',               'path' => $tmp2,  'markers' => [],       'max' => 200],
        ['label' => 'a missing file',           'path' => $tmp2 . '.gone', 'markers' => $MARKERS, 'max' => 200],
        ['label' => 'maxLines keeps the newest','path' => $tmp2,  'markers' => $MARKERS, 'max' => 2],
    ];
    file_put_contents($tmp2, implode("\n", [
        '[2026-08-18 09:00:00] (ERROR) [deliverStorageFile] storage: this caller can prove nothing',
        '[2026-08-18 09:00:01] (ERROR) Error 42000 executing statement: token = \'s3cr3t-value\'',
        '[2026-08-18 09:00:02] (ERROR) [protectOnSave] resource 140 protected',
        '[2026-08-18 09:00:04] (ERROR) [deliverStorageFile] sha1 7781b4de not resolvable (http=404)',
    ]) . "\n");

    foreach ($fixtures as $f) {
        $a = Diagnostics::ownLines($f['path'], $f['markers'], $f['max']);
        $b = $inlineOwnLines($f['path'], $f['markers'], $f['max']);
        unset($a['mtime'], $b['mtime']);   // the only field that is a clock
        check($a === $b, 'class and inline agree — ' . $f['label'],
              "class=" . json_encode($a) . " inline=" . json_encode($b));
    }
    @unlink($tmp2);
}

@unlink($tmp); @unlink($big); @unlink($many);

echo "\n$pass right, $fail wrong\n";
exit($fail === 0 ? 0 : 1);
