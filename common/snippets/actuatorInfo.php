/**
 * actuatorInfo — `/actuator/info` for MODx. The estate's fifth and last one.
 *
 * WHY A SNIPPET AND NOT A DOCROOT FILE. I spent an hour looking for a way to place a PHP file in
 * MODx's docroot and reported a blocker to infra, because nothing in any deploy verb copies a
 * repository file there. The owner answered it in one line — the endpoint can be a page in a context
 * skunkworks already owns — and he was right: a snippet ships through `deploy_modx_elements` and a
 * resource through `deploy_modx_documents`, both of which already exist and I use every session.
 * **The estate had two working paths for this and I went looking for a third.**
 *
 * (His sentence named the host. It is not quoted here: an estate address in the software
 * repository is exactly what the estate-clean work exists to remove, and `tests/estate-clean.sh` caught this
 * docblock — correctly — on the first run after it was written. Which context serves it is an
 * estate fact and lives in the estate file, not in a comment.)
 *
 * MY OWN OBJECTION, AND WHY IT DOES NOT HOLD. I had rejected a resource on the grounds that a new
 * page is anonymously reachable until `setup/04` runs. That is true and it is irrelevant
 * here: **MODx's access model is not the control on this page — the badge is.** `setup/04` decides
 * who may fetch the URL; this snippet decides who gets an answer, and it refuses everyone without
 * `monitoring`'s badge whether the resource is protected or not. That exposure is about pages whose ONLY
 * protection is RBAC.
 *
 * WHAT THIS SNIPPET IS RESPONSIBLE FOR
 *   - the door: X-Application-Badge, checked against the `accept` map infra renders into
 *     core/config/application.php. FAILS CLOSED — no map, no answer, for anybody.
 *   - the answer: Actuator::verdicts() over the handed required-set (core/config/required-set.json,
 *     rendered by infra), values never reported.
 *   - it terminates the request itself. A snippet's return value would be interpolated into a
 *     template, and a JSON diagnostic wrapped in someone's <html> is not a diagnostic.
 *
 * WHAT IT MUST NEVER BECOME. Rule 1 is enforced in Actuator, not here, and this file must not add
 * a field. If a future reader wants "just the value, for debugging", that is phpinfo with extra
 * steps — on a host that answers the public internet.
 *
 * CALL IT UNCACHED. `[[!actuatorInfo]]`. A cached call would freeze one verdict set into the page
 * cache and the endpoint would then report yesterday's answer with today's timestamp — which is
 * the failure this endpoint exists to end. Guarded below rather than documented.
 */

/** @var modX $modx */
/** @var array $scriptProperties */

// Loaded from MODX's own place for shipped PHP, NOT from this repository. A snippet's body
// lives in `modx_site_snippets.snippet` and a plugin's in `modx_site_plugins.plugincode` —
// database columns — so `dirname(__DIR__)` had nothing to resolve against on a real box, and
// `common/lib/` was deployed by nothing (measured with a control 2026-08-04).
// infra now ships these under the estate library directory in core/components, tag `modx_lib`.
$LIB = rtrim(MODX_CORE_PATH, '/') . '/components/pustak/lib/';
require_once $LIB . 'Actuator.php';
require_once $LIB . 'Application.php';

