#!/usr/bin/env python3
"""Render a development bench's core/config/application.php (docs/apply-environment.md).

Infra's modx-web role renders this file for the installations it runs; a bench on a dev box has no
writer, so this is it. It keeps every key the file already holds and replaces two:
`environment` ('development') and `settings`, which setup/apply-environment.php then writes.

    MODX_ROOT=<bench docroot> MODX_ESTATE=<modx-estate>/estate.json \\
        python3 setup/bench-application.py --profile local|bench [--dry-run]

What it declares, and from where:
  system   estate_env, error_log_filepath (from MODX_ROOT), new_folder/new_file_permissions
           (law 2.5: the bench is every agent's), kuber.base_url (the bench's kuber, :8080) and kuber.reader_url (+ kuber-app's reader route),
           estate_env_color when ESTATE_ENV_COLOR is set (infra's colour row for the environment);
  contexts http_host, site_url, base_url from domains.expected_settings(profile), and
           session_name (one cookie per context on localhost).
Not declared, so not managed on a bench yet: sso.* and storage.* — their bench values are set by
setup/12 at restore and are not decided here.
"""
import argparse
import json
import os
import re
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import domains  # noqa: E402

BENCH_KUBER = 'http://localhost:8080'


def kuber_reader_path():
    """kuber's reader route, from kuber-app's own manifest (identity.routes[capability=reader]) at
    origin/main of the kuber-app checkout beside this one (../kuber). kuber owns the path; MODx consumes
    it by capability, so it is read, never typed. Refuses rather than guess."""
    repo = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), '..', 'kuber')
    subprocess.run(['git', '-C', repo, 'fetch', '-q', 'origin', 'main'], capture_output=True)
    shown = subprocess.run(['git', '-C', repo, 'show', 'origin/main:service.json'], capture_output=True, text=True)
    if shown.returncode:
        raise SystemExit(f"REFUSED: cannot read kuber-app's service.json at origin/main in '{repo}' "
                         f"Nothing was written.")
    paths = [r.get('path') for r in json.loads(shown.stdout).get('identity', {}).get('routes', [])
             if r.get('capability') == 'reader']
    if len(paths) != 1 or not re.fullmatch(r'/[A-Za-z0-9._/-]+', paths[0] or ''):
        raise SystemExit(f"REFUSED: kuber-app declares {len(paths)} reader route(s), not one. Nothing was written.")
    return paths[0]


def php_str(s):
    return "'" + str(s).replace('\\', '\\\\').replace("'", "\\'") + "'"


def php(v, ind=1):
    if isinstance(v, dict):
        pad = '    ' * ind
        rows = ''.join(f"{pad}{php_str(k)} => {php(x, ind + 1)},\n" for k, x in v.items())
        return '[\n' + rows + '    ' * (ind - 1) + ']'
    if v is None:
        return 'null'
    if isinstance(v, bool):
        return 'true' if v else 'false'
    return php_str(v)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--profile', required=True, choices=['local', 'bench'])
    ap.add_argument('--dry-run', action='store_true')
    a = ap.parse_args()
    root = (os.environ.get('MODX_ROOT') or '').rstrip('/')
    if not root or not os.path.isfile(f'{root}/config.core.php'):
        raise SystemExit(f"REFUSED: no config.core.php under '{root or '(MODX_ROOT unset)'}'. Nothing was written.")
    path = f'{root}/core/config/application.php'

    current = {}
    if os.path.isfile(path):
        out = subprocess.run(['php', '-r', 'echo json_encode(include $argv[1]);', path],
                             capture_output=True, text=True, check=True).stdout
        current = json.loads(out) or {}
        if not isinstance(current, dict):
            raise SystemExit(f'REFUSED: {path} does not return an array. Nothing was written.')

    system = {
        'estate_env': 'development',
        'error_log_filepath': f'{root}/core/logs/',
        'new_folder_permissions': '0775',
        'new_file_permissions': '0664',
        'kuber.base_url': BENCH_KUBER,
        'kuber.reader_url': BENCH_KUBER + kuber_reader_path(),
    }
    colour = (os.environ.get('ESTATE_ENV_COLOR') or '').strip()
    if colour:
        system['estate_env_color'] = colour
    contexts = {}
    for ctx, pairs in domains.expected_settings(a.profile).items():
        contexts[ctx] = dict(pairs, session_name='PHPSESSID' + re.sub(r'[^A-Za-z0-9]', '', ctx))

    doc = {k: v for k, v in current.items() if k not in ('environment', 'settings')}
    doc['environment'] = 'development'
    doc['settings'] = {'system': system, 'contexts': contexts}
    text = ('<?php\n// Rendered by modx setup/bench-application.py. The keys other than environment\n'
            '// and settings were in the file before and are kept as they were.\nreturn ' + php(doc) + ';\n')
    if a.dry_run:
        print(text.replace(str(current.get('whoami', '\0')), '(not printed)'))
        return 0
    with open(path + '.new', 'w') as f:
        f.write(text)
    subprocess.run(['php', '-l', path + '.new'], check=True, capture_output=True)
    os.replace(path + '.new', path)
    print(f"bench-application: wrote {path}: environment development, "
          f"{len(system)} system and {sum(map(len, contexts.values()))} context value(s), "
          f"kept {', '.join(sorted(k for k in doc if k not in ('environment', 'settings'))) or 'nothing'}")
    return 0


if __name__ == '__main__':
    sys.exit(main())
