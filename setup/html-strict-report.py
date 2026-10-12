#!/usr/bin/env python3
"""
html-strict-report — every seat document, parsed as strict HTML5 after its MODx tags are removed.

The owner, 2026-09-14: "validate that all html files in git pass strict checks." A document is a body
FRAGMENT (seat/documents/<context>/<alias>-<id>.html) that MODx renders inside a template, and it
is full of `[[...]]` tags — chunk calls that open and close the cards and sections, link tags,
placeholders. Browsers forgive almost everything, which is why 72 of 344 documents rendered fine
for years while carrying stray end tags, unquoted attributes and bare ampersands. This parses each
one with html5lib in STRICT mode, which raises the same errors the WHATWG conformance checker
would, and prints every one with its line.

What "strip the tags" means, and its limit: every `[[...]]` is removed before the parse, innermost
first, so a chunk that opens a <div> and its closing chunk both vanish and the document's OWN
markup is what gets judged. A document that closes a tag a chunk opened, or opens one a chunk
closes, is reported as unbalanced — correctly: seat/tag-balance-report.py owns the block-tag
pairing, this owns the HTML in between. Line numbers are the file's own (tags are removed
in place, never across lines).

    python3 setup/html-strict-report.py            # report; exit 1 if any document has an error
    python3 setup/html-strict-report.py --summary  # counts by error kind and by context only

READ-ONLY. Needs MODX_ESTATE (the estate FILE); the documents directory is derived from it.
"""
import glob
import os
import re
import sys
from collections import Counter

import html5lib
from html5lib.html5parser import ParseError

estate = os.environ.get('MODX_ESTATE') or sys.exit(
    "REFUSING: MODX_ESTATE is not set — it names the estate FILE; seat/documents is derived from it.")
docs = os.path.join(os.path.dirname(os.path.abspath(estate)), 'seat', 'documents')
os.path.isdir(docs) or sys.exit(f"REFUSING: no documents directory at {docs}")

TAG = re.compile(r'\[\[(?:[^\[\]]|\[(?!\[)|\](?!\]))*\]\]')   # one innermost [[...]] tag


def strip_tags(text):
    prev = None
    while prev != text:
        prev, text = text, TAG.sub('', text)
    return text


summary = '--summary' in sys.argv
files = sorted(glob.glob(os.path.join(docs, '**', '*.html'), recursive=True))
kinds, contexts, bad = Counter(), Counter(), 0
for path in files:
    body = strip_tags(open(path, encoding='utf-8').read())
    parser = html5lib.HTMLParser(strict=True, namespaceHTMLElements=False)
    try:
        parser.parseFragment(body)
    except ParseError:
        pass                       # strict mode stops at the first error; parser.errors has it
    if not parser.errors:
        continue
    bad += 1
    rel = os.path.relpath(path, docs)
    contexts[rel.split(os.sep)[0]] += 1
    for (line, _col), code, data in parser.errors:
        kinds[code] += 1
        if not summary:
            print(f'{rel}:{line}: {code} {data or ""}'.rstrip())

if summary or bad:
    print('by kind:   ' + ', '.join(f'{k} {n}' for k, n in kinds.most_common()))
    print('by context: ' + ', '.join(f'{c} {n}' for c, n in sorted(contexts.items())))
print(f'{len(files)} documents, {bad} with strict-parse errors')
sys.exit(1 if bad else 0)
