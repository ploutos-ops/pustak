#!/bin/bash
# plugin-events-declared — every plugin declares its events where a MACHINE can read them,
# and that declaration cannot drift from the prose beside it.
#
# THE DEFECT THIS EXISTS FOR. A plugin is not another element row. snippets, chunks and
# templates are one row each; a plugin is a row in `modx_site_plugins` AND rows in
# `modx_site_plugin_events`, and the second is what makes it FIRE. Until 2026-08-18 the only
# statement of which events a plugin binds to was a sentence in a comment —
# `* Event: OnWebPagePrerender.` — which no deploy verb can read. So a verb taught to deploy
# plugins would write the code, write no binding, and report success: the plugin lands, never
# fires, and the deploy is green over an estate wired to nothing. That is a break behind a green deploy, in a
# new place.
#
# TWO SOURCES FOR ONE FACT IS THE OTHER FAILURE, so this test refuses to let them disagree:
# the sidecar is checked AGAINST the prose, not merely for existence. Change one and this
# goes red until you change both — which is the point, because the prose is what a human
# reads and the sidecar is what the verb reads.
set -u
HERE="$(dirname "$(readlink -f "$0")")"
P="$HERE/../common/plugins"
OKS=0; BADS=0
ok()   { OKS=$((OKS+1)); echo "  ok    $1"; }
bad()  { BADS=$((BADS+1)); echo "  FAIL  $1"; [ -n "${2:-}" ] && echo "        $2"; }

prose_events() {  # the events as the comment states them, one per line, normalised
  grep -m1 -oE '^[[:space:]]*\*[[:space:]]*Events?:[[:space:]]*.*' "$1" \
    | sed -E 's/^[[:space:]]*\*[[:space:]]*Events?:[[:space:]]*//; s/\.[[:space:]]*$//; s/,[[:space:]]*/\n/g' \
    | sed '/^$/d' | sort
}

shopt -s nullglob
plugins=("$P"/*)
found=0
for f in "${plugins[@]}"; do
  case "$f" in *.events) continue;; esac
  found=$((found+1))
  name="$(basename "$f")"
  if [ ! -f "$f.events" ]; then
    bad "$name declares its events for the verb" "no $name.events beside it"
    continue
  fi
  want="$(prose_events "$f")"
  got="$(sed '/^$/d' "$f.events" | sort)"
  if [ -z "$want" ]; then
    bad "$name's comment states its event(s)" "no '* Event:' line to check the sidecar against"
  elif [ "$want" = "$got" ]; then
    ok "$name: sidecar and comment agree ($(echo $got | tr '\n' ' '))"
  else
    bad "$name: sidecar and comment DISAGREE" "comment: $(echo $want|tr '\n' ' ')  sidecar: $(echo $got|tr '\n' ' ')"
  fi
done

# A DECLARATION THAT OUTLIVES ITS PLUGIN IS A LIE IN THE OTHER DIRECTION — the same property
# vault-bypass.py's PENDING map has, and for the same reason: a stale entry reads as covered.
for s in "$P"/*.events; do
  base="${s%.events}"
  [ -f "$base" ] || bad "$(basename "$s") has a plugin" "declares events for a plugin that is not there"
done

# And the walk itself must not silently cover nothing.
[ "$found" -gt 0 ] || bad "the plugins directory was walked" "no plugins found under $P — a green run over an empty set"

echo
echo "$OKS passed, $BADS failed"
[ "$BADS" = 0 ]