// Diagnostics.php IS NOT REQUIRED THE SAME WAY, and the asymmetry is deliberate — it is a
// deploy-ordering gate, which is the shape that has broken this estate twice.
// The two halves of this file ship by DIFFERENT mechanisms and cannot be released together:
// `common/lib/*` is copied by infra's modx-web role (tag `modx_lib`) and this snippet's body is a
// database column written by apply-modx-elements.sh, which skunkworks runs. So there is always a
// window where one is on the ground and the other is not.
//
// A bare `require_once` on the newer file makes that window FATAL — and it would take the whole
// endpoint down, including the monitor's health check, which was working before I touched it.
// Breaking a working endpoint to add a diagnostic to it is the wrong trade in every direction.
// So: the file is loaded if it is there, its absence is a fact the report carries, and `?report=log`
// answers 503 naming the missing file instead of dying.
// THE LOG READER THIS SNIPPET RUNS IS ITS OWN, ALWAYS. Not "the library if it is current, else
// mine" — that was the first shape and it was wrong twice in one afternoon: the library absent gave
// a 503 that made my own diagnosis wait on another agent's converge, and then the library PRESENT
// but older answered in yesterday's shape while the response called itself current. Both faults
// were the negotiation, not either copy.
//
// One execution path has no stale half to detect. The library stays as the TESTED unit — the class
// is what tests/diagnostics.php exercises, and that suite also extracts the closure below and
// asserts the two agree on every fixture — but nothing on any ground chooses between them at
// runtime. Version negotiation between two deploy mechanisms is a thing to delete, not to get right.
if (is_readable($LIB . 'Diagnostics.php')) {
    require_once $LIB . 'Diagnostics.php';   // other consumers may want it; this file does not use it
}

// --- BEGIN inline diagnostics fallback (generated — see tests/diagnostics.php) -------------------
// WHY A SECOND COPY OF THIS LOGIC EXISTS AT ALL, stated plainly because duplication is normally
// the wrong answer. The library ships by infra's ansible role and this snippet ships by a verb
// skunkworks runs, so the day the library is not yet on a ground, the agent that owns MODx cannot
// read the log of the application it owns — and reading that log is the ONLY way to tell which
// branch deliverStorageFile took. Handing my own diagnosis to another agent's converge is what
// cost five sessions on one blank PDF. This makes the endpoint self-sufficient.
//
// It is NOT a second master: tests/diagnostics.php runs the class AND this closure against the
// SAME fixtures and asserts identical output, so a change to one that is not made to the other
// goes red. A snippet body is eval'd inside a method, so a namespaced class cannot be declared
// here — that is why this is a closure and not a copy of the class.
$inlineOwnLines = static function (string $path, array $markers, int $maxLines = 200, int $maxBytes = 262144): array {
    $out = ['path' => $path, 'exists' => false, 'reason' => null, 'size' => null, 'mtime' => null,
            'dir' => null, 'dir_exists' => null, 'dir_writable' => null,
            'window_bytes' => 0, 'scanned' => 0, 'matched' => 0, 'truncated' => false, 'lines' => []];
    $dir = dirname($path);
    $out['dir'] = $dir;
    $out['dir_exists'] = is_dir($dir);
    $out['dir_writable'] = $out['dir_exists'] ? is_writable($dir) : null;
    if ($path === '' || !is_file($path))  {
        $out['reason'] = $out['dir_exists']
            ? ($out['dir_writable']
                ? 'the log directory exists and is writable, and no log file has been written yet'
                : 'the log directory exists but this process cannot write to it, so nothing can be logged')
            : 'THE LOG DIRECTORY ITSELF IS ABSENT — MODx has nowhere to write, so errors are '
              . 'being lost silently rather than none occurring. `rm -rf core/cache/*` removes '
              . 'it; it must be recreated on the ground.';
        return $out;
    }
    if (!is_readable($path)) { $out['reason'] = 'file is present but not readable by this process'; return $out; }
    $size = filesize($path);
    $out['exists'] = true;
    $out['size']   = $size === false ? null : $size;
    $mt = filemtime($path);
    $out['mtime']  = $mt === false ? null : $mt;
    if ($markers === []) {
        $out['reason'] = 'no markers supplied, so nothing is authored by us and nothing is returned';
        return $out;
    }
    $fh = @fopen($path, 'rb');
    if ($fh === false) { $out['reason'] = 'could not open for reading'; return $out; }
    $maxBytes = max(1024, $maxBytes);
    $start = ($size !== false && $size > $maxBytes) ? $size - $maxBytes : 0;
    if ($start > 0) { fseek($fh, $start); fgets($fh); $out['truncated'] = true; }
    $kept = [];
    while (($line = fgets($fh)) !== false) {
        $out['window_bytes'] += strlen($line);
        $out['scanned']++;
        $line = rtrim($line, "\r\n");
        foreach ($markers as $m) {
            if ($m !== '' && strpos($line, $m) !== false) { $kept[] = $line; break; }
        }
    }
    fclose($fh);
    $out['matched'] = count($kept);
    if (count($kept) > $maxLines) { $kept = array_slice($kept, -$maxLines); $out['truncated'] = true; }
    $out['lines'] = array_values($kept);
    return $out;
};
// --- END inline diagnostics fallback -------------------------------------------------------------

