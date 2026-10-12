# tableRenderer — how to write a table as data


> **`seat/` and `install/` are not in this repository.** They are the estate repository (`modx-estate`)
> (2026-08-10): his documents, the seed, the docroot tar. Paths below that
> begin `seat/` or `install/` are relative to THAT checkout. The scripts here resolve it
> from `MODX_ESTATE`, which names the estate FILE (`<checkout>/estate.json`); the checkout is
> derived as the directory that file sits in. Point it at the checkout and every script refuses.
> Nothing here defaults — an unset or wrongly-shaped MODX_ESTATE refuses, it does not guess.

purpose: Turn a hand-typed HTML table into named data the page renders — so a person reads a table and a machine reads fields.

## The idea, in two lines

A table typed as `<td>` is text forever. Nothing can tell which column is an email and which is a
phone number — that is not theoretical, it is why "who are my contacts at Barclays" came back with
a name and no way to reach him.

So: write the **data**, name the **fields**, and let the snippet draw the table.

## The smallest thing that works

Put this anywhere in a page:

```html
<script type="application/ld+json" id="contacts">
[
  {"name": "Alice Example",      "email": "alice@example.org",         "telephone": "+44 20 7946 0001"},
  {"name": "Client Support",     "email": "support@example.org",       "telephone": "+44 20 7946 0002"}
]
</script>

[[!tableRenderer? &from=`contacts`]]
```

That is the whole pattern. `<script type="application/ld+json">` is **not** JavaScript — a browser
neither draws it nor runs it, so if the snippet ever fails you get a missing table, never a wall of
raw JSON on the page.

The `id` is yours to choose; `&from` names it. Several blocks with different ids can live in one page.

## Writing the data

It is plain JSON. Rules that actually bite:

- **Double quotes only**, around both the key and the text. `'name'` is not JSON.
- **Comma between rows, none after the last one.**
- A **missing key renders an empty cell** — rows are allowed to differ. The support desk has no
  personal name and that is fine.
- Field names are yours. Where a standard name fits, prefer schema.org's — `name`, `email`,
  `telephone`, `jobTitle`, `address` — because those are already agreed and nobody has to argue
  about them later. Your own terms are fine too.

## Choosing what shows

Without `&columns` you get every field of the first row, in the order you wrote them.

```
[[!tableRenderer? &from=`contacts` &columns=`name=Contact,telephone=Phone,email=Email`]]
```

**A field you leave out of `&columns` is still in the data** — it is simply not on screen. That is
the most useful thing here: the Barclays card shows five columns while the search reads the man's
qualification and the branch address too. What is *shown* and what is *known* became separate
decisions.

## The properties

| Property | What it does |
|---|---|
| `&from` | id of a `<script type="application/ld+json">` block in this page. |
| `&json` | the data directly, or from a chunk: `&json=`[[$sharedContacts]]`` — for a list used on several pages. |
| `&url` | fetch the data from a URL each time the page is drawn. **Needs `[[!tableRenderer`** (see below). |
| `&rows` | where the rows are inside the document, e.g. `jobs`, when the feed wraps them in an envelope. |
| `&columns` | which fields, in what order, with their headings: `name=Contact,email=Email`. |
| `&caption` | a line under the table. May carry `{field}` from the top of the document, e.g. `Generated {generated}`. |
| `&class` | table classes. Default is the site's usual styling. Add `w-auto` to stop it filling the width. |
| `&link` | make a column a link: `&link=`name:url`` renders the name as a link to that row's `url` field. |
| `&badge` + `&badgeMap` | render a column as a coloured badge per value: `&badge=`status`` with `&badgeMap=`ok=text-bg-success,failed=text-bg-danger``. |
| `&tip` | a hover tooltip on one column, built from other fields: `&tip=`job:schedule=Schedule,detail=Detail``. |
| `&localTime` | a column of UTC timestamps, shown in the reader's own clock. |
| `&rowClass` | put a class on the row from a column's value: `&rowClass=`status:ok=tbl-quiet``. |
| `&group` | start a full-width heading row whenever a field's value changes. |
| `&auth` | fetch **as the person reading the page**, so a service can answer with their own data: `&auth=`kuber``. Needs `&url` (see below). |

## Two table behaviours worth knowing

Add these to `&class`:

- **`smart-table`** — click a heading to sort, and a filter box appears. Click a heading three times
  and you are back to your own order.
- **`quiet-first`** — rows carrying `tbl-quiet` start hidden behind one "Show all" control. Pair it
  with `&rowClass` to open a long table on the exceptions. Nothing is ever removed; the control
  always says how many are hidden.

