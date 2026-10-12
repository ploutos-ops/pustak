# Patches to third-party extras

Each file is a unified diff against the installed extra, relative to the MODx docroot. None is
applied by any verb: an extra's files are part of the docroot, placed at install and upgrade.

## pdotools-3.0.3-pl2-null-total.patch (2026-09-14)

`CoreTools::checkPermissions()` decrements `$total` for every row the reader may not list.
`$total` is `getPlaceholder(totalVar)`, which is `null` when that placeholder was never set, and
PHP 8.3+ warns on `null--` ("Decrement on type null has no effect"). The decrement did nothing
before and does nothing now; the guard only stops the warning. Measured on the bench with the
full suite: 18 warnings unfixed, 0 fixed; three signed-in pages render identical DOMs either way.
The bench error log held about 900 of these, which buries real errors.

Upstream pdoTools 3.0.3-pl2 is the latest release and still carries the line.

    patch -p1 -d <docroot> < setup/patches/pdotools-3.0.3-pl2-null-total.patch
