# estate-repo.sh — where the ESTATE repository is. Source it; do not run it.
#
#     . "$(dirname "$0")/estate-repo.sh"      # sets ESTATE_REPO, or refuses
#
# PHASE 3. `seat/` (his documents) and `install/` (the seed and the docroot tar) left
# this repository for the estate repository. This software no longer contains anybody's estate, and
# every script here that used to reach into `$REPO/seat` or `$REPO/install` has to be told where
# that estate is instead.
#
# IT IS NOT A SECOND VARIABLE. The estate is already named — `MODX_ESTATE` points at the estate
# file, and the estate repository is the directory that file lives in. A `MODX_ESTATE_REPO` beside
# `MODX_ESTATE` would be two names for one place, free to disagree, which is the defect this whole
# ticket has been unwinding (one context declared in one of two places; one domain map in two).
#
# AND IT HAS NO DEFAULT, for the reason `domains.db_config()` has none: a default decides what
# SILENCE means. A script that fell back to a sibling `../modx-estate` would, on a box where that
# directory does not exist, write a seed nobody deploys or read documents nobody edited, and report
# success. `deploy_modx_documents` has lied in both directions before and infra found
# this exact shape again today — a `pull.py` defaulting to the software repo would have regenerated
# the tree into the repository that no longer holds the documents.

if [ -z "${MODX_ESTATE:-}" ]; then
  echo "REFUSING: MODX_ESTATE is not set." >&2
  echo "          seat/ and install/ live in the estate repository since the estate split; this script" >&2
  echo "          needs to know which estate it is operating on. There is no default — a guessed" >&2
  echo "          path turns an unset variable into a silent write to the wrong tree." >&2
  exit 2
fi
if [ ! -f "$MODX_ESTATE" ]; then
  echo "REFUSING: MODX_ESTATE names '$MODX_ESTATE', which does not exist." >&2
  exit 2
fi
ESTATE_REPO=$(cd "$(dirname "$MODX_ESTATE")" && pwd)
for _d in seat install; do
  [ -d "$ESTATE_REPO/$_d" ] || {
    echo "REFUSING: $ESTATE_REPO has no $_d/ — MODX_ESTATE points into something that is not the" >&2
    echo "          estate repository (expected the estate repository, which holds estate.json," >&2
    echo "          seat/ and install/)." >&2
    exit 2; }
done
unset _d
