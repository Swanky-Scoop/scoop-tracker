# Prod -> local DB import

Pulls a WP Migrate DB export (`.sql.gz`) from production into the local
"swank-tracker" Local by Flywheel site. Local-only tooling - never deployed
(see `deploy.yml`'s rsync excludes for `data-exports/`).

## Usage

1. In wp-admin on production, use WP Migrate DB to export/download a `.sql.gz`.
2. Run:
   ```powershell
   .\data-exports\scripts\import-prod-db.ps1 -DumpPath 'C:\Users\gusre\Downloads\swanktracker-migrate-....sql.gz'
   ```
   Or drop the file into `data-exports/` and omit `-DumpPath` - it picks the
   newest `*.sql.gz` there automatically.
3. Reload `https://ops.swanky.local/` (hard-refresh for JS). Login should work
   with your usual local credentials since the import replaces `wp_users`/
   `wp_usermeta` with production's.

Flags: `-SkipBackup` skips the pre-import backup, `-SkipRewrite` skips the
domain rewrite (leaves content pointing at production).

## What it does

1. Decompresses the export.
2. Backs up the current local DB to `data-exports/backups/` (gitignored) as a
   gzipped `mysqldump`, before touching anything.
3. Imports the export - this is a full `DROP TABLE` + `CREATE TABLE` + data
   replace for every `track_*` table, i.e. the whole local DB gets swapped for
   production's snapshot.
4. Rewrites production's domain(s) to `ops.swanky.local` throughout every
   `track_*` table, serialization-safe (won't corrupt PHP-serialized strings
   in options/postmeta the way a plain text replace would).
5. Prints tub/flavor/options row counts as a sanity check.

## To restore a backup

```powershell
$b = 'data-exports\backups\local-pre-import-<timestamp>.sql.gz'
& 'C:\Program Files\Git\usr\bin\gzip.exe' -dc $b > "$env:TEMP\restore.sql"
& 'C:\Users\gusre\AppData\Roaming\Local\lightning-services\mysql-8.4.0\bin\win64\bin\mysql.exe' `
  -h 127.0.0.1 -P 10004 -u root --password=root local < "$env:TEMP\restore.sql"
```

## Gotchas

- **Wordfence / 2FA lockout.** The export carries production's Wordfence
  (`track_wf*`) and 2FA (`track_wfls_2fa_secrets`) tables. If `/wp-admin/`
  becomes unreachable after an import, deactivate Wordfence locally (rename
  its folder under `wp-content/plugins/` if you can't reach the Plugins
  screen) to clear it.
- **Two stale domains, not one.** Production's `home` option
  (`ops.swanky.ink`) and `siteurl` option (`ops.swankyscoop.net`) currently
  disagree - a leftover from an incomplete domain rename, confirmed straight
  out of the raw dump, not an artifact of this script. The script detects and
  rewrites both automatically; if a future export only has one stale domain
  (or a third), it still Just Works since detection reads the dump itself
  rather than a hardcoded value.
- **`wp_posts.guid` stays stale.** ~530 rows keep production's old domain in
  `guid` after rewrite. This is intentional/expected - WordPress core says
  never to rewrite `guid` after a post is created, since nothing renders from
  it; only feed/uniqueness logic touches it. Every professional migration
  tool (including WP Migrate DB Pro's own recommendation) leaves it alone.
- **A handful of WP All Import job configs don't get rewritten.**
  `track_pmxe_exports`/`track_pmxe_templates` store serialized `WP_Query`
  objects, which can't be reconstructed by a bare CLI script outside a full
  WordPress bootstrap (no autoloader for the class). The script logs and
  skips those specific cells rather than crashing the whole run. Harmless
  unless you're actively re-running an old WP All Import job locally.
- **PHP CLI needs a specific `php.ini`.** Local's default `php.exe` on PATH
  has no `php.ini` loaded (no `mysqli`). The script explicitly points at
  `C:\Users\gusre\AppData\Roaming\Local\run\uBXwpxgAI\conf\php\php.ini` and
  uses the matching PHP 8.1.29 binary (that ini's `extension_dir` is
  version-pinned to 8.1, not the 8.2 binary that's also installed).
- **Decompression uses Git's `gzip.exe`, not .NET's `GZipStream`.**
  `System.IO.Compression.GZipStream` (.NET Framework, which Windows
  PowerShell 5.1 uses) silently stops after the *first* member of a
  multi-member gzip stream and reports no error. WP Migrate DB streams its
  export in chunks, producing exactly that - a naive `GZipStream` decompress
  reads ~3KB of a multi-MB file with zero indication anything's wrong. Cost
  real debugging time to catch; don't revert this to `GZipStream`.
- **All of the above paths are specific to this machine's Local install**
  (site id `uBXwpxgAI`, MySQL port `10004`). If the Local site ever gets
  recreated, re-derive these from
  `AppData\Roaming\Local\run\<siteId>\conf\mysql\my.cnf` and `sites.json`,
  and update the config block at the top of `import-prod-db.ps1`.
