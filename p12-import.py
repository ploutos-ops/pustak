#!/usr/bin/env python3
"""P12.import — one way in (constitution P12).

No interface holds a store's credential, or imports or invokes a store's client. The gate reads
three fields of the manifest in the same tree ("`layer`, `store_clients`,
`store_key` — P12 made declarable") and holds no list of its own:

    identity.layer          the layer set: globs relative to the repository's root
    identity.store_clients  each client as <kind>:<name> — module:, handle:, binary:, path:, door:
    identity.store_key      local names, each a requires[].key of the same manifest

It walks every file git tracks, and outside the layer set refuses:
    module:X   an import of X or of X/... (Python `import`/`from`, JS `import`/`require`/`import()`)
    handle:X   a reference to the name X
    binary:X   an invocation of the program X (the name as a word in code)
    path:X     a reference to X — the key that carries the path, or the path itself
    door:X     the door's path prefix X in a string
    a store_key  a read of the key by its name
A comment line, and a Python docstring, is prose and not code, and is not read. A file the manifest names in `layer` is the layer.

It prints the set, and every file inside it that holds a client, so a set widened to turn the
gate green is read and not inferred (rulings, P12). An atom with no store declares `"layer": []`
with `$layer_why` and neither of the other two; one or two of the three without the rest is a
refusal, by name.

    python3 p12-import.py [ROOT]     exit 0 clean, 1 on an offender or a malformed declaration

The same bytes ship in every skunkworks repository that carries the gate; a copy beside a
p12-import.py.source stamp names the commit it was copied from.
"""
import fnmatch
import io
import json
import re
import subprocess
import sys
import tokenize
from pathlib import Path

KINDS = ("module", "handle", "binary", "path", "door")
# Files whose lines are read. Anything else (a lockfile, an image, a corpus) holds no code.
CODE = re.compile(r"\.(py|pyi|sh|bash|js|mjs|cjs|ts|tsx|mts|jsx|php|sql|service|ya?ml|toml|ini|conf)$|(^|/)[^./]+$")
COMMENT = re.compile(r"^\s*(#|//|\*|/\*|<!--|--\s)")


def fail(msg):
    print(f"p12-import: REFUSED — {msg}", file=sys.stderr)
    sys.exit(1)


def manifest(root):
    try:
        doc = json.loads((root / "service.json").read_text())
        ident = doc["identity"]
    except (OSError, ValueError, KeyError) as e:
        fail(f"service.json identity could not be read: {e}")
    present = [k for k in ("layer", "store_clients", "store_key") if k in ident]
    if not present:
        fail("service.json identity declares none of layer, store_clients, store_key — absent is "
             "undeclared, never empty")
    layer = ident.get("layer")
    if not isinstance(layer, list) or not all(isinstance(g, str) and g for g in layer):
        fail("identity.layer must be a list of path globs")
    if layer == []:
        if set(present) != {"layer"}:
            fail("identity.layer is [] (no store) but store_clients or store_key is declared")
        if not ident.get("$layer_why"):
            fail("identity.layer is [] with no $layer_why saying why there is no store")
        return [], [], [], []
    missing = [k for k in ("store_clients", "store_key") if k not in ident]
    if missing:
        fail(f"identity.layer is declared without {', '.join(missing)}")
    clients, keys = ident["store_clients"], ident["store_key"]
    if not isinstance(clients, list) or not clients:
        fail("identity.store_clients must be a non-empty list of <kind>:<name>")
    parsed = []
    for c in clients:
        kind, _, name = str(c).partition(":")
        if kind not in KINDS or not name:
            fail(f"store_clients entry {c!r} is not <kind>:<name> with kind one of {', '.join(KINDS)}")
        parsed.append((kind, name))
    if not isinstance(keys, list) or not all(isinstance(k, str) and k for k in keys):
        fail("identity.store_key must be a list of local names")
    # A requires[] row's key is one name, a group of names (all-of) under one canonical, or a
    # name per role ({"web": ..., "tooling": ...}).
    required = set()
    for r in doc.get("requires", []) + ident.get("requires", []):
        k = r.get("key") if isinstance(r, dict) else None
        k = list(k.values()) if isinstance(k, dict) else k
        required.update(k if isinstance(k, list) else [k])
    stray = [k for k in keys if k not in required]
    if stray:
        fail(f"store_key {', '.join(stray)} is no requires[].key of this manifest")
    return layer, parsed, keys, clients


