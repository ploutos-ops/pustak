<?php
/**
 * Diagnostics — what THIS application can say about its own error log, to a caller at its door.
 *
 * WHY IT EXISTS. skunkworks owns every element in this repository and has no way to read the
 * ground any of them runs on: no shell there by standing rule, no MODx on a dev box, and `modx` is
 * not among the services the estate's broker declares. So every diagnosis of a MODx fault has
 * ended in another agent or the owner looking at a screen for me. On 2026-08-18 that cost a full
 * session on one blank PDF: the plugin, the blob, the scope and the door were all proven working
 * and the ONE line that says which branch the ground took was unreadable.
 *
 * WHAT IT IS NOT, and this is the whole design. It is not "tail the error log". MODx's error log
 * carries everything MODx logs — failed SQL with its parameters, session and path detail — and
 * this application answers the public internet. A tail endpoint would be phpinfo with extra steps
 * on the wrong host, which is exactly what `actuatorInfo`'s docblock forbids becoming.
 *
 * SO IT RETURNS ONLY LINES THIS ESTATE'S OWN ELEMENTS WROTE. The caller hands a list of allowed
 * markers (`[deliverStorageFile]`, `[protectOnSave]`, …) and a line reaches the response only if
 * it carries one. The bound is therefore not "we redact what looks sensitive" — a mask fails open
 * and looks identical when it does — it is "nothing we did not author can appear at all". Our own
 * lines are written to carry reasons and never values, which is a property of the elements and is
 * asserted in tests/failures-name-themselves.sh rather than believed here.
 *
 * IT READS FROM THE END, BOUNDED. A production error log is unbounded in size and a naive
 * file_get_contents on one is an outage rather than a diagnostic. The read takes at most
 * $maxBytes from the tail, and the first (partial) line of that window is discarded because half a
 * line is not a line.
 *
 * ABSENT IS AN ANSWER, NOT AN ERROR. A missing or unreadable log file is reported as exists=false
 * with the reason, because "I could not look" and "there is nothing there" are different facts and
 * this estate has paid for confusing them.
 */
declare(strict_types=1);

namespace Pustak\Modx;

final class Diagnostics
{
    /**
     * THE CONTRACT THIS COPY IMPLEMENTS. Bumped whenever the SHAPE of what ownLines() returns
     * changes, never for an internal edit.
     *
     * It exists because this file and its only consumer ship by DIFFERENT mechanisms — the file
     * with infra's modx-web ansible role, the consumer as a database column skunkworks' own verb
     * writes — so either can be newer than the other on any ground at any moment. On 2026-08-18
     * the consumer was deployed with a new field set and the grounds still carried the previous
     * library, so the endpoint answered in the OLD shape while reporting that it had used the
     * library: a stale answer that looked current, which is the failure this estate pays for most.
     * The consumer now checks this number and falls back to its own copy when the ground is behind,
     * so being behind is visible instead of silent.
     */
    public const CONTRACT = 2;

    /** A window big enough to hold a real incident, small enough that reading it is free. */
    public const DEFAULT_MAX_BYTES = 262144;   // 256 KiB
    public const DEFAULT_MAX_LINES = 200;

    /**
     * The MODx version, from TWO independent witnesses, because they can disagree.
     *
     * `settings_version` is a row in modx_system_settings; the version file under core/docs is
     * what the distribution shipped. An upgrade that replaced the files and did not finish its
     * settings step leaves those two saying different things — the 3.2.3 upgrade in this estate
     * exited 0 while logging a failure, so this is a shape that has actually happened here. One
     * witness would have reported whichever half was wrong with total confidence.
     *
     * @param callable(string):?string $setting  reads one MODx system setting by key.
     * @param array<string,mixed>|null $versionData  $modx->getVersionData(), or null if unavailable.
     * @return array{settings_version: ?string, file_version: ?string, agree: ?bool}
     */
    public static function version(callable $setting, ?array $versionData): array
    {
        $fromSettings = $setting('settings_version');
        $fromSettings = is_string($fromSettings) && trim($fromSettings) !== '' ? trim($fromSettings) : null;

        $fromFile = null;
        if (is_array($versionData)) {
            // MODX 3 hands back full_version; fall back to assembling it, and never invent one.
            $full = $versionData['full_version'] ?? null;
            if (is_string($full) && trim($full) !== '') {
                $fromFile = trim($full);
            } else {
                $parts = [];
                foreach (['version', 'major_version', 'minor_version', 'patch_level'] as $k) {
                    if (isset($versionData[$k]) && is_scalar($versionData[$k])) { $parts[] = (string) $versionData[$k]; }
                }
                $fromFile = $parts === [] ? null : implode('.', $parts);
            }
        }

        return [
            'settings_version' => $fromSettings,
            'file_version'     => $fromFile,
            // null, not false, when one of them is unknown: "they disagree" is a finding and
            // "I could not compare" is not the same finding.
            'agree'            => ($fromSettings === null || $fromFile === null)
                                    ? null
                                    : ($fromSettings === $fromFile),
        ];
    }

