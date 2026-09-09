# Operations Runbook

Day-two operations for the SEO Operations & Reporting Platform. Deployment
lives in `production-deployment.md`, backups in `backup-restore.md`.

## Daily / routine

- `php artisan app:production-check --production` after any server change.
- Watch `storage/logs/laravel-<date>.log` (`LOG_CHANNEL=daily`,
  `LOG_DAILY_DAYS` retention) and `storage/logs/queue-worker.log`.
- `php artisan queue:failed` should stay empty; `queue:retry <id>` after
  the cause is fixed, `queue:flush` only for jobs that must not rerun.
- Disk: `storage/app/private/reports` grows with every finalized report
  version; `imports/` is pruned weekly by `seo:prune-import-files`.

## Retention rules

| Data | Rule |
|---|---|
| Report revisions, audit events, final reports | never pruned; no command deletes them |
| Import batches and row issues | kept indefinitely; only the raw CSV file is removed after `IMPORT_FILE_RETENTION_DAYS` (default 90) |
| Legacy migration runs, issues, ledger | kept indefinitely (nothing stored on disk) |
| Logs | `LOG_DAILY_DAYS` |
| Backups | `BACKUP_RETENTION_DAYS` locally, longer off-machine |

## Error recovery

**Failed migration during deploy** — the site is in maintenance mode.
Read the error; a failed migration in MySQL leaves earlier statements of
that migration applied (DDL is not transactional). Fix forward when the
cause is obvious (missing privilege, disk full), otherwise restore the
pre-deploy database backup and roll the code back
(`production-deployment.md` § Rollback). Never `migrate:fresh`.

**Failed PDF generation** — finalization is atomic: when Chromium fails
the report stays *Ready for review*, the month stays unlocked and nothing
is written. The log entry `Chromium did not produce a PDF` carries the
exit code and stderr. Check `CHROMIUM_PATH`, that the binary is executable
by the PHP user, `CHROMIUM_TIMEOUT`, free space in `storage/app/tmp`, and
the sandbox note in the deployment doc. Retry finalization from the report
editor. A missing PDF file for an already-final report is reported as a
plain 404 to users; restore the file from backup (path in
`monthly_reports.generated_pdf_path` / `monthly_report_revisions`).

**Queue failure** — `systemctl status seo-queue`, then the worker log.
Restart with `systemctl restart seo-queue`; after code changes always
`php artisan queue:restart`. Jobs waiting over an hour make the preflight
warn.

**Import failure** — imports are atomic per batch: on any failure the
whole batch is rolled back and the batch shows *Failed* with row-level
reasons on the Imports → details page (log entry `CSV import failed`).
The user fixes the CSV and uploads again. Locked months are refused by
design.

**Accidental finalization / wrong final report** — do not edit the
database. A Super Admin uses *Unlock for correction* on the final report
(reason mandatory): the final becomes an immutable archived version, the
month reopens, the team corrects the data and the report is finalized
again as the next version. Managers cannot unlock. This is the only
correction path.

**Deactivated user still logged in** — deactivation takes effect on the
user's next request (the panel checks `canAccessPanel` every request and
returns 403); no manual session purge is needed. `php artisan
session:flush`-style cleanup is not required with the database driver.

**Lost APP_KEY** — sessions and any encrypted values are unrecoverable;
restore the `.env` copy from the backup set. Do not generate a new key
unless the old one is truly gone, and then expect all users to log in
again.

## Legacy spreadsheet migration (Milestone 17)

Administrative only; see `legacy-migration.md`. Safeguards in place:
explicit `--dry-run` or `--apply`; the `--actor` must be an active Super
Admin; the source is opened read-only and its SHA-256 checksum recorded;
locked months are skipped as conflicts and never unlocked; reruns are
idempotent through natural identities and the ledger; no current cycle,
package-target snapshot or onboarding task is fabricated for imported
projects. Always: backup → dry run → review issues → apply → rerun to
confirm idempotency.

## Security operations

- Users: only Super Admins manage users and roles; deactivate leavers
  instead of deleting. Executives see only projects they own or belong to.
- Sessions are database-backed, 120 minutes idle by default, secure and
  HttpOnly cookies over HTTPS, SameSite=lax.
- Login is throttled by Filament (5 attempts per minute per email/IP).
- Uploads: CSV only, 5 MB / 5,000 rows, content-sniffed, stored on the
  private disk; report PDFs are never on a public disk (preflight fails
  otherwise).
- Response headers: `X-Content-Type-Options`, `Referrer-Policy`,
  `X-Frame-Options`, `Permissions-Policy` from `config/security.php`. No
  Content-Security-Policy is shipped; add one only after testing it
  against Filament/Livewire on staging. Enable HSTS at the web server once
  HTTPS is stable.
