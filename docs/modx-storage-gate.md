# MODx download gate — protected files from object storage

The problem: a static resource backed by a real file under the webroot is served by nginx
**before MODX runs** — RBAC is skipped, and an obscure sha1 filename is only obscurity (a leaked
URL works forever). For financial documents that is unacceptable. The fix: the bytes live in the
**object store**, not under the webroot, so every fetch must go through MODX (and its RBAC).

## Model (as built)

A protected file is a **native MODX Static Resource** where:

- the native **`content_type`** field is the file's real type (e.g. PDF) — mime, extension, and the
  download filename all come from that one central `modContentType` row (change it once, every file
  that references it follows);
- the native **`content`** field holds the **sha1** of the bytes — the *one and only* copy (no TV,
  no shadow field). A real static resource's `content` is a file path (slashes/dots); a bare 40-hex
  sha1 can't be mistaken for one, so **the shape of `content` is also the "storage-backed" marker**.

Delivery is a **plugin**, `deliverStorageFile`, on the **`OnWebPagePrerender`** event:

- It scopes to `modStaticResource` only (a cheap in-memory `instanceof`, no DB), then checks whether
  `content` is a bare sha1. If not, it does nothing and normal (disk) delivery proceeds.
- If yes, it re-checks `view` (defense in depth), then streams the bytes **server-side** from the
  Storage service **by sha1** (`GET /v1/files/by-sha1/{sha1}/download`), setting Content-Type /
  Content-Disposition / filename from the resource's native content type. The browser never sees a
  storage URL or token.

**RBAC ordering (verified):** core enforces the resource-group / `view` check in the **request**
phase, *before* the resource renders — so the plugin (a render-phase event) only ever runs for a
request that already passed access. A protected storage file returns 401 to anonymous, with **zero
bytes**, exactly like a protected page. One ACL across pages and files.

**Disk and storage coexist.** Only a Static Resource whose `content` is a bare sha1 goes to storage;
path-based static resources are untouched. Migration is per-file: put the sha1 in `content`.

## Why a plugin — not a custom resource class, not a core edit (DO NOT RE-LITIGATE)

Decided 2026-07-17 after building and testing the alternatives. The full rationale + a compatibility
watch live in the plugin header (`common/plugins/deliverStorageFile`). In short:

- **Custom resource class** (the architecturally "pure" seam — override the type's own byte-read):
  MODX 3 makes this unreliable (open bug `modxcms/revolution#14759`), docs are 2.x-stale, and it rides
  MODX's flakiest mechanism. Recorded as the ideal to revisit **if** MODX makes it reliable again.
- **Editing core** (`modStaticResource.php`): a MODX upgrade replaces core files wholesale, so the
  patch dies — silently — on the very next upgrade. The least durable option; rejected.
- **The plugin**: a documented, maintained event + native Static Resource + native content type +
  xPDO methods. Durable, and the deprecation log warns us if any call ever goes stale.

## The upload half — the manager flow (2026-09-06, the owner: "i would not remember curl")

Until 2026-09-06 the only ways bytes reached storage were the migration script and a hand-typed
curl — no flow a person could use. Now the manager IS the flow:

1. **Files → Protected → upload the file.** The `Protected` media source is rooted at
   `core/protected/`, which the webserver denies — an uploaded file is never web-readable, not even
   for a moment.
2. **Create a Static Resource**: media source `Protected`, point it at the file, save.
3. **The save does the rest** — the `storeProtectedFile` plugin (on `OnDocFormSave`) pushes the
   bytes to the Storage service with the ground's own certificate, verifies the door's sha1 against
   its own, swaps `content` to the sha1, sets the real content type when the editor left the HTML
   default, and removes the disk copy. Failures never block the save: the resource keeps its path
   (still RBAC-served, still not web-readable) and the error log says exactly what to fix; the next
   save retries.

The plugin recognises a protected upload by the **source id** in the `storage.protected_source`
setting (written by `setup/20`), never by name — a rename in the manager cannot widen or narrow it.
Disk static resources on any other source are untouched, exactly as before.

**One file from a shell instead:** `python3 store-file.py <file>` — uploads as modx (environment
read off the certificate's own CN, never typed), prints the sha1 that goes in the content field.

## Elements (in this repo)

- `common/plugins/deliverStorageFile` — the download gate (the plugin above). Carries the decision
  record + compatibility watch in its header.
- `common/plugins/storeProtectedFile` — the upload absorb (manager flow above); its header owns the
  behaviour, including why `getOne('ContentType')` must not be used before a `set('content_type')`.
- `setup/13-storage-gate.py` — idempotent: installs the download plugin, binds it, creates the
  `storage.*` settings. A re-run leaves the token alone when `MODX_STORAGE_TOKEN` is unset, but
  resets `storage.base_url` and `storage.environment` to its defaults unless `MODX_STORAGE_BASEURL`
  / `MODX_STORAGE_ENV` name them.
- `setup/20-protected-files.py` — idempotent: the Protected media source (+ its directory), the
  upload plugin, and `storage.protected_source`.
- `store-file.py` (repo root) — one file in, sha1 out.
- `tests/protected-absorb.sh` — the drill: absorb measured five ways, the coexist control, and the
  plugin-disabled red arm (P11). In `run-all.sh`.

## Config (system settings, namespace `storage`)

| setting                    | source / value |
|----------------------------|----------------|
| `storage.base_url`         | Storage service base (default: the storage service's `https://` name on the internal zone — the door authenticates by client certificate, which cannot be presented without TLS; both plugins refuse an http value) |
| `storage.environment`      | storage scope this ground writes to (`setup/13`'s default is `production`; the bench reads `production` too) |
| `storage.protected_source` | id of the Protected media source (written by `setup/20`) |
| `storage.modx_token`       | RETIRED at the door 2026-08-14 — identity is the ground's client certificate from `estate_identity_dir` in `core/config/application.php` |

## Go-live

Deploy the plugins and run `setup/13-storage-gate.py` + `setup/20-protected-files.py` on the
ground; the certificate identity is already there.

This heading read *"(skunkworks never writes prod)"* until 2026-09-12. Who may deploy is owned by
**`workflow.md` → Who may do what** and is not restated here. The two `setup/` scripts are still
infra's for the reason that document gives: a script run on the box is not a committed ref, so no
deploy carries it and no verb undoes it.