use Pustak\Modx\Actuator;
use Pustak\Modx\Application;
use Pustak\Modx\Diagnostics;

// Only `monitoring` may read this. A SEPARATE list from anything else that reads a badge, so a
// caller admitted at another door does not silently acquire this one — @dev's ACTUATOR_CALLERS is
// not SEAM_CALLERS finding. Today the accept map holds one caller and this list is the same
// one name; the day it does not, this line is what keeps them apart.
$ACTUATOR_CALLERS = ['monitoring'];

// A SECOND LIST, FOR A SECOND QUESTION — `?report=log`, below. Deliberately not the same array, for the
// reason the line above already states in the other direction: `monitoring` polls this endpoint on
// a schedule and has no business reading an error log, and `skunkworks` owns every element here and
// has no business being polled for. Two callers, two questions, no overlap. Widening either list is
// a decision someone has to type, which is the whole point of them being separate.
$DIAGNOSTIC_CALLERS = ['skunkworks'];

// THE ONLY LINES `?report=log` CAN EVER RETURN — the markers OUR OWN elements write. Not a redaction
// list and not a deny-list: a line reaches the caller only if it carries one of these, so the MODx
// error log's own content (failed SQL with its parameters, session and path detail, on a host that
// answers the public internet) cannot appear at all. A mask would fail open and look identical
// while doing it; this fails closed by construction. Adding a marker here is how a new element
// becomes diagnosable, and it is the one edit that widens what this endpoint discloses.
// An install's OWN additions name theirs in the system setting actuator.log_markers (comma-separated,
// e.g. '[myPlugin]'), so this list names only what ships here — and widening it stays one deliberate edit.
$OWN_LOG_MARKERS = array_values(array_unique(array_merge(
    ['[deliverStorageFile]', '[protectOnSave]', '[SSO-'],
    array_filter(array_map('trim', explode(',', (string) $modx->getOption('actuator.log_markers', null, ''))),
                 static fn($m) => preg_match('/^\[[A-Za-z][A-Za-z0-9_-]*\]?$/', $m) === 1 && strlen($m) >= 4))));

