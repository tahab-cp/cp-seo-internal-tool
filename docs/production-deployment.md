# Production Deployment

Internal office deployment of the SEO Operations & Reporting Platform: one
Linux server (or VM), PHP-FPM behind a web server, one MySQL/MariaDB
database, one queue worker, one cron entry. Nothing else is required.

Placeholders used below: `<app-dir>` (e.g. `/var/www/seo-platform`),
`<php>` (e.g. `/usr/bin/php8.2`), `<deploy-user>` (the account that owns
the code), `<web-group>` (the web server's group, e.g. `www-data`).

## 1. Prerequisites

| Component | Requirement | Why |
|---|---|---|
| PHP | 8.2 or newer (8.2.12 verified) | `composer.json` `php: ^8.2` |
| PHP extensions | pdo_mysql, mbstring, openssl, fileinfo, zip, xml, xmlreader, ctype, json, tokenizer, bcmath, curl | Laravel, MIME sniffing of CSV uploads, OpenSpout XLSX reading |
| Composer | 2.x | dependency install |
| Database | MySQL 8.0+ or MariaDB 10.4+ (MariaDB 10.4.32 verified), InnoDB, utf8mb4 | JSON columns, row locks (`SELECT … FOR UPDATE`), long utf8mb4 indexes |
| Chromium | Google Chrome, Chromium or Microsoft Edge (headless `--print-to-pdf`) | monthly report PDFs |
| Web server | Nginx (example below) or Apache with `mod_rewrite`, HTTPS certificate | front controller `public/index.php` |
| Node.js | not required at runtime | the panel ships its own compiled CSS; no asset build is part of deployment |
| Process manager | systemd (example below) or Supervisor | keeps the queue worker alive |
| Cron | one entry | Laravel scheduler |

Redis, Docker, Node, Elasticsearch or any SaaS service are not needed.

## 2. First installation

```bash
# as <deploy-user>
git clone <repository> <app-dir>
cd <app-dir>
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env
```

Edit `.env` (see section 3), then:

```bash
php artisan key:generate        # ONLY on a brand-new environment without an APP_KEY
php artisan migrate --force     # after the .env is verified
php artisan db:seed --force     # RoleSeeder only: creates the three roles, no users
php artisan app:create-super-admin --name="…" --email=…   # password prompted, never seeded
php artisan optimize            # config + route + view cache
php artisan app:production-check --production
```

Never run `key:generate` on an existing installation: an APP_KEY change
makes encrypted data and every session unreadable. Never run
`migrate:fresh`, `migrate:reset` or `db:wipe` in production.

## 3. Environment (`.env`)

`.env` is git-ignored and must contain no real secrets in any committed
file. Required production values:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<internal-hostname>
APP_KEY=<generated once>

LOG_CHANNEL=daily
LOG_LEVEL=warning        # or info
LOG_DAILY_DAYS=14

DB_CONNECTION=mysql      # or mariadb
DB_HOST=… DB_PORT=3306 DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=…

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
TRUSTED_PROXIES=         # proxy IPs/CIDRs only when a reverse proxy terminates HTTPS

CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

REPORT_PDF_DISK=local    # private disk; "public" is rejected by the preflight
CHROMIUM_PATH=/usr/bin/chromium
CHROMIUM_TIMEOUT=90
CHROMIUM_FLAGS=          # empty; see Chromium section before adding anything

IMPORT_DISK=local
IMPORT_MAX_FILE_BYTES=5242880
IMPORT_MAX_ROWS=5000
IMPORT_FILE_RETENTION_DAYS=90

MAIL_MAILER=log          # mail is not used by V1
```

Config caching is safe: application code reads configuration only through
`config()` (there is no `env()` outside `config/`), and the test suite
guards that.

## 4. File permissions

Code is owned by `<deploy-user>`; only these paths are written at runtime:

```bash
chown -R <deploy-user>:<web-group> storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
chmod -R o-rwx storage        # PDFs and CSV uploads live here: not world-readable
```

Never use `777`. `storage/app/private` (report PDFs under `reports/`,
uploads under `imports/`) is never served by the web server; files reach
users only through authorized application routes.

## 5. Web server (Nginx example)

```nginx
server {
    listen 443 ssl http2;
    server_name <internal-hostname>;
    root <app-dir>/public;
    index index.php;

    ssl_certificate     /etc/ssl/certs/<cert>.pem;
    ssl_certificate_key /etc/ssl/private/<key>.pem;
    client_max_body_size 8m;          # CSV upload limit is 5 MB

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) { deny all; }   # .env and friends
}
server { listen 80; server_name <internal-hostname>; return 301 https://$host$request_uri; }
```

The document root is `public/`; `.env`, `storage/` and `vendor/` are
outside it. HTTPS is required in production (secure cookies, credentials).
With Apache use the shipped `public/.htaccess` and `AllowOverride All`.
When a reverse proxy terminates HTTPS, set `TRUSTED_PROXIES` to that proxy
only, never to `*` unless the app is unreachable except through it.

## 6. Queue worker

The queue is database-backed. V1 has no user-facing queued jobs (PDFs are
rendered synchronously inside finalization), but the worker is part of the
baseline so failed-job handling, future background work and the scheduler
behave as documented.

`/etc/systemd/system/seo-queue.service`:

```ini
[Unit]
Description=SEO platform queue worker
After=network.target mysql.service

[Service]
User=<deploy-user>
Group=<web-group>
WorkingDirectory=<app-dir>
ExecStart=<php> <app-dir>/artisan queue:work --sleep=3 --tries=3 --timeout=180 --max-time=3600
Restart=always
RestartSec=5
StandardOutput=append:<app-dir>/storage/logs/queue-worker.log
StandardError=append:<app-dir>/storage/logs/queue-worker.log

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now seo-queue
sudo systemctl status seo-queue
```

`--max-time=3600` makes the worker exit hourly so systemd restarts it with
fresh code; `php artisan queue:restart` after each deploy does the same
immediately. (Supervisor: `command=<php> <app-dir>/artisan queue:work …`,
`autostart=true`, `autorestart=true`, `user=<deploy-user>`,
`stdout_logfile=<app-dir>/storage/logs/queue-worker.log`.)

## 7. Scheduler

One cron entry for `<deploy-user>`:

```
* * * * * cd <app-dir> && <php> artisan schedule:run >> /dev/null 2>&1
```

Scheduled commands (`php artisan schedule:list`):

| Command | When | Purpose |
|---|---|---|
| `seo:ensure-monthly-cycles` | 1st of the month 00:05 | monthly cycle + target snapshot for every active project |
| `seo:prune-import-files` | Sundays 02:30 | removes uploaded CSV files older than `IMPORT_FILE_RETENTION_DAYS`; history kept |

Both run `withoutOverlapping`. No API sync, no report automation.

## 8. Chromium

- Install Chrome, Chromium or Edge on the server and set `CHROMIUM_PATH`
  explicitly. Auto-detection only covers default install locations.
- PDFs are rendered with `--headless=new --print-to-pdf` in a private
  work directory under `storage/app/tmp/reports/<uuid>` that is deleted
  after each render (even on failure). `CHROMIUM_TIMEOUT` (seconds) bounds
  the render; failures are logged with the exit code and Chromium's
  stderr, never with report content.
- The Chromium sandbox stays ON. `--no-sandbox` is not a default and is
  acceptable only inside an isolated container where the sandbox cannot
  start; add it via `CHROMIUM_FLAGS=--no-sandbox` in that case only. The
  preflight warns when it is set.
- Verify with `php artisan app:production-check` (reads the version) and,
  on a staging copy, `RUN_CHROMIUM_TESTS=1 php artisan test --group=chromium`.

## 9. Deploying a new version

```bash
cd <app-dir>
scripts/backup.sh                         # database + private files (see docs/backup-restore.md)
php artisan down --retry=60
git fetch && git checkout <tag-or-commit>
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force               # only after the backup succeeded
php artisan optimize                      # config:cache, route:cache, view:cache
php artisan queue:restart
php artisan app:production-check --production
php artisan up
```

Keep the site down only between `down` and `up`; backups run before.
Then perform the smoke test in `docs/launch-checklist.md` on a test project.

### Rollback

1. `php artisan down`
2. `git checkout <previous-tag>` and `composer install --no-dev --optimize-autoloader`
3. If the release added migrations, restore the database from the backup
   taken before the deploy (`docs/backup-restore.md`). Do not rely on
   `migrate:rollback` for data-bearing changes.
4. `php artisan optimize && php artisan queue:restart && php artisan up`

## 10. Verification after deploy

- `php artisan app:production-check --production` exits 0.
- `curl -I https://<host>/up` returns 200 (framework health route; no
  configuration is exposed).
- Log in as a Super Admin; the Dashboard, Reports and Imports pages load.
- `php artisan schedule:list` shows the two scheduled commands;
  `systemctl status seo-queue` is active.
