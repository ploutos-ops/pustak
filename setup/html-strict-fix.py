#!/usr/bin/env python3
"""
html-strict-fix — repair, in place and deterministically, the strict-HTML5 errors in seat
documents that a program can repair WITHOUT changing what a browser renders.

The owner, 2026-09-14: "if you have to update html programmatically in a deterministic manner, please
fix what can be done by program." html-strict-report.py finds the errors; this fixes the classes
below and leaves every other one for a person, saying which and why.

THE RULE THAT DECIDES WHAT IS FIXABLE: the edit must be exactly what the HTML5 parsing algorithm
already does with the broken input, so the rendered page is unchanged — or it must restore an
attribute the author evidently typed and the browser silently dropped. Nothing here guesses.

    expected-named-entity                a bare `&`                       -> `&amp;`
    attributes-in-end-tag                `</div class="x">`               -> `</div>`
    duplicate-attribute                  second `class=` on one tag       -> removed (browsers keep the FIRST)
    invalid-character-in-attribute-name  `border="0" ,` / `height"=20"`   -> comma removed / `height="20"`
    unexpected-character-after-attribute-value      `"x"y`                -> `"x" y`
    unexpected-character-in-unquoted-attribute-value `colspan=3class="x"` -> `colspan=3 class="x"`
    unexpected-doctype                   a <!DOCTYPE> inside a fragment   -> removed (ignored by browsers)
    unexpected-start-tag-implies-end-tag `<a>..<a>` (a inside a)          -> `</a>` inserted before the inner one
    unexpected-end-tag                   `</a>` with no open <a>          -> removed (ignored by browsers)
    unexpected-end-tag-in-table-row      same, in a table row             -> removed
    unexpected-cell-end-tag              `</td>` while <a> is open        -> `</a></td>` (the implied close)
    end-tag-too-early                    `</div>` while <a>/<span> open   -> the implied closes inserted
    expected-closing-tag-but-got-eof     file ends with <p>/<li>/<a> open -> their end tags appended

TWO THINGS THE PARSE CANNOT SEE, and the guards for them. (1) A chunk can leave an element open
for another chunk to close — cardStart/cardEnd and sectionStart/sectionEnd do exactly that with
<div>, and a few carry table fragments — so a stray-looking `</div>` in a document may close a
chunk's element. The names each chunk leaves unbalanced are read from common/chunks at start, and
for every document the set is computed from the chunks THAT DOCUMENT calls (transitively): a
stray end tag of such a name is never removed, no close is inserted for or through such an
element, and it is never closed at EOF. A document that calls no cardStart has no chunk-opened
<div> from a chunk. (3) The TEMPLATE holds elements open around the content — read from the text
before [[*content]] in common/templates — and a stray end tag of one of those names closes the
template's wrapper early, which the browser does not ignore: never removed either.
(2) An "element" like `<alice.smith@example.org>` is an e-mail address the author typed in
angle brackets; the browser swallows it as an unknown tag. Closing it would be nonsense, so no
end tag is ever inserted for a name outside the HTML element list — reported instead.

MODx tags: every `[[...]]` is invisible to the parse (removed, line structure kept) and is never
edited; positions are mapped back to the original text so the edit lands on the right bytes.

    python3 setup/html-strict-fix.py            # dry run: what would change, per document
    python3 setup/html-strict-fix.py --write    # apply

Needs MODX_ESTATE (the estate FILE); seat/documents is derived from it. One error is fixed per
parse and the document re-parsed, up to 300 times, so a fix never depends on a stale position;
an error nothing repairs is skipped, so the repairable ones after it are still reached.
Exit 1 while any document still carries an error this program does not repair.
"""
import glob
import html.entities
import os
import re
import sys
from collections import Counter

import html5lib
from html5lib.html5parser import ParseError

HERE = os.path.dirname(os.path.abspath(__file__))
estate = os.environ.get('MODX_ESTATE') or sys.exit(
    "REFUSING: MODX_ESTATE is not set — it names the estate FILE; seat/documents is derived from it.")