// --- BEGIN actuator contract (tested — see tests/actuator-typed.php) -----------------------------
// EVERY ANSWER THIS DOOR GIVES HAS A SHAPE, AND LEAVES THROUGH IT (constitution P14). A door whose
// answer is untyped is undeclared: a consumer's reader is then written against whatever came back
// the day it was written, and a renamed key fails nothing until the monitor goes quiet.
//
// `$CONTRACT` is the schema of each report and of the refusal; `$send` below validates the body
// against it and sends nothing the schema does not admit. So the contract document
// (docs/actuator.openapi.json, rendered from this array by the suite) and the answer cannot differ.
// Every object says `additionalProperties => false`: a key added to an answer and not here is a
// 500 on the bench, never a field a consumer silently learns to depend on.
//
// IN THE SNIPPET, NOT IN common/lib, for the reason the fallback above gives: the library ships by
// infra's role and this body by skunkworks' verb, and a door that needs both released together
// has a window where it is down. The suite extracts this block and runs it, so it is tested code.
$nullable = static fn(string $type): array => ['type' => [$type, 'null']];
$CONTRACT = [
    'error' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['error'],
        'properties' => ['error' => ['type' => 'string'], 'detail' => ['type' => 'string']],
    ],
    'info' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['application', 'ready', 'requirements', 'environment', 'role', 'commit', 'modx_version'],
        'properties' => [
            'application'  => ['type' => 'string'],
            'ready'        => ['type' => 'boolean'],
            'requirements' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'ok', 'detail'],
                'properties' => ['key' => ['type' => 'string'], 'ok' => ['type' => 'boolean'],
                                 'detail' => ['type' => 'string']],
            ]],
            'environment'  => ['type' => 'string'],
            'role'         => ['type' => 'string'],
            'commit'       => $nullable('string'),
            'modx_version' => [
                'type' => ['object', 'null'], 'additionalProperties' => false,
                'required' => ['settings_version', 'file_version', 'agree'],
                'properties' => ['settings_version' => $nullable('string'),
                                 'file_version' => $nullable('string'), 'agree' => $nullable('boolean')],
            ],
        ],
    ],
    'elements' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['environment', 'caller', 'elements'],
        'properties' => [
            'environment' => $nullable('string'),
            'caller'      => ['type' => 'string'],
            'elements'    => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['kind', 'name', 'bytes', 'sha1'],
                'properties' => ['kind' => ['type' => 'string'], 'name' => ['type' => 'string'],
                                 'bytes' => ['type' => 'integer'], 'sha1' => $nullable('string')],
            ]],
        ],
    ],
    'settings' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['environment', 'caller', 'settings', 'note'],
        'properties' => [
            'environment' => $nullable('string'),
            'caller'      => ['type' => 'string'],
            'settings'    => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['storage.base_url', 'storage.environment', 'sso.provider', 'log_target', 'log_level'],
                'properties' => ['storage.base_url' => $nullable('string'), 'storage.environment' => $nullable('string'),
                                 'sso.provider' => $nullable('string'), 'log_target' => $nullable('string'),
                                 'log_level' => $nullable('string')],
            ],
            'note'        => ['type' => 'string'],
        ],
    ],
    'log' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['path', 'exists', 'reason', 'size', 'mtime', 'dir', 'dir_exists', 'dir_writable',
                       'window_bytes', 'scanned', 'matched', 'truncated', 'lines', 'caller', 'markers', 'environment'],
        'properties' => [
            'path' => ['type' => 'string'], 'exists' => ['type' => 'boolean'], 'reason' => $nullable('string'),
            'size' => $nullable('integer'), 'mtime' => $nullable('integer'), 'dir' => $nullable('string'),
            'dir_exists' => $nullable('boolean'), 'dir_writable' => $nullable('boolean'),
            'window_bytes' => ['type' => 'integer'], 'scanned' => ['type' => 'integer'],
            'matched' => ['type' => 'integer'], 'truncated' => ['type' => 'boolean'],
            'lines' => ['type' => 'array', 'items' => ['type' => 'string']],
            'caller' => ['type' => 'string'],
            'markers' => ['type' => 'array', 'items' => ['type' => 'string']],
            'environment' => $nullable('string'),
        ],
    ],
];
// Why `$value` is NOT admitted by `$schema`, or null when it is. The few keywords $CONTRACT uses
// and no others: type, properties, required, additionalProperties false, items.
$admits = static function (array $schema, $value, string $at = '$') use (&$admits): ?string {
    // A PHP array is a JSON array when its keys are 0..n-1, and an object otherwise; the empty
    // one is either. Spelled out rather than array_is_list(), which the ground's PHP may predate.
    $list = is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1));
    $is = [
        'null'    => $value === null,
        'boolean' => is_bool($value),
        'integer' => is_int($value),
        'string'  => is_string($value),
        'array'   => $list,
        'object'  => is_array($value) && ($value === [] || !$list),
    ];
    $types = (array) ($schema['type'] ?? []);
    $as = null;
    foreach ($types as $t) { if ($is[$t] ?? false) { $as = $t; break; } }
    if ($as === null) { return "$at is not " . implode(' or ', $types); }
    if ($as === 'array') {
        foreach ($value as $i => $item) {
            $why = $admits($schema['items'], $item, "{$at}[$i]");
            if ($why !== null) { return $why; }
        }
    }
    if ($as === 'object') {
        foreach (($schema['required'] ?? []) as $k) {
            if (!array_key_exists($k, $value)) { return "$at lacks `$k`"; }
        }
        foreach ($value as $k => $v) {
            if (!isset($schema['properties'][$k])) { return "$at carries `$k`, which the contract does not name"; }
            $why = $admits($schema['properties'][$k], $v, "$at.$k");
            if ($why !== null) { return $why; }
        }
    }
    return null;
};
// --- END actuator contract -----------------------------------------------------------------------

