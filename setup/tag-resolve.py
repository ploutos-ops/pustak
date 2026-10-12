#!/usr/bin/env python3
"""Read every seat .html file, find every [[...]] MODx tag, and say which ones do
not resolve against the current modx.

Migration 2. The migration moved resources but not chunks, and nobody
checked afterwards whether what the moved pages REFER TO actually arrived. This is
that check, for every kind of reference a page can make — not just chunks:

    [[$name]]      chunk        -> modx_site_htmlsnippets.name
    [[name]]       snippet      -> modx_site_snippets.name   ([[!name]] = uncached)
    [[~id]]        link         -> modx_site_content.id
    [[*name]]      field / TV   -> core resource field, else modx_site_tmplvars.name
    [[++key]]      setting      -> modx_system_settings.key + context settings
    [[+name]]      placeholder  -> set at runtime; COUNTED, never flagged
    [[%key]]       lexicon      -> not resolvable from the DB; counted

SOURCE OF TRUTH: the seat files in the modx-estate repo (`seat/documents/**`, plus the
elements under `common/`), which is the estate's master copy of the content — the
same tree `export.py` regenerates from a production restore. Run
`ops.sh local restore_modx_dev` first and the files, and this report, are prod.

WHY FILES AND NOT THE DB: they are the same content, but a defect found in the file
is a defect you can fix, commit and deploy. The DB is checked too, for the tags a
file cannot carry — TV VALUES live only in the database.

NESTING is handled by a real scanner rather than a regex: MODx tags nest
([[$row? &link=`[[~123]]`]]), and a regex either stops at the first ]] (losing the
outer tag) or swallows to the last (losing the inner one). Both inner and outer are
reported here.

KNOWN FALSE-POSITIVE, contained rather than hidden: the `claude` context holds
report and documentation pages whose text contains literal [[~id]] and [[$name]]
examples. Those are prose, not references. They are reported in a separate section
so the real list stays clean and nothing is silently dropped.

Usage:
    python3 setup/tag-resolve.py          # the report
    python3 setup/tag-resolve.py --all    # also list what DID resolve
    python3 setup/tag-resolve.py --json   # machine-readable, for the migration
"""

import argparse
import json
import os
import re
import sys
from collections import defaultdict

import pymysql

# Moved here from claude/skunkworks/modx-chunk-migration (2026-10-07): it is part of
# our addition to the MODx package. The seat is in the estate repository, and the
# connection is the repository's own db_config, which takes the socket where one is set.
from domains import db_config
from estate import estate_path

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SEAT = estate_path("seat", "documents")
COMMON = os.path.join(REPO, "common")
PREFIX = "modx_"

DB = db_config()

# MODx core settings that live in config.inc.php, NOT in modx_system_settings.
# Flagging these as unresolved is wrong — [[++assets_url]] resolves on every page
# of this site. Found the honest way: the report claimed 39 broken references on
# pages that demonstrably render.
CONFIG_SETTINGS = {
    "base_path", "base_url", "core_path", "manager_path", "manager_url",
    "assets_path", "assets_url", "connectors_path", "connectors_url",
    "processors_path", "http_host", "site_url", "cache_path", "cache_url",
}

# Contexts whose pages are documentation ABOUT tags, so a tag in them may be prose.
DOC_CONTEXTS = {"claude", "modxSite"}

# Core resource fields addressable as [[*field]] — everything else must be a TV.
CORE_FIELDS = {
    "id", "pagetitle", "longtitle", "description", "alias", "link_attributes",
    "published", "pub_date", "unpub_date", "parent", "isfolder", "introtext",
    "content", "richtext", "template", "menuindex", "searchable", "cacheable",
    "createdby", "createdon", "editedby", "editedon", "deleted", "deletedon",
    "deletedby", "publishedon", "publishedby", "menutitle", "donthit", "privateweb",
    "privatemgr", "content_dispo", "hidemenu", "class_key", "context_key",
    "content_type", "uri", "uri_override", "hide_children_in_tree",
    "show_in_tree", "properties", "alias_visible",
}


def q(sql, args=None):
    with q.conn.cursor() as cur:
        cur.execute(sql, args) if args else cur.execute(sql)
        return cur.fetchall()


q.conn = pymysql.connect(**DB)


COMMENT_RE = re.compile(r"/\*.*?\*/", re.S)


