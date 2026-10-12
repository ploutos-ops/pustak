// claude.js — page helpers for the claude/skunkworks surface. Loaded on every page via the
// Bootstrap.5.Javascript chunk ([[$claude.js]]). Call a function from a resource's initjs
// where you want it (same wiring as kuber.js).

// Make every `td.gitlink` a clickable cell: opens its document on gitea in one shared "git"
// tab, pointer cursor, cell highlights on hover. Call gitLinks() from the page's initjs.
function gitLinks() {
  for (const cell of document.querySelectorAll('td.gitlink')) {
    // estate-link: the estate table's own pointer and hover tint. The CDN rule this used,
    // `.table > tbody > tr > td.gitlink:hover`, needs Bootstrap's .table, which Library's tables
    // stopped wearing on 2026-10-03 — the tint had been dead since (the owner noticed, 2026-10-04).
    cell.classList.add('estate-link');
    cell.addEventListener('click', () => window.open(cell.dataset.url, 'git'));
  }
}
