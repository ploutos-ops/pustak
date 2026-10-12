/**
 * jumpNav — build an in-page jump navigation from a resource's section headers.
 *
 * A section header is a div carrying the header style (bg-info + font-monospace) AND an id, e.g.
 *   <div class="text-center bg-info fs-5 font-monospace" id="overview">Overview</div>
 * jumpNav scans the current resource's content for those and renders a <ul> of same-page jump
 * links (href="#id"). It is SELF-MAINTAINING: add/rename/remove a section header and the nav
 * follows on the next render — no per-document nav list to keep in sync. Outputs nothing when the
 * resource has no section headers, so it is safe to place on every page (e.g. in the template).
 *
 * Call uncached-or-cached freely; it derives purely from the resource content.
 */

$content = $modx->resource ? (string) $modx->resource->get('content') : '';
if ($content === '') {
    return '';
}

$items = [];

// ── THE SECOND FORM, and the bug that made it necessary ────────────────────────────────────────
// This snippet reads the resource's RAW content, not the rendered page. When 420 sections were
// converted to `[[$sectionStart? &id=`x` &nav=`y` &title=`z`]]` (2026-07-30) the markup this used
// to scan for stopped existing in the field, and every one of those navs silently emptied — 409
// entries to 13. The conversion's own guard compared the RENDERED page, which was byte-identical,
// and never exercised this reader at all. A verifier only covers the consumer it happens to run.
//
// So the declaration is read directly, before the div scan. It is also the better source: the id,
// the short label and the title are named properties rather than things inferred from a class list.
if (preg_match_all('#\[\[\$section(?:Start|Head)\?(?P<props>[^\]]*)\]\]#s', $content, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
    foreach ($calls as $c) {
        $props = [];
        if (preg_match_all('#&(\w+)=`([^`]*)`#s', $c['props'][0], $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $p) {
                $props[$p[1]] = $p[2];
            }
        }
        if (empty($props['id'])) {
            continue;
        }
        $label = trim(preg_replace('/\s+/', ' ', strip_tags($props['nav'] ?? $props['title'] ?? '')));
        if ($label === '') {
            continue;
        }
        $items[$c[0][1]] = '<li><a href="#'
                 . htmlspecialchars($props['id'], ENT_QUOTES) . '" class="jump-link">'
                 . htmlspecialchars($label, ENT_QUOTES) . '</a></li>';
    }
}

// A NOTE ON WHY THIS SCANS OPENING TAGS ONLY. It used to match `<div ...>(.*?)</div>` as a unit.
// preg_match_all resumes after the END of each match, so a header nested inside any wrapper div was
// swallowed by the wrapper's match and never seen — six of the ten headers in worldview/uk-949 were
// missing from its nav for exactly that reason, and the six that survived were the ones that
// happened to sit at the top level. Opening tags are scanned instead, and each header's label is
// taken from there to its own next `</div>`; headers do not nest inside each other.
if (preg_match_all('#<div\b([^>]*)>#i', $content, $divs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
    foreach ($divs as $d) {
        $attrs = $d[1][0];
        // WHAT COUNTS AS A SECTION HEADER, and why this is not just `bg-info` any more.
        //
        // It used to be: bg-info AND font-monospace. That made the COLOUR the marker — and the
        // colour is the owner's, carrying his own meaning. corporate/shubha-50 has 27 sections in
        // success/warning/primary/danger and appeared in its own nav ZERO times; estate-wide, 9
        // documents of 60 were partly or wholly missing and nothing said so, because a nav with
        // fewer entries than it should have looks exactly like a nav.
        //
        // A header written through [[$sectionStart]] / [[$sectionHead]] always emits `data-nav`,
        // which is a DECLARATION rather than an inference. So: a data-nav marks a header, and the
        // old colour rule stays as well, for anything still written by hand. Either way an `id` is
        // required — there is nothing to jump to without one.
        $declared = strpos($attrs, 'data-nav=') !== false;
        $styled = strpos($attrs, 'bg-info') !== false && strpos($attrs, 'font-monospace') !== false;
        if (!$declared && !$styled) {
            continue;
        }
        if (!preg_match('#\bid="([^"]+)"#', $attrs, $idm)) {
            continue;
        }
        // A SHORT NAME FOR THE NAV, when the heading on the page is long.
        //
        // The owner, doing this by hand before jumpNav existed, put a short label in the nav and a
        // full title on the page — and automating the nav took that away, because the heading
        // text became the only source. `data-nav` gives it back:
        //
        //   <div class="... anchor-target" id="state" data-nav="State">
        //     Where this estate stands — 4 built, 2 partial, 5 absent</div>
        //
        // A data-* attribute renders nothing, so the page is unchanged. Headings without one keep
        // using their own text, so nothing existing has to be edited — the long label was never
        // wrong, just long in a narrow column.
        $label = '';
        if (preg_match('#\bdata-nav="([^"]*)"#i', $attrs, $navm)) {
            $label = trim(preg_replace('/\s+/', ' ', $navm[1]));
        }
        if ($label === '') {
            $inner = substr($content, $d[0][1] + strlen($d[0][0]));
            $end = stripos($inner, '</div>');
            $label = trim(preg_replace('/\s+/', ' ',
                strip_tags($end === false ? $inner : substr($inner, 0, $end))));
        }
        if ($label === '') {
            continue;
        }
        $items[$d[0][1]] = '<li><a href="#'
                 . htmlspecialchars($idm[1], ENT_QUOTES) . '" class="jump-link">'
                 . htmlspecialchars($label, ENT_QUOTES) . '</a></li>';
    }
}

ksort($items);
return implode("\n", $items);