DOCS = os.path.join(os.path.dirname(os.path.abspath(estate)), 'seat', 'documents')
os.path.isdir(DOCS) or sys.exit(f"REFUSING: no documents directory at {DOCS}")
CHUNKS = os.path.join(HERE, '..', 'common', 'chunks')
os.path.isdir(CHUNKS) or sys.exit(f"REFUSING: no chunks at {CHUNKS} — the chunk-opened names cannot be read")
WRITE = '--write' in sys.argv

VOID = {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param',
        'source', 'track', 'wbr', 'path'}
KNOWN = set('''a abbr address article aside audio b bdi bdo blockquote body button canvas caption
cite code col colgroup data datalist dd del details dfn dialog div dl dt em fieldset figcaption
figure footer form h1 h2 h3 h4 h5 h6 head header hgroup html i iframe ins kbd label legend li main
map mark menu meter nav noscript object ol optgroup option output p picture pre progress q rp rt
ruby s samp script section select slot small span strong style sub summary sup table tbody td
template textarea tfoot th thead time title tr u ul var video font center strike tt big nobr
svg g'''.split()) | VOID
# Elements whose end the browser supplies at the end of a block anyway; safe to append at EOF.
SAFE_EOF = {'p', 'li', 'dd', 'dt', 'a', 'span', 'b', 'i', 'em', 'strong', 'u', 'del', 'ins',
            'small', 'sup', 'sub', 'code', 'font', 'strike', 's'}
ENTITY = re.compile(r'&(?:[A-Za-z][A-Za-z0-9]*|#[0-9]+|#[xX][0-9A-Fa-f]+);')
ATTR = re.compile(r'''([^\s"'>/=]+)(?:\s*=\s*("[^"]*"|'[^']*'|[^\s"'=<>`]+))?''')


CALL = re.compile(r'\[\[!?\$([A-Za-z0-9_.-]+)')


def chunk_tables():
    """For every chunk: the element names it leaves unbalanced, and the chunks it calls."""
    opens, calls = {}, {}
    for path in glob.glob(os.path.join(CHUNKS, '*')):
        name = os.path.basename(path).rsplit('.html', 1)[0]
        text = open(path, encoding='utf-8', errors='replace').read()
        starts = Counter(m.lower() for m in re.findall(r'<([A-Za-z][A-Za-z0-9]*)', text))
        ends = Counter(m.lower() for m in re.findall(r'</([A-Za-z][A-Za-z0-9]*)', text))
        opens[name] = {n for n in starts | ends if starts[n] != ends[n] and n not in VOID}
        calls[name] = set(CALL.findall(text))
    return opens, calls


CHUNK_OPENS, CHUNK_CALLS = chunk_tables()
TEMPLATES = os.path.join(HERE, '..', 'common', 'templates')


def template_open_names():
    """Element names every template holds OPEN around the document's content — a stray-looking
    end tag of such a name closes the template's wrapper early, and the browser does NOT ignore
    it. Measured 2026-09-14: removing a stray </div> from boatsafety-449 moved the rest of the
    page out of the template's content column. Read from the text before [[*content]]."""
    names = set()
    for path in glob.glob(os.path.join(TEMPLATES, '*')):
        text = open(path, encoding='utf-8', errors='replace').read()
        m = re.search(r'\[\[!?\*content\]\]', text)
        head = text[:m.start()] if m else text
        starts = Counter(x.lower() for x in re.findall(r'<([A-Za-z][A-Za-z0-9]*)', head))
        ends = Counter(x.lower() for x in re.findall(r'</([A-Za-z][A-Za-z0-9]*)', head))
        names |= {n for n in starts if starts[n] > ends[n] and n not in VOID}
    return names


TEMPLATE_OPEN = template_open_names() if os.path.isdir(TEMPLATES) else sys.exit(
    f"REFUSING: no templates at {TEMPLATES} — the names a template holds open cannot be read")


