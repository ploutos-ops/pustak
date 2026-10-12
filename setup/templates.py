"""templates.py — a template's id, found by its NAME.

The pustak package installs the templates, so their ids are whatever this database handed out —
on a fresh install page.public is not id 3. A script that wants a template asks for it by name, and
a database without it REFUSES, naming the package, rather than pointing pages at another template.
"""


def template_id(cur, name):
    cur.execute("SELECT id FROM modx_site_templates WHERE templatename=%s", (name,))
    row = cur.fetchone()
    if not row:
        raise SystemExit(f"template {name!r} is not in this database — install the pustak package "
                         f"(it carries the templates) before running this step")
    return row[0]
