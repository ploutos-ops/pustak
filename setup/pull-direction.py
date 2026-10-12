#!/usr/bin/env python3
"""pull-direction.py — after a pull FROM production, say which way each difference points.

    python3 setup/pull-direction.py                    this repo (or --repo PATH)
    python3 setup/pull-direction.py --repo ../modx-estate

Exit 0 — every difference is content production has and git has never had. Safe to commit.
Exit 1 — at least one file is a file GIT IS AHEAD ON. Committing it would REVERT work.
Exit 2 — could not tell (not a git tree, git unavailable).

WHY THIS EXISTS, AND IT IS NOT HYPOTHETICAL. `restore_modx_dev` regenerates the seat and the element
export from a live production dump, and the differences it leaves point in BOTH directions at once.
On 2026-09-12, one run produced page 1584 — content created in the manager on production that git
had never seen, which must be committed — and three element bodies that were production's OLDER
copies, which would have DELETED the sign-in fix that names the provider it actually goes to
and today's restyled card. Both in the same `git status`. The only thing standing between
the second set and a silent revert was a person reading a diff, twice in one day.

THE TEST IS THE FILE'S OWN HISTORY, WHICH IS THE ONE SOURCE THAT CANNOT BE MISSING. There is no
deploy ledger to consult and no timestamp to trust. But if what production just handed back is
byte-identical to something this file USED TO BE, then git moved on and the ground did not — git is
ahead, and the pull is a revert. If it matches no version this file has ever had, production carries
something git has never carried, and that is new content arriving the way the owner's design says new
content arrives: born in the manager, pulled into git, edited there after.

WHAT IT DELIBERATELY DOES NOT DO. It never edits, never commits, never discards. A tool that
"helpfully" reverted the working tree would be one more writer of the thing it is protecting; the
reader decides, and the reader gets told which decision each file needs.
"""

import argparse
import os
import subprocess
import sys


def git(repo, *args, check=True):
    r = subprocess.run(['git', '-C', repo, *args], capture_output=True, text=True)
    if check and r.returncode != 0:
        raise RuntimeError(f"git {' '.join(args)}: {r.stderr.strip()}")
    return r.stdout


def changed_paths(repo):
    """Tracked files that differ from HEAD, plus untracked ones. Porcelain, so renames are plain."""
    out = git(repo, 'status', '--porcelain', '-uall')
    paths = []
    for line in out.splitlines():
        if not line.strip():
            continue
        code, _, rest = line[:2], line[2], line[3:]
        path = rest.split(' -> ')[-1].strip().strip('"')
        paths.append((code.strip(), path))
    return paths


def worktree_blob(repo, path):
    full = os.path.join(repo, path)
    if not os.path.isfile(full):
        return None
    return git(repo, 'hash-object', '--', full).strip()


def historical_blobs(repo, path):
    """Every blob this path has ever had, newest first, with the commit that carried it."""
    commits = git(repo, 'log', '--format=%H', '--', path).split()
    seen = []
    for c in commits:
        r = subprocess.run(['git', '-C', repo, 'rev-parse', f'{c}:{path}'],
                           capture_output=True, text=True)
        if r.returncode == 0:
            blob = r.stdout.strip()
            if not seen or seen[-1][1] != blob:
                seen.append((c, blob))
    return seen


def subject(repo, commit):
    return git(repo, 'log', '-1', '--format=%s', commit).strip()


def classify(repo, path):
    """('behind', commit, subject) | ('new',) | ('gone',) — what this difference is."""
    blob = worktree_blob(repo, path)
    if blob is None:
        return ('gone',)
    head = subprocess.run(['git', '-C', repo, 'rev-parse', f'HEAD:{path}'],
                          capture_output=True, text=True)
    if head.returncode == 0 and head.stdout.strip() == blob:
        return ('same',)
    history = historical_blobs(repo, path)
    for i, (commit, hist) in enumerate(history):
        if hist == blob:
            # THE USEFUL SENTENCE IS WHAT WOULD BE LOST, NOT WHERE THE OLD BYTES CAME FROM. The
            # first version of this named the commit that CARRIED the matching blob — provenance,
            # which the reader does not need and cannot act on. What they need is the work a commit
            # of this file would delete: every commit NEWER than the match that touched this path.
            # Found by the drill expecting the other sentence and being right to.
            reverts = [(c, subject(repo, c)) for c, _ in history[:i]]
            return ('behind', commit, reverts)
    return ('new',)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--repo', default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    ap.add_argument('--quiet', action='store_true')
    args = ap.parse_args()
    repo = os.path.abspath(args.repo)

    if not os.path.isdir(os.path.join(repo, '.git')):
        print(f"pull-direction: {repo} is not a git checkout — nothing can be said about direction "
              f"without the file's own history.", file=sys.stderr)
        return 2

    paths = changed_paths(repo)
    if not paths:
        if not args.quiet:
            print(f'pull-direction: {os.path.basename(repo)} has no differences — nothing to decide.')
        return 0

    behind, new, other = [], [], []
    for code, path in paths:
        try:
            verdict = classify(repo, path)
        except RuntimeError as e:
            other.append((path, f'could not classify: {e}'))
            continue
        if verdict[0] == 'behind':
            behind.append((path, verdict[1], verdict[2]))
        elif verdict[0] == 'new':
            new.append(path)
        elif verdict[0] == 'gone':
            other.append((path, 'deleted in the working tree'))

    if new and not args.quiet:
        print('CONTENT GIT HAS NEVER HAD — production is ahead, commit these:\n')
        for p in new:
            print(f'  {p}')
        print()

    if behind:
        print('GIT IS AHEAD ON THESE — DO NOT COMMIT THEM. Committing reverts the work named:\n',
              file=sys.stderr)
        for p, commit, reverts in behind:
            print(f'  {p}', file=sys.stderr)
            for c, subj in reverts:
                print(f'      reverts {c[:9]}  {subj}', file=sys.stderr)
            print(f'      (the ground is on {commit[:9]}, which is what this content matches)',
                  file=sys.stderr)
        print('\n  These are production\'s OLDER copies: the ground has not received that commit\n'
              '  yet. Discard them (git checkout -- <path>) and deploy, or the pull deletes work\n'
              '  nobody has seen fail.', file=sys.stderr)

    for p, why in other:
        print(f'  {p}: {why}', file=sys.stderr)

    if not args.quiet and not behind:
        print(f'pull-direction: {len(new)} new, none behind — safe to commit.')
    return 1 if behind else 0


if __name__ == '__main__':
    sys.exit(main())