def strip_comments(text, path):
    """Remove /* */ and // comments from PHP/JS sources before scanning.

    A tag inside a comment is documentation, not a dependency: loginWidget.php says
    "replaces the [[!Login]] widget" and tableRenderer.php demonstrates itself with
    [[$sharedContacts]]. Counting those as unresolved references invents work that
    does not exist — the whole point of this report is a list you can act on.
    Only applied to code files; an HTML comment in a document may well contain a
    real tag that renders, so documents are left alone.
    """
    if not path.endswith((".php", ".js")):
        return text
    text = COMMENT_RE.sub("", text)
    return "\n".join(re.sub(r"//.*$", "", line) for line in text.split("\n"))


def scan_tags(text):
    """Yield every [[...]] tag body in `text`, innermost tags included.

    A depth counter, not a regex: nested tags are common in this estate
    ([[$children-rows? &tpl=`[[$row]]`]]) and both levels are real references.
    """
    i, stack = 0, []
    out = []
    while i < len(text) - 1:
        two = text[i:i + 2]
        if two == "[[":
            stack.append(i + 2)
            i += 2
        elif two == "]]" and stack:
            start = stack.pop()
            out.append(text[start:i])
            i += 2
        else:
            i += 1
    return out


def classify(body):
    """(kind, name) for a tag body. `name` is '' when there is nothing to resolve."""
    b = body.strip()
    if not b:
        return ("empty", "")
    if b.startswith("$"):
        return ("chunk", re.split(r"[\s:?]", b[1:], maxsplit=1)[0])
    if b.startswith("~"):
        return ("link", re.split(r"[\s:?]", b[1:], maxsplit=1)[0])
    if b.startswith("++"):
        return ("setting", re.split(r"[\s:?]", b[2:], maxsplit=1)[0])
    if b.startswith("+"):
        return ("placeholder", re.split(r"[\s:?]", b[1:], maxsplit=1)[0])
    if b.startswith("*"):
        return ("field", re.split(r"[\s:?]", b[1:], maxsplit=1)[0])
    if b.startswith("%"):
        return ("lexicon", re.split(r"[\s:?]", b[1:], maxsplit=1)[0])
    name = re.split(r"[\s:?]", b.lstrip("!"), maxsplit=1)[0]
    return ("snippet", name)


def load_universe():
    return {
        "chunk": {r[0] for r in q(f"SELECT name FROM {PREFIX}site_htmlsnippets")},
        "snippet": {r[0] for r in q(f"SELECT name FROM {PREFIX}site_snippets")},
        "tv": {r[0] for r in q(f"SELECT name FROM {PREFIX}site_tmplvars")},
        "resource": {str(r[0]) for r in q(f"SELECT id FROM {PREFIX}site_content")},
        "deleted": {str(r[0]) for r in q(f"SELECT id FROM {PREFIX}site_content WHERE deleted=1")},
        "setting": ({r[0] for r in q(f"SELECT `key` FROM {PREFIX}system_settings")}
                    | {r[0] for r in q(f"SELECT `key` FROM {PREFIX}context_setting")}
                    | CONFIG_SETTINGS),
    }


def resolves(kind, name, universe):
    """(ok, note). ok=None means 'not statically resolvable' — counted, not flagged."""
    if kind == "chunk":
        return (name in universe["chunk"], "")
    if kind == "snippet":
        return (name in universe["snippet"], "")
    if kind == "link":
        if not name.isdigit():
            return (None, "non-numeric link target")
        if name in universe["deleted"]:
            return (False, "target is in the recycle bin")
        return (name in universe["resource"], "")
    if kind == "field":
        return (True, "") if name in CORE_FIELDS else (name in universe["tv"], "TV")
    if kind == "setting":
        return (name in universe["setting"], "")
    return (None, "")          # placeholder, lexicon, empty


def sources():
    """(label, context, text) for every seat document and every element file."""
    out = []
    for root, _dirs, files in os.walk(SEAT):
        ctx = os.path.relpath(root, SEAT).split(os.sep)[0]
        for fn in sorted(files):
            if fn.startswith("."):
                continue
            path = os.path.join(root, fn)
            rel = os.path.relpath(path, REPO)
            try:
                body = open(path, encoding="utf-8", errors="replace").read()
                out.append((rel, ctx, strip_comments(body, path)))
            except OSError as e:
                print(f"unreadable: {rel}: {e}", file=sys.stderr)
    # The estate's own chunks live in the estate repository, not in common/; their tags are
    # the estate's content and are resolved like every other element body.
    owned = estate_path("seat", "estate-elements", "chunks")
    for fn in sorted(os.listdir(owned)) if os.path.isdir(owned) else []:
        path = os.path.join(owned, fn)
        if os.path.isfile(path):
            body = open(path, encoding="utf-8", errors="replace").read()
            out.append((os.path.relpath(path, os.path.dirname(os.path.dirname(owned))), "element:chunks",
                        strip_comments(body, path)))
    for sub in ("chunks", "snippets", "templates", "plugins"):
        d = os.path.join(COMMON, sub)
        if not os.path.isdir(d):
            continue
        for fn in sorted(os.listdir(d)):
            path = os.path.join(d, fn)
            if os.path.isfile(path):
                rel = os.path.relpath(path, REPO)
                body = open(path, encoding="utf-8", errors="replace").read()
                out.append((rel, f"element:{sub}", strip_comments(body, path)))
    return out