$send = static function (int $status, string $shape, array $body) use ($modx, $CONTRACT, $admits) {
    // Through the schema or not at all. An answer the contract does not admit is OUR fault, so it
    // is a 500 that says which key — and it is itself of the `error` shape.
    $why = isset($CONTRACT[$shape]) ? $admits($CONTRACT[$shape], $body) : "no shape is named `$shape`";
    if ($why !== null) {
        $status = 500;
        $body = ['error' => 'answer outside its contract', 'detail' => "$shape: $why"];
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');          // a cached diagnostic is a lie with a timestamp
    header('X-Robots-Tag: noindex, nofollow');
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    // exit(), not $modx->sendResponse(): that method does not exist in MODX 3 and the @ hid
    // nothing — PHP fatals on an undefined METHOD regardless of the suppression operator, so the
    // door printed its JSON and then a 'Call to undefined method' fatal underneath it (measured on
    // staging, 2026-08-14, the first time this snippet was ever reached). The body is already
    // flushed and the status already set; there is nothing for MODx to do after that.
    exit;
};

// A cached call cannot be made honest, so it is refused rather than served stale. The 500 is
// deliberate: this is a deployment fault in OUR page, not a caller error, and a 200 with a warning
// in it would be read by a poller as success.
//
// `isProcessingUncacheable()` is true only inside a `[[! ]]` call. This is `tableRenderer`'s own
// mechanism, reused rather than re-decided — the first version of this file invented a
// `$scriptProperties['__uncached__']` marker that MODx does not set, which would have been a guard
// that could never fire, in a file whose whole subject is checks that cannot fail.
$parser = $modx->getParser();
if (!$parser || !$parser->isProcessingUncacheable()) {
    $send(500, 'error', [
        'error'  => 'actuatorInfo must be called uncached',
        'detail' => 'The page calls [[actuatorInfo]] instead of [[!actuatorInfo]]. A cached call '
                  . 'freezes one verdict set into the page cache and reports it forever.',
    ]);
}

// THE CERTIFICATE FIRST, THE BADGE ONLY WHILE CALLERS MOVE (the owner's ruling 2026-08-14:
// the badge is deleted in favour of the certificate — one identity mechanism, not two).
//
// nginx sets X-Client-Identity only on a verified chain and overwrites it on every request, so a
// caller cannot type an identity into a request. The badge path stays for the transition window
// only — infra's nginx is `ssl_verify_client optional` for the same reason — and it SAYS when it
// is taken, so 'the last badge caller has moved' becomes something the log can answer instead of
// something somebody believes. Deleting it is its own ask.
// INERT UNTIL THE GROUND VOUCHES FOR THE HEADER. Caught 40 minutes after I wrote the certificate
// path, by reading dev's version of the same change (trump e4b10006), which gates on a
// flag infra renders in the same act as the vhost line — and then checking MODx's own vhost:
// roles/nginx/templates/modx.conf.j2 does NOT set or clear X-Client-Identity, it passes client
// headers straight into fastcgi. So on that ground the header is TYPEABLE, and reading it would
// have turned a shared-secret door into an open one. Nothing was deployed; the defect existed in
// git for the length of one commit.
//
// The rule this encodes: a header is only an identity where something the caller cannot reach
// wrote it. Until application.php carries `client_identity_trusted`, rendered by infra in the same
// act as the nginx line that overwrites the header, this door does not look at it at all.
$identity = Application::config('client_identity_trusted')
    ? ($_SERVER['HTTP_X_CLIENT_IDENTITY'] ?? null)
    : null;

// WHICH QUESTION IS BEING ASKED, decided BEFORE the door so the door can be the right one. An
// unrecognised q is the info report, not an error: this endpoint is polled by a monitor and a
// typo in a query string must not turn a health check into a 400.
// NOT `q`. MODX OWNS THAT NAME: friendly URLs are `index.php?q=<uri>`, so `?q=log` makes core
// look for a resource whose URI is `log` and answer its own 404 before this snippet is reached.
// Measured on the bench 2026-08-18 — the first thing a bench found, and nothing short of running
// it would have. `report` collides with nothing in core's request handling.
$q = isset($_GET['report']) && is_string($_GET['report']) ? strtolower(trim($_GET['report'])) : '';
$wantLog = ($q === 'log');
$wantElements = ($q === 'elements');
$wantSettings = ($q === 'settings');

$caller = Application::acceptedClient(
    is_string($identity) ? $identity : null,
    ($wantLog || $wantElements || $wantSettings) ? $DIAGNOSTIC_CALLERS : $ACTUATOR_CALLERS
);
// THE BADGE FALLBACK IS GONE. It stood for one day, while infra moved the monitor —
// the only caller this door admits — onto a client certificate. Their measurement closed it: with
// --cert monitoring@production and NO badge the door answers 200, certless 401, a typed header is
// cleared at PHP on both grounds, and the modx error log carries zero 'admitted by APPLICATION
// BADGE' lines. A fallback nobody takes is a second way in that nobody is testing, which is the
// whole argument of this ticket: a badge is a shared secret every acceptor holds, so any acceptor
// can impersonate the presenter. There is one mechanism here now.
if ($caller === null) {
    // ONE message for every refusal. "no badge" and "wrong badge" are different faults to us and
    // must be the same answer to a caller: telling an unauthenticated stranger which of the two
    // they hit is telling them whether the badge they guessed exists.
    $send(401, 'error', ['error' => 'Application badge required']);
}

// ── ?report=elements — WHAT CODE IS ACTUALLY DEPLOYED, hashed the way the verb stores it ────────
//
// "know what is running there without logging in to machines" — the owner, 2026-08-18. `commit` on the
// info report names what infra STAMPED; this names what the database actually holds, which is a
// different fact and the one that has been wrong. An element deploy is an upsert into a database
// column and the verb reports success from the UPSERT, not from the row: on 2026-08-18 a plugin
// was deployed twice, the verb printed `updated plugins/deliverStorageFile (id 2)` both times, and
// the ground went on running the old body. Nothing in the estate could see that.
//
// sha1 of the stored body, and the byte length. NEVER the body: an element can carry a key, and a
// diagnostic that prints source on a host answering the public internet is the thing this file's
// docblock forbids. A digest compares against the repo without disclosing anything.
if ($wantElements) {
    $out = ['environment' => Application::config('environment'), 'caller' => $caller, 'elements' => []];
    foreach ([
        ['site_plugins',   'plugincode', 'plugin'],
        ['site_snippets',  'snippet',    'snippet'],
        ['site_chunks',    'snippet',    'chunk'],
        ['site_templates', 'content',    'template'],
    ] as [$table, $col, $kind]) {
        // The table name is built from MODx's OWN prefix, never hardcoded: this estate's prefix is
        // modx_ but the value is a per-install fact and reading it costs nothing.
        $prefix = (string) $modx->getOption('table_prefix', null, 'modx_');
        $sql = "SELECT name, LENGTH($col) AS len, SHA1($col) AS sha1 FROM {$prefix}{$table} ORDER BY name";
        $st = $modx->prepare($sql);
        if ($st && $st->execute()) {
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out['elements'][] = ['kind' => $kind, 'name' => $row['name'],
                                      'bytes' => (int) $row['len'], 'sha1' => $row['sha1']];
            }
        }
    }
    $send(200, 'elements', $out);
}

