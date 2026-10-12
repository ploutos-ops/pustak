/**
 * feedView — one page that shows any feed, picked by name.
 *
 * The owner, 2026-10-04: a hundred JSON outputs must not mean a hundred pages. So the layout travels
 * with the data — every feed is a SELF-DESCRIBING document (tableRenderer: title,
 * columns, rows or groups) — and this page only chooses which one to draw.
 *
 *   [[!feedView]]                  no ?feed=   the index: every feed's name, what it is, its address
 *                                  ?feed=NAME  that feed, drawn by tableRenderer from its own address
 *
 * THE READER NEVER SUPPLIES AN ADDRESS. ?feed= is a NAME, looked up in the index; a name the index
 * does not carry is refused. Taking a URL from the query string would let anyone make this server
 * fetch anything it can reach and print the answer.
 *
 * THE INDEX IS NOT LIBRARY'S. Its address is the system setting `feeds.index_url` (the estate
 * file), and the document there — {"feeds":[{"name","url","what"}]} — is owned by whoever publishes
 * it (census). Library keeps no list of its own, so a new feed needs no Library change.
 *
 * Every table it draws wears estate-top, estate-striped and estate-numbered (a row counter that
 * renumbers after a sort or filter) — the stripe and counter on the owner's word, 2026-10-04: a list cell runs many lines, and the row's one-line
 * cells belong at its top, not floating in its middle (the owner, 2026-10-04).
 *
 * Must be called uncached ([[!feedView]]): it reads the query string, and the feed it draws is
 * fetched at render time.
 */

$h = function ($v) { return htmlspecialchars(is_scalar($v) ? (string) $v : '', ENT_QUOTES, 'UTF-8'); };
$fail = function ($why) use ($h) {
    return '<p class="text-danger">feedView: ' . $h($why) . '</p>';
};

$indexUrl = trim((string) $modx->getOption('feeds.index_url', null, ''));
if ($indexUrl === '') {
    return $fail('this ground has no feeds.index_url, so there is no index to read');
}
if (!preg_match('#^https?://#i', $indexUrl)) {
    return $fail('feeds.index_url must be an http(s) address');
}