def chunk_open_for(text):
    """Element names left unbalanced by any chunk THIS document calls, transitively. A document
    that never calls cardStart has no chunk-opened <div>, so a stray </div> in it is truly stray."""
    todo, seen, names = set(CALL.findall(text)), set(), set()
    while todo:
        c = todo.pop()
        if c in seen:
            continue
        seen.add(c)
        names |= CHUNK_OPENS.get(c, set())
        todo |= CHUNK_CALLS.get(c, set())
    return names


def strip_tags(text):
    """Remove every [[...]] (nesting-aware), keeping newlines so lines stay aligned.
    Returns (stripped, index) where index[i] is the original offset of stripped[i]."""
    out, index, i, n = [], [], 0, len(text)
    while i < n:
        if text.startswith('[[', i):
            depth, j = 1, i + 2
            while j < n and depth:
                if text.startswith('[[', j):
                    depth += 1; j += 2
                elif text.startswith(']]', j):
                    depth -= 1; j += 2
                else:
                    j += 1
            for k in range(i, j):
                if text[k] == '\n':
                    out.append('\n'); index.append(k)
            i = j
        else:
            out.append(text[i]); index.append(i); i += 1
    index.append(n)
    return ''.join(out), index


def all_errors(text):
    """Every parse error with the open-element stack AT that error, in document order. The
    parser runs lenient so it recovers exactly as a browser does and reports what follows; the
    stack is captured by wrapping the parser's own parseError."""
    stripped, index = strip_tags(text)
    parser = html5lib.HTMLParser(strict=False, namespaceHTMLElements=False)
    found = []
    original = parser.parseError

    def capture(errorcode='XXX-undefined-error', datavars=None):
        found.append((parser.tokenizer.stream.position(), errorcode, dict(datavars or {}),
                      [e.name for e in parser.tree.openElements] if parser.tree.openElements else []))
        return original(errorcode, datavars)
    parser.parseError = capture
    parser.parseFragment(stripped)
    lines = stripped.split('\n')
    out = []
    for (line, col), code, data, open_names in found:
        off = sum(len(l) + 1 for l in lines[:line - 1]) + min(col, len(lines[line - 1]))
        out.append((code, data, line, index[min(off, len(index) - 1)], open_names))
    return out


def start_tag_at(text, pos):
    """The start tag that contains or ends at pos: (start, end) or None. Quote-aware."""
    i = pos
    while True:
        i = text.rfind('<', 0, i)
        if i < 0:
            return None
        if re.match(r'<[A-Za-z]', text[i:i + 2]):
            break
    j, n, quote = i + 1, len(text), None
    while j < n:
        c = text[j]
        if quote:
            if c == quote:
                quote = None
        elif c in '"\'':
            quote = c
        elif c == '>':
            return (i, j + 1)
        j += 1
    return None


def end_tag_before(text, pos, name):
    """The last `</name>` ending at or before pos (a tree error is reported after its token)."""
    lo = max(0, pos - 4000)
    best = None
    for m in re.finditer(rf'</{re.escape(name)}\s*>', text[lo:pos + 2], re.I):
        best = (lo + m.start(), lo + m.end())
    return best


def dedupe_attrs(tag):
    m = re.match(r'<([A-Za-z][^\s/>]*)', tag)
    head = m.end()
    body = tag[head:-1].rstrip()
    self_closing = body.endswith('/')
    body = body.rstrip('/')
    seen, kept = set(), []
    for a in ATTR.finditer(body):
        name = a.group(1).lower()
        if name in seen:
            continue
        seen.add(name); kept.append(a.group(0))
    return tag[:head] + (' ' + ' '.join(kept) if kept else '') + (' /' if self_closing else '') + '>'


def replace_tag(text, span, new, note):
    if new == text[span[0]:span[1]]:
        return None, note + ' — but the tag is unchanged by the rule'
    return text[:span[0]] + new + text[span[1]:], note


