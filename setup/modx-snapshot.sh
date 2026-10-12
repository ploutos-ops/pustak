#!/usr/bin/env bash
# modx-snapshot.sh <label> — capture a before/after IMAGE of the MODx install.
#
# Two stores fully describe a MODx install: the filesystem and the database.
# This captures both under /srv/local/modx-snapshots/<label>/ (DATA area — off git,
# off home). Diffing two labels shows exactly what a step produced.
#
#   files.sha256  — every file under the docroot (runtime noise excluded), hashed
#   modx.sql      — full modx DB dump, diff-friendly (row-per-line, stable order,
#                   no timestamps). This IS the seed dump if the label is post-install.
#
# Runs wherever the docroot is: MODX_ROOT names it, and the caller needs sudo there
# (the docroot is www-data). The snapshot area stays local to that machine.
set -euo pipefail
LABEL="${1:?usage: modx-snapshot.sh <label>   e.g. 00-baseline, 10-post-install}"
# The docroot is the ground's, not this file's: /srv/local/modx was the dev bench and it stopped
# existing when the bench became staging (2026-08-14). A snapshot of a guessed docroot is an
# image of nothing, and this tool exists to be diffed against another run.
ROOT="${MODX_ROOT:?MODX_ROOT not set — name the docroot to snapshot; there is no default since the
       bench became staging}"
OUT=/srv/local/modx-snapshots/$LABEL
sudo mkdir -p "$OUT"

# --- filesystem image (exclude cache/logs/sessions — runtime noise, not install output) ---
sudo bash -c "cd '$ROOT' && find . -type f \
    -not -path './core/cache/*' \
    -not -path './core/components/*/cache/*' \
    -not -path './logs/*' -not -name '*.log' \
    -not -path './core/packages/*' \
  | LC_ALL=C sort | xargs -d '\n' sha256sum" | sudo tee "$OUT/files.sha256" >/dev/null

# --- database image (schema + data; deterministic ordering for clean diffs) ---
sudo bash -c "mysqldump --no-tablespaces --skip-dump-date --single-transaction \
    --skip-extended-insert --order-by-primary \
    -u \"$MODX_DB_USER\" -p\"$MODX_DB_PASS\" \"${MODX_DB_NAME:-modx}\" > '$OUT/modx.sql'"

FILES=$(wc -l < "$OUT/files.sha256")
TABLES=$(sudo grep -c '^CREATE TABLE' "$OUT/modx.sql" || true)
echo "snapshot '$LABEL': $FILES files | $TABLES tables | db $(sudo du -h "$OUT/modx.sql" | cut -f1)"
echo "  -> $OUT"