// ── ?report=settings — the handful of settings that decide whether this install WORKS ──────────
//
// AN EXPLICIT ALLOW-LIST, never a prefix and never "all of them". `storage.modx_token` lives in the
// same namespace as `storage.base_url`, so a report keyed by prefix would publish a credential from
// a host that answers the public internet. Adding a name here is a deliberate act; there is no
// pattern that can sweep one in by accident.
//
// Why these five: every one of them has been WRONG on a live ground and cost a session. base_url was
// http, which cannot carry a client certificate, and blanked every PDF in the estate. environment
// scoped reads to a hold that did not exist. sso.provider stayed google on an internal-zone host, where
// Google cannot issue a callback at all. log_target and log_level decide whether any of this leaves
// a trace — and the log directory was unwritable on both grounds while they read FILE and 1.
if ($q === 'settings') {
    $NAMED = ['storage.base_url', 'storage.environment', 'sso.provider', 'log_target', 'log_level'];
    $vals = [];
    foreach ($NAMED as $k) {
        $v = $modx->getOption($k, null, null);
        $vals[$k] = is_scalar($v) ? (string) $v : null;
    }
    $send(200, 'settings', [
        'environment' => Application::config('environment'),
        'caller'      => $caller,
        'settings'    => $vals,
        'note'        => 'an explicit allow-list — storage.modx_token and anything else in these '
                       . 'namespaces is deliberately unreachable here',
    ]);
}