def fix_once(text, err, chunk_open):
    """Return (new_text, note) or (None, reason)."""
    code, data, line, pos, open_names = err
    name = data.get('name', '')
    where = f'line {line}'

    if code == 'expected-named-entity':
        s = text.rfind('\n', 0, pos) + 1
        i = text.rfind('&', s, pos + 1)
        if i < 0:
            return None, 'bare & not found at the reported position'
        m = ENTITY.match(text, i)
        if m and (m.group(0)[1:] in html.entities.html5 or m.group(0)[1:2] == '#'):
            return None, f'{m.group(0)} at the reported position is a real entity'
        # `&aamp;` is not an entity, so the browser shows it literally; `&amp;aamp;` shows the same.
        return text[:i] + '&amp;' + text[i + 1:], f'{where}: & -> &amp;'

    if code == 'attributes-in-end-tag':
        lo = max(0, pos - 4000)
        best = None
        for m in re.finditer(r'</([A-Za-z][A-Za-z0-9]*)\s+[^>]*>', text[lo:pos + 2]):
            best = (lo + m.start(), lo + m.end(), m.group(1))
        if not best:
            return None, 'end tag with attributes not found'
        return text[:best[0]] + f'</{best[2]}>' + text[best[1]:], f'{where}: </{best[2]} ...> -> </{best[2]}>'

    if code == 'invalid-character-in-attribute-name' and re.match(r'"[\s/>]', text[pos:pos + 2]) \
            and text[pos - 1] == '"':
        # `target="_x"">` — a doubled closing quote becomes an attribute NAMED `"`, which the
        # browser drops. Position-exact, because the tag itself no longer scans cleanly.
        return text[:pos] + text[pos + 1:], f'{where}: doubled quote removed'

    if code in ('duplicate-attribute', 'invalid-character-in-attribute-name',
                'unexpected-character-after-attribute-value',
                'unexpected-character-in-unquoted-attribute-value'):
        span = start_tag_at(text, pos)
        if not span:
            return None, 'start tag not found'
        tag = text[span[0]:span[1]]
        if code == 'duplicate-attribute':
            return replace_tag(text, span, dedupe_attrs(tag), f'{where}: duplicate attribute dropped')
        if code == 'invalid-character-in-attribute-name':
            new = re.sub(r'\s+,(?=\s)', '', tag)                                  # border="0" ,
            new = re.sub(r'(\s[A-Za-z][A-Za-z0-9-]*)"=([^"\s>]+)"', r'\1="\2"', new)   # height"=20"
            new = re.sub(r'(="[^"]*")"(?=[\s/>])', r'\1', new)                            # target="_x""
            return replace_tag(text, span, new, f'{where}: attribute typo repaired')
        if code == 'unexpected-character-after-attribute-value':
            new = re.sub(r'(=\s*"[^"]*"|=\s*\'[^\']*\')(?=[^\s/>])', r'\1 ', tag)
            return replace_tag(text, span, new, f'{where}: space inserted after attribute value')
        new = re.sub(r'=(\d+)([A-Za-z][A-Za-z0-9-]*=)', r'=\1 \2', tag)          # colspan=3class=
        return replace_tag(text, span, new, f'{where}: space inserted inside an unquoted value')

    if code == 'unexpected-doctype':
        lo = max(0, pos - 4000)
        best = None
        for m in re.finditer(r'<!DOCTYPE[^>]*>', text[lo:pos + 2], re.I):
            best = (lo + m.start(), lo + m.end())
        if not best:
            return None, 'doctype not found'
        return text[:best[0]] + text[best[1]:], f'{where}: doctype removed'

    if code == 'unexpected-start-tag-implies-end-tag':
        name = data.get('startName', name)
        span = start_tag_at(text, pos)
        if not span or not re.match(rf'<{re.escape(name)}\b', text[span[0]:], re.I):
            return None, f'the inner <{name}> was not found at the reported position'
        if name not in open_names:
            return None, f'<{name}> inside <{name}> reported but none is open'
        return text[:span[0]] + f'</{name}>' + text[span[0]:], f'{where}: </{name}> inserted before a nested <{name}>'

    def remove_stray(tag_name):
        if tag_name in TEMPLATE_OPEN:
            return None, f'stray </{tag_name}> closes the template\'s own <{tag_name}> around the content — not ignored by the browser'
        if tag_name in chunk_open:
            return None, f'stray </{tag_name}> may close an element a chunk this document calls left open'
        m = end_tag_before(text, pos, tag_name)
        if not m:
            return None, f'stray </{tag_name}> not found at the reported position'
        return text[:m[0]] + text[m[1]:], f'{where}: stray </{tag_name}> removed'

    if code in ('unexpected-end-tag', 'unexpected-end-tag-in-table-row'):
        if name in open_names[1:]:
            return None, f'</{name}> matches an open element — the browser closes through to it, not a stray'
        return remove_stray(name)

    if code in ('unexpected-cell-end-tag', 'end-tag-too-early'):
        target = name
        if code == 'unexpected-cell-end-tag':
            m = end_tag_before(text, pos, 'td') or end_tag_before(text, pos, 'th')
            if not m:
                return None, 'cell end tag not found'
            target = re.match(r'</(t[dh])', text[m[0]:], re.I).group(1).lower()
        else:
            if target not in open_names:
                return remove_stray(target)
            m = end_tag_before(text, pos, target)
        if not m or target not in open_names:
            return None, f'</{target}> or its open element not found'
        above = [n for n in open_names[open_names.index(target) + 1:] if n not in VOID]
        if not above:
            return None, f'nothing open above <{target}>'
        tangled = [n for n in [target] + above if n in chunk_open]
        if tangled:
            return None, f'<{tangled[0]}> is chunk-opened in this document — the close cannot be placed by parsing the document alone'
        unknown = [n for n in above if n not in KNOWN]
        if unknown:
            return None, f'<{unknown[0]}> is open — not an HTML element, probably text typed in angle brackets'
        closes = ''.join(f'</{n}>' for n in reversed(above))
        return text[:m[0]] + closes + text[m[0]:], f'{where}: {closes} inserted before </{target}>'

    if code == 'expected-closing-tag-but-got-eof':
        above = [n for n in open_names[1:] if n not in VOID]
        if not above:
            return None, 'nothing open at EOF'
        unsafe = [n for n in above if n not in SAFE_EOF or n in chunk_open or n in TEMPLATE_OPEN]
        if unsafe:
            return None, f'<{unsafe[0]}> is open at EOF — closing it there would move the template content that follows'
        closes = ''.join(f'</{n}>' for n in reversed(above))
        end = len(text.rstrip())
        return text[:end] + closes + text[end:], f'EOF: {closes} appended'

    return None, f'{code} is not a class this program repairs'