    /**
     * Lines this estate's own elements wrote, newest last, out of the tail of a log file.
     *
     * @param string   $path      the MODx error log.
     * @param string[] $markers   substrings identifying OUR lines. EMPTY MEANS NOTHING MATCHES —
     *                            never "match everything". A caller that forgets to pass its
     *                            markers gets an empty result, not the whole log.
     * @return array{path:string, exists:bool, reason:?string, size:?int, mtime:?int,
     *               window_bytes:int, scanned:int, matched:int, truncated:bool, lines:string[]}
     */
    public static function ownLines(
        string $path,
        array $markers,
        int $maxLines = self::DEFAULT_MAX_LINES,
        int $maxBytes = self::DEFAULT_MAX_BYTES
    ): array {
        $out = [
            'path' => $path, 'exists' => false, 'reason' => null, 'size' => null, 'mtime' => null,
            'dir' => null, 'dir_exists' => null, 'dir_writable' => null,
            'window_bytes' => 0, 'scanned' => 0, 'matched' => 0, 'truncated' => false, 'lines' => [],
        ];

        // THE DIRECTORY IS A SEPARATE FACT FROM THE FILE, and conflating them is the exact
        // ambiguity this class exists to avoid. MODx creates core/cache/logs/error.log on its
        // first write; a missing FILE with a present DIRECTORY means nothing has been logged,
        // while a missing DIRECTORY means nothing CAN be — and `rm -rf core/cache/*` takes the
        // log directory with it, which has happened in this estate before. One reads as "all
        // quiet" and the other as "we are deaf", and they must never print the same sentence.
        $dir = dirname($path);
        $out['dir'] = $dir;
        $out['dir_exists'] = is_dir($dir);
        $out['dir_writable'] = $out['dir_exists'] ? is_writable($dir) : null;

        if ($path === '' || !is_file($path)) {
            $out['reason'] = $out['dir_exists']
                ? ($out['dir_writable']
                    ? 'the log directory exists and is writable, and no log file has been written yet'
                    : 'the log directory exists but this process cannot write to it, so nothing can be logged')
                : 'THE LOG DIRECTORY ITSELF IS ABSENT — MODx has nowhere to write, so errors are '
                  . 'being lost silently rather than none occurring. `rm -rf core/cache/*` removes '
                  . 'it; it must be recreated on the ground.';
            return $out;
        }
        if (!is_readable($path)) {
            // The likeliest real cause, named rather than left to be guessed at: the log is owned
            // by the user that writes it and this process is a different one.
            $out['reason'] = 'file is present but not readable by this process';
            return $out;
        }

        $size = filesize($path);
        $out['exists'] = true;
        $out['size']   = $size === false ? null : $size;
        $mt = filemtime($path);
        $out['mtime'] = $mt === false ? null : $mt;

        // NOTHING MATCHES AN EMPTY MARKER LIST. Stated before the read so the read does not happen.
        if ($markers === []) {
            $out['reason'] = 'no markers supplied, so nothing is authored by us and nothing is returned';
            return $out;
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            $out['reason'] = 'could not open for reading';
            return $out;
        }

        $maxBytes = max(1024, $maxBytes);
        $start    = ($size !== false && $size > $maxBytes) ? $size - $maxBytes : 0;
        if ($start > 0) {
            fseek($fh, $start);
            fgets($fh);                       // discard the partial first line — half a line is not a line
            $out['truncated'] = true;
        }

        $kept = [];
        while (($line = fgets($fh)) !== false) {
            $out['window_bytes'] += strlen($line);
            $out['scanned']++;
            $line = rtrim($line, "\r\n");
            foreach ($markers as $m) {
                if ($m !== '' && strpos($line, $m) !== false) {
                    $kept[] = $line;
                    break;
                }
            }
        }
        fclose($fh);

        $out['matched'] = count($kept);
        if (count($kept) > $maxLines) {
            $kept = array_slice($kept, -$maxLines);   // newest, which is what a diagnosis wants
            $out['truncated'] = true;
        }
        $out['lines'] = array_values($kept);
        return $out;
    }
}
