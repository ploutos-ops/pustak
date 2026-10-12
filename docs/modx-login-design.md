# MODx multi-context login & access control — proper implementation

> ⚠️ Sign-in is **SSO**, design + build in **`docs/modx-sso-design.md`**; the Login extra and its `[[!Login]]` form are not installed. This form-login/RBAC design is the pre-SSO baseline — the RBAC in §1–§7 is unchanged by SSO, which only replaces *how identity is established*. Current working model: **`docs/workflow.md`**.

This is a from-scratch design, not a patch of the current (non-working) setup. It's grounded
in how MODx 3.x actually enforces access, your notes in client doc 15, and the bania scaffolding
we carried over. Two things a login setup controls, and both fall out of the same mechanism:

- **Which page you land on** — anonymous → public/login; logged-in → your home.
- **Which menu you see** — the nav only lists resources the current user is allowed to list.

---

## 1. The mental model — four tables, one idea

MODx access is "a principal (user group) is granted a **policy** on a **target**, in a **context**."
There are two targets that matter here:

| Layer | Table | Grants | "target" |
|---|---|---|---|
| **Context access** | `modx_access_context` | can this group *enter* this context at all | context_key |
| **Resource-group access** | `modx_access_resource_groups` | can this group *see resources in this group* | resource-group id |

Plus two "buckets":

| Bucket | Table | What it holds |
|---|---|---|
| **User groups** | `modx_membergroup_names` (+ `modx_member_groups` for membership) | `Members` (your logged-in users), `Administrator` |
| **Resource groups** | `modx_documentgroup_names` (+ `modx_document_groups` = resource→group) | `Protected` (the resources only Members may see) |

Policies you'll use (`modx_access_policies`): **3 = Load Only**, **4 = Load, List and View**.

**The "magic checkbox" you remember** = putting a resource into a resource group. The instant a
resource is in *any* resource group, it becomes invisible to everyone **except** user groups that
have been granted access to that group. A resource in **no** group is public.

So protection is *opt-in per resource*: unprotected = public, add-to-Protected = members-only.
That's why your rule "only home is protected, all others open" works with one checkbox per home.

---

## 2. The wiring, per context (this is the whole thing)

For each real context `family / corporate / worldview`:

**a. Everyone can enter the context (so public pages render + anonymous gets a real 401, not a blank):**
```
modx_access_context:  target=<context>, principal_class=modUserGroup, principal=0 (all/anonymous),
                      authority=9999, policy=3 (Load Only)
```
> This is the row that's **missing today** — only `web` has it. Without it the context isn't granted
> to anyone, so nothing resolves. principal **0** = the built-in "all users incl. anonymous" group.

**b. Members can enter the context (needed so a logged-in user is recognized here):**
```
modx_access_context:  target=<context>, principal=<Members group id>, authority=9999, policy=4
```

**c. Members can see the Protected resource group in this context:**
```
modx_access_resource_groups: target=<Protected group id>, principal=<Members id>,
                             authority=9999, policy=4 (Load, List and View), context_key=<context>
```
> Today this row exists but points at group **3**, which doesn't exist (the group is **id 1**).
> target must be the real `modx_documentgroup_names.id`.

**d. Put each context's protected home into the Protected group:**
```
modx_document_groups: document=<that context's home id>, document_group=<Protected group id>
```

That's it. Anonymous can enter and see everything *not* in Protected; the home page is in
Protected, so anonymous hits `unauthorized_page` (→ your login page); a logged-in Member sees it.

---

## 3. Per-context settings (the routing)

Set on each context (`modx_context_setting`), as your doc 15 lists:

| setting | value | role |
|---|---|---|
| `site_start` | the `userHome` resource | where `/` goes |
| `unauthorized_page` | the `unauthorized` resource | where a blocked anonymous user is sent |
| `login_page` / `logout_page` | login / logout resources | the Login snippet's redirects |
| `error_page` | error resource | 404 |
| `protected_page` | home | your convenience pointer to the members-home |

**`userHome` snippet** is the router: on `/`, if the user is logged in it forwards to
`homePages[currentContext]` (their home in *this* context), else to the public/login page.
That per-context map lives in the user's **extended field**:
```
homePages = { "family": 15, "corporate": 29, "worldview": 43 }
```
One user, one login, correct home in each context — this is the single→multi difference in one line.

---

## 4. The Login snippet rule (your "Watch" note is real)

`[[!Login]]` is **not** an independent widget — it sets request/session globals (auth handling,
redirects). Two on one page fight each other. So:

- **Exactly one** `[[!Login]]`, and only on the **login page** (delivered via a chunk, e.g.
  `Bootstrap.5.Login`, not typed into content).
- **Never** in a normal content document. The migration's `strip_login` already removes
  `[[!Login]]`/`[[!Logout]]` from every migrated page for exactly this reason.
- Your standing rule — "Login snippet lives in chunks + housekeeping only, nowhere else" — is the
  correct invariant. (Grep to enforce: any content resource containing `[[!Login`/`[[!Logout` outside
  the login/logout pages is a violation to clean.)

---

## 5. What menu you see — falls out for free

`topNavBar`/`leftNavBar` call `pdoMenu` with `&checkPermissions=\`list\``. `pdoMenu` then only
emits resources the *current* user is allowed to **list**. Anonymous → protected items vanish from
the nav automatically; after login they appear. No separate "menu logic" needed — it's the same
resource-group access, read by the menu.

---

## 6. Static resources — the real fix for your bypass bug

The bug: a static resource is a real file under the webroot, so `https://site/assets/…/file.pdf`
is served by nginx **before MODx ever runs** — access control is skipped. Your sha1sum filenames
are obscurity (unguessable, but a leaked URL still works forever).

Proper fix — **never expose the file path; deliver through a gated snippet:**
1. Store files **outside the webroot** (or block direct `/assets/…` access in nginx).
2. Reference each file by its resource id, not a URL.
3. A `deliverFile` snippet: look up the resource, check the **current user's access** to it
   (same resource-group check as pages), and only then stream the bytes (`readfile` with the right
   `Content-Type`/`Content-Disposition`). Unauthorized → 401.

This makes a file exactly as protected as the page it belongs to, and kills the direct-path
bypass. (This is also the hook for the future "files in object storage / Storage service
`/v1/files/{uuid}/url`" plan — the snippet becomes the single gate, storage backend swappable.)

---

## 7. Single-context → multi-context: the differences, summarized

1. **Context access** must be granted **per context** (§2a/2b) — the one people forget; there's no
   inheritance from `web`.
2. **Resource-group access** is granted **per context** too (§2c has `context_key`).
3. **`homePages`** is a **map keyed by context**, not a single id (§3).
4. All login/logout/unauthorized/site_start settings are **context settings**, set once per context.
5. One `Members` group + one `Protected` group can serve all contexts (shared buckets), *or* you
   split per context if you ever want different membership — the wiring is identical, just more rows.

---

## 8. How this maps to the repo (so it's reproducible, not hand-clicked)

`04-access-control.py` already does the shape of this but with the two bugs above (missing
per-context `modx_access_context` rows for family/corporate/worldview; resource group id 3 vs 1).
A proper `04` would: ensure the `Members` user group + a real `Protected` resource group (capture
its id, don't hardcode 3), then for each context write rows (a)(b)(c)(d) above and set `homePages`.
Then this whole thing is one script run, re-runnable, no manager clicking.