changed, residue, notes_all = 0, {}, 0
for path in sorted(glob.glob(os.path.join(DOCS, '**', '*.html'), recursive=True)):
    rel = os.path.relpath(path, DOCS)
    original = text = open(path, encoding='utf-8').read()
    notes, stopped = [], []
    chunk_open = chunk_open_for(text)
    for _ in range(300):
        errors = all_errors(text)
        if not errors:
            break
        fixed, refused = False, []
        for err in errors:
            new, note = fix_once(text, err, chunk_open)
            if new is not None:
                text = new
                notes.append(note)
                fixed = True
                break                       # re-parse: every later position is stale now
            refused.append(f'{err[0]} at line {err[2]}: {note}')
        if not fixed:
            stopped = refused
            break
    if notes:
        changed += 1; notes_all += len(notes)
        print(f'{rel}: {len(notes)} fix(es)' + ('' if WRITE else ' (dry run)'))
        for n in notes:
            print(f'    {n}')
        if WRITE and text != original:
            open(path, 'w', encoding='utf-8').write(text)
    if stopped:
        residue[rel] = stopped
        for r in stopped:
            print(f'{rel}: LEFT FOR A PERSON — {r}')

print(f'{changed} document(s) {"changed" if WRITE else "would change"}, {notes_all} fix(es), '
      f'{len(residue)} document(s) still carry an error a program does not repair')
sys.exit(1 if residue else 0)
