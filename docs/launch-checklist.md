# Launch Checklist

Tick every box on the production server before go-live. Use TEST or
staging data for the smoke test: never finalize a real client month while
verifying.

## Infrastructure
- [ ] Server meets `production-deployment.md` § 1 (PHP 8.2+, extensions, MySQL 8.0+/MariaDB 10.4+, Chromium, HTTPS certificate)
- [ ] Web server document root is `<app-dir>/public`; `.env`, `storage/`, `vendor/` are not reachable over HTTP
- [ ] HTTPS works; HTTP redirects to HTTPS
- [ ] Time zone / NTP correct (UTC timestamps in the database)

## Environment
- [ ] `.env` created from `.env.example`; no secrets committed anywhere
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`
- [ ] `APP_KEY` generated once with `php artisan key:generate` (new install only) and included in the backup set
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, `TRUSTED_PROXIES` set only if a proxy terminates TLS
- [ ] `LOG_CHANNEL=daily`, `LOG_LEVEL` warning/info, `LOG_DAILY_DAYS` set
- [ ] `php artisan optimize` run; `app:production-check --production` exits 0

## Database
- [ ] Dedicated database and user (no root), utf8mb4
- [ ] `php artisan migrate --force` applied; preflight shows no pending migrations and all required tables
- [ ] `php artisan db:seed --force` run once (roles only)
- [ ] Nightly dump job configured and one restore rehearsed on staging (`backup-restore.md`)

## Storage
- [ ] `storage/` and `bootstrap/cache/` writable by the PHP user, not world-readable
- [ ] `REPORT_PDF_DISK` and `IMPORT_DISK` are private disks (preflight passes "Report PDFs private")
- [ ] `storage/app/private` included in the file backup
- [ ] `IMPORT_FILE_RETENTION_DAYS` agreed

## Chromium
- [ ] `CHROMIUM_PATH` set to an installed Chrome/Chromium/Edge; preflight prints its version
- [ ] `CHROMIUM_FLAGS` empty (no `--no-sandbox`) unless running in an isolated container by decision
- [ ] `storage/app/tmp` writable; a test report finalizes and its PDF downloads

## Queue
- [ ] `QUEUE_CONNECTION=database`; `jobs` and `failed_jobs` tables exist
- [ ] Queue worker service enabled and running as the deploy user (`systemctl status seo-queue`)
- [ ] `php artisan queue:restart` is part of the deployment sequence

## Scheduler
- [ ] Cron entry `* * * * * cd <app-dir> && <php> artisan schedule:run` installed for the deploy user
- [ ] `php artisan schedule:list` shows `seo:ensure-monthly-cycles` and `seo:prune-import-files`

## Backups
- [ ] `scripts/backup.sh` runs from cron with `BACKUP_DIR` on a separate volume or path
- [ ] Off-machine copy scheduled; retention windows agreed
- [ ] Pre-deploy backup step written into the deployment procedure

## Security
- [ ] No demo/default users; Super Admin created with `app:create-super-admin` and a strong password
- [ ] All staff accounts created by a Super Admin with the correct role; leavers deactivated
- [ ] Security headers visible on responses (`curl -I https://<host>/admin/login`)
- [ ] `/up` health route returns 200 and exposes nothing else
- [ ] Composer audit clean at deployment time (`composer audit`)

## Users / Roles
- [ ] Super Admin(s) confirmed
- [ ] SEO Managers created
- [ ] SEO Executives created and assigned as owner or team member of their projects only

## Smoke test (test/staging data)
- [ ] 1. Login as Super Admin
- [ ] 2. Login as Manager
- [ ] 3. Login as an assigned Executive
- [ ] 4. Executive cannot open an unrelated Project (URL gives 404), its reports, imports or PDFs
- [ ] 5. Open a Client and a Project
- [ ] 6. Ensure the current MonthlyCycle (Monthly cycles page or `seo:ensure-monthly-cycles`)
- [ ] 7. Create and update a Task
- [ ] 8. Record a Page Optimization
- [ ] 9. Enter a Ranking (bulk entry)
- [ ] 10. Add a Backlink
- [ ] 11. Add and publish a Content item
- [ ] 12. Enter Analytics (GSC, GA4, authority)
- [ ] 13. Add a MonthlyNote
- [ ] 14. Create the draft report
- [ ] 15. Readiness shows the missing sections correctly
- [ ] 16. Preview the report
- [ ] 17. Mark Ready for review
- [ ] 18. Finalize (Manager)
- [ ] 19. PDF downloads
- [ ] 20. The cycle is locked; edits are refused
- [ ] 21. Super Admin unlock with reason works; refinalize gives version 2; version 1 stays downloadable
- [ ] 22. CSV validation preview shows errors; a valid CSV imports; history shows the batch
- [ ] 23. Dashboard, Reports and Team Workload load with the test data

## Legacy data migration
- [ ] Office workbook exported into the documented layout (`legacy-migration.md`)
- [ ] Mapping file prepared (clients, projects, users, packages, `ranking_year`)
- [ ] Backup taken; `seo:migrate-legacy … --dry-run` reviewed with the team
- [ ] `--apply` run, then a second `--apply` confirmed idempotent
- [ ] Spot-check migrated rankings, backlinks and analytics in the UI

## Go-live
- [ ] Deployment sequence executed (`production-deployment.md` § 9)
- [ ] `php artisan up`; login verified over HTTPS
- [ ] Team informed of the URL and the spreadsheet freeze date

## Post-launch verification (first week)
- [ ] Daily: logs, `queue:failed`, disk usage
- [ ] First scheduled run: cycles created on the 1st at 00:05
- [ ] First nightly backup present and restorable
- [ ] First real report finalized and PDF checked by a Manager