// ── ?report=log — THIS APPLICATION'S OWN LINES OUT OF ITS OWN ERROR LOG ──────────────────────────────
//
// WHY IT IS HERE AND NOT ITS OWN PAGE. Reaching a new snippet needs a new MODx resource, and a new
// resource is anonymously reachable until setup/04 runs — so the cheap-looking option
// creates a page whose protection depends on a separate step, to serve a diagnostic. This route is
// already certificate-gated by the vhost, and the caller list above is a different list, so the
// second question is separated by the thing that actually separates callers rather than by a URL.
//
// WHAT IT DOES NOT BECOME, because the docblock at the top of this file forbids exactly that: it
// does not grow fields onto the INFO report, does not read a setting, and cannot return a line no
// element of ours wrote. `$OWN_LOG_MARKERS` is the disclosure surface and it is one array.
//
// THE PATH IS MODX'S OWN, asked of MODx rather than assembled: cache_path + logs/error.log is
// where core writes, and `error_log_filename` overrides the filename on some installs.
if ($wantLog) {
    // NO 503 ANY MORE. This used to refuse when the library was absent, which meant the agent that
    // owns MODx could not read its own log until another agent ran a converge. The fallback above
    // removes that; `library` in the response says which implementation answered, so a ground
    // running on the fallback is visible rather than silently equivalent.
    $lines = (int) ($_GET['lines'] ?? 200);
    $lines = max(1, min($lines, 200));

    $cachePath = (string) $modx->getOption('cache_path', null, rtrim(MODX_CORE_PATH, '/') . '/cache/');
    $fname     = (string) ($modx->getOption('error_log_filename', null, 'error.log') ?: 'error.log');
    $logPath   = rtrim($cachePath, '/') . '/logs/' . $fname;

    $r = $inlineOwnLines($logPath, $OWN_LOG_MARKERS, $lines);
    $r['caller']      = $caller;
    $r['markers']     = $OWN_LOG_MARKERS;   // so a caller seeing nothing knows WHAT was looked for
    $r['environment'] = Application::config('environment');
    // 200 even when the file is absent: "I looked and it is not there" is a successful answer to
    // this question, and the body says so. A 404 here would mean the ROUTE was not found.
    $send(200, 'log', $r);
}