## Live data from elsewhere

```
[[!tableRenderer?
  &url=`http://reports.web.internal.test/backup-status.json`
  &rows=`jobs`
  &columns=`job=Job,last_run=Last run,status=Status`
]]
```

**Note the `!`.** With `&url` the call must be `[[!tableRenderer`, not `[[tableRenderer`. Without it
MODx would store one fetch in the page cache and serve that same answer for as long as the cache
lived — a table about freshness, itself stale. The snippet refuses a cached call rather than
quietly doing the wrong thing, and tells you so.

Use `&url` only for data **someone else keeps up to date**. For your own content, the data belongs
in the page: one file, versioned, diffable, nothing hidden in the manager.

## A value that must not be in the page at all — `&auth`

Some numbers should not be written down here in any form. An account number typed as a `<td>` is in
the page; the same number pasted into a JSON block is still in the page. `&auth` is the third
option: **the page holds the id, the other system holds the value, and the page asks for it as the
person who is reading.**

```
[[!tableRenderer?
  &url=`https://kuber.example.internal.test/v1/figures?id=67012`
  &auth=`kuber`
  &columns=`label=,value=`
]]
```

What happens when someone opens that page:

- **Signed in** — the fetch carries a 60-second signed assertion saying *who they are*. The other
  system decides what that person may see, exactly as it would if they had signed in to it
  directly. Two people opening the same page can correctly see different numbers, or one of them
  nothing at all.
- **Not signed in** — nothing is asked and nothing is shown. Blank. The page never falls back to
  asking on the site's behalf, because that would hand one person's figures to whoever opened the
  page.

Three things about it worth knowing:

- **The value is never stored here.** It is not in the file, not in git, not in a backup of this
  site, and not in the page cache — `&auth` requires `[[!tableRenderer`, like any `&url` call.
- **The credential never reaches the browser.** It is made and used on the server, during the
  moment the page is drawn.
- **It is not a password for the page.** `&auth` says who is reading; it does not decide who may
  open the page. That is still MODx's job — a page holding sensitive figures should be protected
  like any other.

To use it for a new system, ask infra for a keypair (the private half becomes the system setting
`<name>.jwt_private_key`, the public half goes to that system) and then `&auth=`<name>``. No change
to this snippet is needed.

## When nothing appears

The snippet never breaks a page. It renders nothing and leaves a note in the HTML source saying
why. **View source and search for `tableRenderer`:**

```
<!-- tableRenderer: invalid JSON — Syntax error -->
<!-- tableRenderer: no <script type="application/ld+json" id="contacts"> block found in this page -->
<!-- tableRenderer: &url requires an uncached call — write [[!tableRenderer instead of [[tableRenderer -->
<!-- tableRenderer: &rows path 'jobs' does not exist in the data (no 'jobs') -->
<!-- tableRenderer: no signed-in, Google-verified reader: nothing was requested from 'kuber' -->
<!-- tableRenderer: system setting kuber.jwt_private_key is empty — refusing to fetch … -->
```

Nearly always a missing comma, a smart quote where a straight one belongs, or a name that does not
match between `id=` and `&from`.

## What it will not do

- **No styling.** It emits classes; the CSS is in the site's stylesheet (`estate.stylesheet`). No inline styles, ever.
- **No re-ordering.** Rows appear in the order you wrote them. Sorting is the reader's choice at
  render time, never the file's.
- **No guessing.** Nothing is auto-linked because it looks like a URL, and no colour is inferred
  from a value. If you want a link or a badge, you say so.

## Converting an existing page

One block at a time, and only where the markup is really a record:

1. Find a table or list whose rows are the same **shape** — the same fields repeated.
2. Write those rows as JSON in a `<script>` block, giving each part a name.
3. Replace the old markup with `[[!tableRenderer? &from=`…` &columns=`…`]]`.
4. Look at it on the bench, then deploy.

**Leave alone** anything whose markup is doing real work that a plain grid cannot express —
`rowspan` grouping, buttons inside cells, the `bird`/kuber hooks. The Barclays accounts table is
exactly that case: it stayed as hand-written HTML on purpose, and only the contact block was
converted. Converting it would have degraded a working page to prove a point.

## See also

`common/snippets/tableRenderer.php` — the source, whose header carries the reasoning behind every
property. `seat/documents/platform/dashboard-1558.html` — the fullest example in use (live feed,
badges, tooltips, exceptions-first). `seat/documents/worldview/barclays-899.html` — the smallest one.