$ch = curl_init($indexUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
$body   = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err    = curl_error($ch);
curl_close($ch);
if ($body === false || $status !== 200) {
    return $fail("the index at $indexUrl answered " . ($body === false ? "nothing ($err)" : "HTTP $status"));
}
$index = json_decode((string) $body, true);
if (!is_array($index) || !isset($index['feeds']) || !is_array($index['feeds'])) {
    return $fail("the index at $indexUrl is not {\"feeds\":[...]}");
}

$feeds = [];
foreach ($index['feeds'] as $f) {
    if (is_array($f) && isset($f['name'], $f['url']) && is_scalar($f['name']) && is_scalar($f['url'])
        && (string) $f['name'] !== '' && preg_match('#^https?://#i', (string) $f['url'])) {
        $feeds[(string) $f['name']] = ['url' => (string) $f['url'], 'what' => is_scalar($f['what'] ?? null) ? (string) $f['what'] : '',
                                       'extra' => array_filter($f, function ($v, $k) {
                                           return !in_array($k, ['name', 'url', 'what'], true) && is_scalar($v);
                                       }, ARRAY_FILTER_USE_BOTH)];
    }
}

$here = $modx->makeUrl($modx->resource->get('id'), '', '', 'full');
$name = isset($_GET['feed']) && is_string($_GET['feed']) ? trim($_GET['feed']) : '';

// SEARCH: the reader's words go to whois — lookup, search or answer — with fmt=feed, and
// the answer is drawn like any feed. The door is the setting whois.url (estate.json); the reader
// supplies WORDS, never an address. An answer takes tens of seconds (measured 47 s), so it waits 55.
$verbs = ['lookup' => ['whois', 'Look up', 10], 'search' => ['search', 'Search', 15], 'answer' => ['ask', 'Answer', 55]];
// `words`, not `q`: MODx's friendly URLs carry the page's own path in ?q= (q=feeds on this page).
$q     = isset($_GET['words']) && is_string($_GET['words']) ? trim($_GET['words']) : '';
$verb  = isset($_GET['verb']) && is_string($_GET['verb']) && isset($verbs[$_GET['verb']]) ? $_GET['verb'] : 'search';
$whois = rtrim(trim((string) $modx->getOption('whois.url', null, '')), '/');
$form  = function () use ($whois, $here, $q, $verb, $verbs, $h) {
    if (!preg_match('#^https?://#i', $whois)) {
        return '';
    }
    $opts = '';
    foreach ($verbs as $k => [, $label]) {
        $opts .= '<option value="' . $k . '"' . ($k === $verb ? ' selected' : '') . '>' . $label . '</option>';
    }
    // THE ESTATE FORM (claude-shared design/estate-form.md): a query's row — fields left, GO at
    // the right edge, as on every Data and kuber screen. feed-search stays: it is what the page script
    // looks for to say "asking" and stop a second press.
    return '<form class="estate-form feed-search mb-3" method="get" action="' . $h($here) . '">'
         . '<div class="estate-filter-row"><div class="estate-filter-fields">'
         . '<label class="estate-label">Ask <input class="estate-field" type="search" name="words" size="50" value="' . $h($q) . '" placeholder="the estate\'s documents"></label>'
         . '<label class="estate-label">How <select class="estate-field" name="verb">' . $opts . '</select></label>'
         . '<span class="feed-search-wait" hidden>Asking whois — an answer takes up to a minute…</span>'
         . '</div><div class="estate-filter-actions"><button class="estate-go" type="submit">Go</button></div></div>'
         . '</form>';
};

// &part=`heading` — the page header for the page.feeds template: the same card pageTitle draws (its
// breadcrumbs, its menutitle band), whose title line and description name the feed on ?feed=<name>
// and are the page's own longtitle and introtext otherwise (the owner, 2026-10-04).
if (($part ?? '') === 'heading') {
    $res   = $modx->resource;
    $title = (string) $res->get('longtitle');
    $intro = (string) $res->get('introtext');
    if ($name !== '' && isset($feeds[$name])) {
        $title = $name;
        $intro = $feeds[$name]['what'];
    } elseif ($q !== '' && $whois !== '') {
        $title = $verbs[$verb][1] . ': ' . $q;
        $intro = 'what the estate\'s documents say, from whois';
    }
    // The body's call below runs after this one: it drops its own title from the table band, since
    // this header now names what is shown (the owner, 2026-10-04).
    $modx->setPlaceholder('feedView.heading', '1');
    return '[[$Crumbs]]'   // the same breadcrumbs pageTitle draws; MODx parses a snippet's output at once
         . '<div class="card mb-3 text-center">'
         // The way back to the list from one feed or a search sits in the top line, at its left, so it
         // costs no height of its own (the owner, 2026-10-04).
         . '<div class="card-header bg-dark text-white position-relative">'
         . (($name !== '' || $q !== '') ? '<a class="link-light position-absolute start-0 ms-3" href="' . $h($here) . '">&larr; All feeds</a>' : '')
         . $h($res->get('menutitle') ?: $res->get('pagetitle')) . '</div>'
         . '<div class="card-body bg-info">'
         . ($title !== '' ? '<h5 class="card-title fw-bold">' . $h($title) . '</h5>' : '')
         . ($intro !== '' ? '<p class="card-text fst-italic">' . $h($intro) . '</p>' : '')
         . '</div></div>';
}

$noTitle = $modx->getPlaceholder('feedView.heading') === '1' ? 1 : 0;

if ($q !== '' && $whois !== '') {
    [$path, , $wait] = $verbs[$verb];
    $src = $whois . '/' . $path . '?' . http_build_query(['q' => $q, 'fmt' => 'feed']);
    return $form() . $modx->runSnippet('tableRenderer', ['url' => $src, 'urlTimeout' => $wait, 'feedBase' => $here,
        'noTitle' => $noTitle, 'class' => 'estate-top estate-striped estate-numbered estate-sort estate-filter']);
}

if ($name === '') {
    // The index, itself drawn as a self-describing document, so it looks like every feed.
    // EVERY FIELD THE INDEX CARRIES IS SHOWN (the owner, 2026-10-04: "i thought you show all columns").
    // name, what and url have their places; any other plain field the index publishes — today
    // census's `count` — becomes a column of its own, in the order the index first uses it,
    // blank for a feed that does not carry it. A new field needs no change here.
    $extraKeys = [];
    foreach ($feeds as $f) {
        foreach (array_keys($f['extra']) as $k) {
            if (!in_array($k, $extraKeys, true)) { $extraKeys[] = $k; }
        }
    }
    $labels = ['count' => 'Items'];
    $rows = [];
    foreach ($feeds as $n => $f) {
        $row = [
            'name' => $n,
            'what' => $f['what'],
            'url'  => $f['url'],
        ];
        foreach ($extraKeys as $k) { $row['x_' . $k] = $f['extra'][$k] ?? ''; }
        $rows[] = $row;
    }
    $cols = [
        // The feed mark opens the feed; its tooltip is the address (the owner, 2026-10-04: the address is
        // a detail once the viewer draws any feed by name, so it is no longer a column of its own).
        ['key' => 'name', 'label' => 'Feed', 'type' => 'feed', 'href' => 'url'],
        ['key' => 'what', 'label' => 'What it is'],
    ];
    foreach ($extraKeys as $k) {
        $cols[] = ['key' => 'x_' . $k, 'label' => $labels[$k] ?? ucfirst(str_replace('_', ' ', $k))];
    }
    $doc = ['title' => $noTitle ? '' : 'Feeds', 'columns' => $cols, 'rows' => $rows];
    return $form() . $modx->runSnippet('tableRenderer', ['json' => json_encode($doc), 'feedBase' => $here,
                                               'class' => 'estate-fit estate-top estate-striped estate-numbered estate-sort estate-filter']);
}

if (!isset($feeds[$name])) {
    return $form() . $fail("the index has no feed named \"$name\"");
}
// No source line under the table (the owner, 2026-10-04): the address is a detail once the viewer draws
// any feed by name; it is the feed mark's tooltip on the list.
// The search box on every Feeds view, not only the list (the owner, 2026-10-04: "where is whois").
return $form() . $modx->runSnippet('tableRenderer', ['url' => $feeds[$name]['url'], 'feedBase' => $here, 'noTitle' => $noTitle,
                                           'class' => 'estate-top estate-striped estate-numbered estate-sort estate-filter']);