def prose_lines(rel, text):
    """Lines of a Python file that are a comment or a docstring — prose, never code."""
    if not rel.endswith((".py", ".pyi")):
        return set()
    out, prev = set(), tokenize.NEWLINE
    try:
        for tok in tokenize.generate_tokens(io.StringIO(text).readline):
            if tok.type == tokenize.COMMENT or (
                    tok.type == tokenize.STRING and prev in (tokenize.NEWLINE, tokenize.NL, tokenize.INDENT,
                                                             tokenize.DEDENT, tokenize.ENCODING)):
                out.update(range(tok.start[0], tok.end[0] + 1))
            if tok.type not in (tokenize.COMMENT, tokenize.NL):
                prev = tok.type
    except (tokenize.TokenError, SyntaxError, IndentationError):
        pass
    return out


def matcher(kind, name):
    n = re.escape(name)
    if kind == "module":
        sub = rf"{n}(?:[./][\w./-]*)?"
        return re.compile(
            rf"^\s*(?:from\s+{sub}\s+import\b|import\s+{sub}(?:\s|,|$))"          # Python
            rf"|\bfrom\s+['\"]{sub}['\"]|\bimport\s+['\"]{sub}['\"]"              # JS static
            rf"|\b(?:require|import)\(\s*['\"]{sub}['\"]\s*\)")                   # JS dynamic
    if kind == "door":
        return re.compile(re.escape(name))
    # handle, binary, path, key: the name as a word (a path itself, as written)
    lead = r"(?<![\w-])" if re.match(r"[\w-]", name) else ""
    tail = r"(?![\w-])" if re.search(r"[\w-]$", name) else ""
    return re.compile(lead + n + tail)


def in_layer(rel, layer):
    # fnmatch's * crosses "/", so "lib/**" holds every file under lib/
    return any(fnmatch.fnmatchcase(rel, g) for g in layer)


def main():
    root = Path(sys.argv[1] if len(sys.argv) > 1 else Path(__file__).resolve().parent).resolve()
    layer, clients, keys, raw = manifest(root)
    if not layer:
        print("p12-import: clean — identity.layer is [] (no store), declared with its reason")
        return 0
    rules = [(f"{k}:{n}", matcher(k, n)) for k, n in clients] + [(f"key:{k}", matcher("key", k)) for k in keys]
    files = subprocess.run(["git", "-C", str(root), "ls-files", "-z"], capture_output=True, check=True,
                           text=True).stdout.split("\0")
    me = Path(__file__).name
    inside, outside = {}, {}
    for rel in filter(None, files):
        if Path(rel).name in (me, "service.json") or not CODE.search(rel):
            continue
        try:
            text = (root / rel).read_text(errors="replace")
        except (OSError, IsADirectoryError):
            continue
        hits, prose = [], prose_lines(rel, text)
        for no, line in enumerate(text.splitlines(), 1):
            if no in prose or COMMENT.match(line):
                continue
            hits += [(no, label) for label, rx in rules if rx.search(line)]
        if hits:
            (inside if in_layer(rel, layer) else outside)[rel] = hits
    print(f"p12-import: the layer set (service.json identity.layer): {', '.join(layer)}")
    print(f"p12-import: clients {', '.join(raw)}; key {', '.join(keys) or '(none)'}")
    print(f"p12-import: {len(inside)} file(s) inside the set hold a client:")
    for rel, hits in sorted(inside.items()):
        print(f"  {rel}  {' '.join(sorted({l for _, l in hits}))}")
    if outside:
        print(f"p12-import: REFUSED — {len(outside)} file(s) outside the layer set reach the store (P12):",
              file=sys.stderr)
        for rel, hits in sorted(outside.items()):
            for no, label in hits:
                print(f"  {rel}:{no}  {label}", file=sys.stderr)
        return 1
    print("p12-import: clean — no client and no key outside the layer set")
    return 0


if __name__ == "__main__":
    sys.exit(main())