def db_only_tags():
    """TV VALUES — the one place a tag lives in the DB and in no file."""
    rows = q(
        "SELECT v.contentid, tv.name, v.value "
        f"FROM {PREFIX}site_tmplvar_contentvalues v "
        f"JOIN {PREFIX}site_tmplvars tv ON tv.id = v.tmplvarid "
        "WHERE v.value LIKE %s",
        ("%[[%",),
    )
    return [(f"DB: TV {name} on resource {rid}", "db", str(val)) for rid, name, val in rows]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--all", action="store_true", help="also list references that resolve")
    ap.add_argument("--json", action="store_true")
    args = ap.parse_args()

    universe = load_universe()
    unresolved = defaultdict(lambda: defaultdict(list))   # kind -> name -> [labels]
    doc_context_hits = defaultdict(lambda: defaultdict(list))
    counts = defaultdict(int)
    resolved = defaultdict(set)
    files_scanned = 0

    for label, ctx, text in sources() + db_only_tags():
        files_scanned += 1
        for body in scan_tags(text):
            kind, name = classify(body)
            counts[kind] += 1
            if not name:
                continue
            ok, note = resolves(kind, name, universe)
            if ok is None:
                continue
            if ok:
                resolved[kind].add(name)
            else:
                key = f"{name}{' — ' + note if note else ''}"
                bucket = doc_context_hits if ctx in DOC_CONTEXTS else unresolved
                bucket[kind][key].append(label)

    if args.json:
        print(json.dumps({
            "scanned": files_scanned,
            "counts": dict(counts),
            "unresolved": {k: {n: v for n, v in sorted(d.items())} for k, d in unresolved.items()},
            "doc_context_only": {k: {n: v for n, v in sorted(d.items())} for k, d in doc_context_hits.items()},
        }, indent=2))
        return

    print(f"scanned {files_scanned} sources (seat documents + common/ elements + TV values)\n")
    print("TAGS FOUND, by kind:")
    for kind in sorted(counts, key=lambda k: -counts[k]):
        bad = sum(len(v) for v in unresolved[kind].values())
        extra = f"   UNRESOLVED: {len(unresolved[kind])} distinct, {bad} references" if unresolved[kind] else ""
        print(f"  {kind:<12} {counts[kind]:>5}{extra}")

    total_bad = sum(len(v) for d in unresolved.values() for v in d.values())
    print(f"\n{'=' * 70}\nUNRESOLVED REFERENCES — {total_bad} in served contexts\n{'=' * 70}")
    for kind in ("chunk", "snippet", "link", "field", "setting"):
        if not unresolved[kind]:
            continue
        print(f"\n{kind.upper()} — {len(unresolved[kind])} distinct:")
        for name in sorted(unresolved[kind], key=lambda n: (-len(unresolved[kind][n]), n)):
            where = unresolved[kind][name]
            print(f"  {name:<28} {len(where):>3} refs")
            for w in sorted(set(where))[:6]:
                print(f"  {'':<28}     {w}")
            if len(set(where)) > 6:
                print(f"  {'':<28}     … {len(set(where)) - 6} more files")

    if doc_context_hits:
        n = sum(len(v) for d in doc_context_hits.values() for v in d.values())
        print(f"\n{'=' * 70}")
        print(f"IN DOCUMENTATION CONTEXTS ({', '.join(sorted(DOC_CONTEXTS))}) — {n} refs, "
              f"most are prose examples, NOT defects. Listed so nothing is hidden.")
        for kind in sorted(doc_context_hits):
            names = sorted(doc_context_hits[kind])
            print(f"  {kind:<10} {len(names)} distinct: {', '.join(names[:8])}"
                  + (" …" if len(names) > 8 else ""))

    if args.all:
        print(f"\n{'=' * 70}\nRESOLVED (present and correct):")
        for kind in sorted(resolved):
            print(f"  {kind:<10} {len(resolved[kind])}: {', '.join(sorted(resolved[kind])[:14])}"
                  + (" …" if len(resolved[kind]) > 14 else ""))


if __name__ == "__main__":
    main()
