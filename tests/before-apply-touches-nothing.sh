#!/usr/bin/env bash
# before-apply-touches-nothing.sh — the suites a DEPLOY runs reach nothing but this checkout.
#
# `tests/run-all.sh --before-apply` is what a deploy verb runs on the ground it deploys to, before it
# applies, and on production that ground holds production's schema. The list's
# promise is that none of its suites reaches a database, the network or a docroot. A comment cannot
# keep that promise; this does, on every bench run, by running the whole list with:
#   * no network       — a new network namespace holding only a loopback that is down;
#   * no database      — /run/mysqld (the socket MariaDB answers on here) hidden under an empty tmpfs;
#   * no ground        — the environment emptied by --before-apply itself.
# A suite that needed any of the three refuses or fails, and the list goes red here.
#
# It also holds the list to the bench list: a before-apply suite that is not in run-all.sh's
# `suites` would be a check the bench never runs.
#
# Exit 2 where this machine cannot build the sandbox (no unprivileged user namespaces): that is a
# statement about the machine, and it is not a pass.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 2
REPO=$(pwd)

pass=0; fail=0
check() { if [ "$1" = 1 ]; then pass=$((pass+1)); echo "  ok    $2"
          else fail=$((fail+1)); echo "  FAIL  $2"; [ -n "${3:-}" ] && echo "        $3"; fi; }

if ! unshare -rmn true 2>/dev/null; then
  echo "REFUSING: this machine cannot create an unprivileged user+mount+network namespace"
  echo "          (unshare -rmn), so the sandbox the claim needs cannot be built here."
  echo "          This is a statement about the machine, not about the tree. NOT A PASS."
  exit 2
fi

# 1 — every before-apply suite is also a bench suite
mapfile -t ba < <(bash tests/run-all.sh --before-apply-list | grep -oE 'tests/[A-Za-z0-9._-]+')
check "$([ "${#ba[@]}" -gt 0 ] && echo 1 || echo 0)" "the before-apply list is not empty (${#ba[@]} suites)"
bench=$(sed -n '/^suites=(/,/^)/p' tests/run-all.sh)
missing=""
for t in "${ba[@]}"; do grep -qF "$t" <<<"$bench" || missing="$missing $t"; done
check "$([ -z "$missing" ] && echo 1 || echo 0)" "every before-apply suite is in the bench list" "absent:$missing"

# 2 — the list, run as a deploy runs it, inside the sandbox
db_dir=/run/mysqld
out=$(unshare -rmn bash -c '
  [ -d "$1" ] && mount -t tmpfs none "$1"
  cd "$2" && bash tests/run-all.sh --before-apply' _ "$db_dir" "$REPO" 2>&1); rc=$?
echo "$out" | sed 's/^/    /' | tail -"$(( ${#ba[@]} + 4 ))"
check "$([ "$rc" = 0 ] && echo 1 || echo 0)" \
  "run-all.sh --before-apply is green with no network, no database socket and an empty environment" \
  "exit $rc — a suite above needs something a deploy must not give it"

# 3 — and the sandbox is real: inside it, the database socket and the network are gone
probe=$(unshare -rmn bash -c '
  [ -d "$1" ] && mount -t tmpfs none "$1"
  [ -S "$1/mysqld.sock" ] && echo SOCKET
  (exec 3<>/dev/tcp/1.1.1.1/53) 2>/dev/null && echo NETWORK
  echo done' _ "$db_dir" 2>&1)
check "$([ "$probe" = done ] && echo 1 || echo 0)" \
  "inside the sandbox no database socket is visible and no outside address answers" "$probe"

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