// `environment` is NOT inferred. The player never knows its ground — infra writes it, and if it is
// not written the endpoint says so rather than guessing from a hostname, which is the fault
// this estate has paid for twice.
$environment = Application::config('environment');
if ($environment === null) {
    $send(500, 'error', [
        'error'  => 'environment is not set',
        'detail' => 'core/config/application.php carries no `environment` key. The verdicts no '
                  . 'longer need it (the handed set already IS this instance\'s obligations), but '
                  . 'the report names where this instance believes it is, and that fact is infra\'s '
                  . 'to write — this endpoint does not infer its ground from a hostname, a path or '
                  . 'a database name. infra renders it.',
    ]);
}

// The HANDED required-set, rendered by infra beside application.php —
// same file, same reasoning: the web role reads no env, and a DB row restores estate-wide. The
// manifest no longer carries required_in, so which needs are required HERE is a fact only this
// artifact states. It is generated from this atom's own manifest (required-set.py), so infra can
// mark "not required here" visibly and can never delete a declared need.
$handedPath = rtrim(MODX_CORE_PATH, '/') . '/config/required-set.json';
$handed = is_readable($handedPath) ? json_decode((string)file_get_contents($handedPath), true) : null;
if (!is_array($handed) || !is_array($handed['requires'] ?? null)) {
    // REFUSE rather than report an empty requirement list. An actuator that cannot read its own
    // required set and answers `requirements: []` is green over an empty haystack — the worst
    // answer this estate produces, and the one it has produced most often.
    $send(500, 'error', [
        'error'  => 'required set not handed',
        'detail' => 'core/config/required-set.json could not be read or parsed, so the requirement '
                  . 'list would be empty for the wrong reason. infra renders it beside '
                  . 'application.php. Refusing rather than reporting nothing as fine.',
    ]);
}

// The `web` role, because that is what is serving this request. Settings, never getenv(): the
// PHP-FPM this runs under reads no environment at all, so getenv() would report `not set` for
// values that are present and correct.
$read = Actuator::webReader(
    static function (string $key) use ($modx): ?string {
        $v = $modx->getOption($key, null, null);
        return is_scalar($v) ? (string)$v : null;
    }
);

$out = Actuator::verdicts($handed, $read);
// The artifact deliberately carries no ground name (the player never knows its ground); the
// REPORT still names where this instance believes it is, because infra wrote exactly that into
// application.php and a divergence monitor needs to see it. Echoed verbatim, rule 11.
$out['environment'] = $environment;
$out['role'] = 'web';
// null when infra has not stamped one. NULL, not omitted and not a guess: a missing commit is a
// fact a divergence monitor must be able to see, and inventing one from the repo on disk would
// report what this checkout says rather than what this deployment is running.
$out['commit'] = Application::config('commit');

// THE MODX VERSION, from two witnesses. `commit` above says which of OUR code is deployed; this
// says which MODX is under it, and until now nothing in the estate answered that from the ground —
// the version was known only from the artefacts the install was built from, which is a document
// rather than a reading. Two witnesses because they can disagree: an earlier upgrade in this estate
// exited 0 while logging a failure, and a single witness would have reported whichever half was
// wrong with complete confidence. `agree` is null when one of them cannot be read — "I could not
// compare" is not "they differ".
//
// This is a field on the info report and the docblock above says this file must not add one. It is
// added deliberately and the distinction is the one that docblock is actually about: it forbids
// this endpoint becoming a way to read VALUES for debugging. A version is what an actuator is for —
// every other actuator in the estate answers `q=version` — and it discloses nothing a caller
// admitted here does not already know.
// null when the library is not on this ground yet — NOT omitted, because a divergence monitor must
// be able to see that this instance cannot answer, and an absent key reads as "nobody asked".
$out['modx_version'] = class_exists('Pustak\\Modx\\Diagnostics')
    ? Diagnostics::version(
        static fn(string $k): ?string => is_scalar($v = $modx->getOption($k, null, null)) ? (string) $v : null,
        method_exists($modx, 'getVersionData') ? $modx->getVersionData() : null
      )
    : null;

$send(200, 'info', $out);
