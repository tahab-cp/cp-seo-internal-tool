<?php

namespace App\Console\Commands;

use App\Services\Reports\PdfReportGenerator;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read-only launch preflight: PASS / WARNING / FAIL per check, exit code 1
 * when any check fails. Never prints secrets and never writes domain data
 * (the only writes are throw-away probe files on the storage disks).
 */
class ProductionCheckCommand extends Command
{
    public const PASS = 'PASS';

    public const WARNING = 'WARNING';

    public const FAIL = 'FAIL';

    /**
     * Tables the application cannot run without (roles, sessions, queue,
     * operational core and the reporting/import/migration history).
     */
    public const REQUIRED_TABLES = [
        'users', 'roles', 'role_user', 'sessions', 'cache', 'jobs', 'failed_jobs', 'migrations',
        'clients', 'projects', 'project_user', 'packages', 'package_targets', 'project_target_overrides',
        'monthly_cycles', 'monthly_cycle_targets', 'tasks', 'pages', 'page_optimizations', 'keywords', 'ranking_snapshots',
        'backlinks', 'content_items', 'gsc_monthly_metrics', 'gsc_query_metrics', 'gsc_page_metrics', 'ga4_monthly_metrics',
        'ga4_country_metrics', 'authority_metrics', 'monthly_notes', 'project_report_sections', 'monthly_reports',
        'monthly_report_sections', 'monthly_report_revisions', 'monthly_cycle_audit_events', 'import_batches', 'import_row_errors',
        'legacy_migration_runs', 'legacy_migration_issues', 'legacy_migration_records',
    ];

    public const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'zip', 'xml', 'xmlreader', 'ctype', 'json', 'tokenizer', 'bcmath', 'curl'];

    protected $signature = 'app:production-check
        {--production : Treat production expectations (APP_ENV=production, APP_DEBUG=false, HTTPS) as failures instead of warnings}
        {--skip-chromium : Do not launch Chromium to read its version}';

    protected $description = 'Read-only launch preflight: environment, database, storage, queue, scheduler and Chromium checks';

    /**
     * @var list<array{0: string, 1: string, 2: string}> [status, check, detail]
     */
    protected array $results = [];

    public function handle(): int
    {
        $production = (bool) $this->option('production') || app()->environment('production');
        $this->results = [];

        $this->checkPhp();
        $this->checkEnvironment($production);
        $this->checkDatabase();
        $this->checkStorage();
        $this->checkQueue();
        $this->checkScheduler();
        $this->checkChromium();

        $this->table(['Status', 'Check', 'Detail'], $this->results);

        $failures = count(array_filter($this->results, fn (array $r): bool => $r[0] === self::FAIL));
        $warnings = count(array_filter($this->results, fn (array $r): bool => $r[0] === self::WARNING));

        $this->newLine();

        if ($failures > 0) {
            $this->error(sprintf('%d check(s) FAILED, %d warning(s). Fix the failures before launch.', $failures, $warnings));

            return self::FAILURE;
        }

        $this->info(sprintf('All critical checks passed (%d warning(s)).', $warnings));

        return self::SUCCESS;
    }

    protected function checkPhp(): void
    {
        $this->result(version_compare(PHP_VERSION, '8.2.0', '>=') ? self::PASS : self::FAIL, 'PHP version', PHP_VERSION.' (8.2+ required)');

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn (string $ext): bool => ! extension_loaded($ext)));
        $this->result($missing === [] ? self::PASS : self::FAIL, 'PHP extensions', $missing === [] ? implode(', ', self::REQUIRED_EXTENSIONS) : 'missing: '.implode(', ', $missing));

        $this->result(class_exists(\finfo::class) ? self::PASS : self::FAIL, 'MIME sniffing (fileinfo)', class_exists(\finfo::class) ? 'available' : 'ext-fileinfo missing: CSV uploads cannot be content-checked');
    }

    protected function checkEnvironment(bool $production): void
    {
        $env = (string) config('app.env');
        $this->result($env === 'production' ? self::PASS : ($production ? self::FAIL : self::WARNING), 'APP_ENV', $env.($env === 'production' ? '' : ' (production expected at launch)'));

        $debug = (bool) config('app.debug');
        $this->result(! $debug ? self::PASS : ($production ? self::FAIL : self::WARNING), 'APP_DEBUG', $debug ? 'true: stack traces, SQL and paths would be shown to users' : 'false');

        $key = (string) config('app.key');
        $this->result($key !== '' && (Str::startsWith($key, 'base64:') ? strlen(base64_decode(substr($key, 7), true) ?: '') >= 32 : strlen($key) >= 32) ? self::PASS : self::FAIL, 'APP_KEY', $key === '' ? 'missing: run php artisan key:generate ONCE on a new install (never on an existing one)' : 'set ('.strlen($key).' chars)');

        $url = (string) config('app.url');
        $https = Str::startsWith($url, 'https://');
        $this->result($https ? self::PASS : ($production ? self::FAIL : self::WARNING), 'APP_URL', $url.($https ? '' : ' (HTTPS expected in production)'));

        $secure = config('session.secure');
        $this->result($secure === true || $secure === null && $https ? self::PASS : ($https ? self::WARNING : self::PASS), 'Secure session cookie', $secure === null ? 'SESSION_SECURE_COOKIE unset (secure only when the request is detected as HTTPS)' : ('SESSION_SECURE_COOKIE='.var_export((bool) $secure, true)));
        $this->result(in_array(config('session.same_site'), ['lax', 'strict'], true) ? self::PASS : self::WARNING, 'Session SameSite', (string) config('session.same_site'));
        $this->result((int) config('session.lifetime') > 0 && (int) config('session.lifetime') <= 720 ? self::PASS : self::WARNING, 'Session lifetime', config('session.lifetime').' minutes');
        $this->result(in_array(config('session.driver'), ['database', 'file', 'redis'], true) ? self::PASS : self::WARNING, 'Session driver', (string) config('session.driver'));
        $this->result(config('cache.default') !== 'array' ? self::PASS : self::WARNING, 'Cache store', (string) config('cache.default'));

        $channel = (string) config('logging.default');
        $this->result(in_array($channel, ['daily', 'stack', 'syslog', 'errorlog', 'stderr'], true) ? self::PASS : self::WARNING, 'Log channel', $channel.($channel === 'stack' ? ' ('.implode(',', (array) config('logging.channels.stack.channels')).')' : ''));
        $this->result(config('logging.channels.'.$channel.'.level', config('logging.channels.single.level')) !== 'debug' || ! $production ? self::PASS : self::WARNING, 'Log level', (string) config('logging.channels.'.$channel.'.level', 'n/a'));

        $proxies = (array) config('security.trusted_proxies', []);
        $this->result(self::PASS, 'Trusted proxies', $proxies === [] ? 'none (HTTPS must terminate at this server)' : implode(', ', $proxies));

        $this->result(app()->configurationIsCached() ? self::PASS : self::WARNING, 'Config cache', app()->configurationIsCached() ? 'cached' : 'not cached (run php artisan optimize after deploy)');
        $this->result(app()->routesAreCached() ? self::PASS : self::WARNING, 'Route cache', app()->routesAreCached() ? 'cached' : 'not cached (run php artisan optimize after deploy)');
        $this->result(app()->isDownForMaintenance() ? self::WARNING : self::PASS, 'Maintenance mode', app()->isDownForMaintenance() ? 'application is DOWN' : 'up');
    }

    protected function checkDatabase(): void
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $name = (string) config("database.connections.{$connection}.database");
        $host = (string) config("database.connections.{$connection}.host");

        $this->result(in_array($driver, ['mysql', 'mariadb'], true) ? self::PASS : self::FAIL, 'Database driver', "{$driver} ({$connection}) — MySQL 8.0+ or MariaDB 10.4+ expected");

        try {
            $version = (string) DB::selectOne('select version() as v')->v;
            $this->result(self::PASS, 'Database connection', sprintf('%s@%s: server %s', $name, $host, $version));
        } catch (Throwable $exception) {
            $this->result(self::FAIL, 'Database connection', sprintf('%s@%s: %s', $name, $host, Str::limit($exception->getMessage(), 120)));

            return;
        }

        $this->result(config("database.connections.{$connection}.charset") === 'utf8mb4' ? self::PASS : self::WARNING, 'Database charset', (string) config("database.connections.{$connection}.charset"));

        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');
            $migrator->setConnection($connection);
            $ran = $migrator->getRepository()->getRan();
            $files = array_keys($migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')]));
            $pending = array_values(array_diff($files, $ran));
            $this->result($pending === [] ? self::PASS : self::FAIL, 'Migrations', $pending === [] ? count($ran).' applied, none pending' : count($pending).' pending: '.implode(', ', array_slice($pending, 0, 3)).(count($pending) > 3 ? ', …' : ''));
        } catch (Throwable $exception) {
            $this->result(self::FAIL, 'Migrations', Str::limit($exception->getMessage(), 120));
        }

        $required = (array) (config('security.preflight_required_tables') ?: self::REQUIRED_TABLES);
        $missing = array_values(array_filter($required, fn (string $table): bool => ! Schema::hasTable($table)));
        $this->result($missing === [] ? self::PASS : self::FAIL, 'Required tables', $missing === [] ? count($required).' present' : 'missing: '.implode(', ', $missing));

        if (! in_array('users', $missing, true) && ! in_array('roles', $missing, true) && ! in_array('role_user', $missing, true)) {
            $admins = DB::table('users')->join('role_user', 'role_user.user_id', '=', 'users.id')->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('roles.key', 'super_admin')->where('users.is_active', true)->count();
            $this->result($admins > 0 ? self::PASS : self::WARNING, 'Active Super Admin', $admins > 0 ? "{$admins} active" : 'none: run php artisan app:create-super-admin');
        }
    }

    protected function checkStorage(): void
    {
        foreach (['storage/app' => storage_path('app'), 'storage/logs' => storage_path('logs'), 'storage/framework' => storage_path('framework'), 'bootstrap/cache' => base_path('bootstrap/cache')] as $label => $path) {
            $this->result(is_dir($path) && is_writable($path) ? self::PASS : self::FAIL, "Writable {$label}", is_dir($path) ? (is_writable($path) ? 'writable' : 'NOT writable') : 'missing');
        }

        $tmp = storage_path('app/tmp');
        $this->result(is_writable(dirname($tmp)) || (is_dir($tmp) && is_writable($tmp)) ? self::PASS : self::FAIL, 'PDF work directory', $tmp);

        $this->checkDisk('Report PDF disk', (string) config('reports.pdf_disk'), (string) config('reports.pdf_directory', 'reports'));
        $this->checkDisk('Import disk', (string) config('imports.disk'), (string) config('imports.directory', 'imports'));

        $public = config('filesystems.disks.'.config('reports.pdf_disk').'.visibility') === 'public' || config('reports.pdf_disk') === 'public';
        $this->result($public ? self::FAIL : self::PASS, 'Report PDFs private', $public ? 'the report disk is PUBLIC; final reports would be reachable without login' : 'served only through authorized routes');
        $this->result(config('imports.disk') === 'public' ? self::FAIL : self::PASS, 'Import files private', config('imports.disk') === 'public' ? 'the import disk is PUBLIC' : 'private disk');
    }

    protected function checkDisk(string $label, string $disk, string $directory): void
    {
        $driver = (string) (config("filesystems.disks.{$disk}.driver") ?: 'local');
        $probe = trim($directory, '/').'/.preflight-'.Str::lower(Str::random(8)).'.txt';

        try {
            /** @var Filesystem $storage */
            $storage = Storage::disk($disk);
        } catch (\InvalidArgumentException $exception) {
            $this->result(self::FAIL, $label, "disk [{$disk}] is not configured in config/filesystems.php");

            return;
        }

        try {
            $storage->put($probe, 'preflight '.now()->toIso8601String());
            $ok = $storage->exists($probe) && str_starts_with((string) $storage->get($probe), 'preflight');
            $storage->delete($probe);

            $this->result($ok ? self::PASS : self::FAIL, $label, sprintf('[%s] %s driver, %s/ read/write %s', $disk, $driver, trim($directory, '/'), $ok ? 'ok' : 'FAILED'));
        } catch (Throwable $exception) {
            $this->result(self::FAIL, $label, sprintf('[%s] %s: %s', $disk, $driver, Str::limit($exception->getMessage(), 100)));
        }
    }

    protected function checkQueue(): void
    {
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver");

        $this->result($driver === 'sync' ? self::WARNING : self::PASS, 'Queue connection', $connection.' ('.$driver.')'.($driver === 'sync' ? ': background work would run inline in requests' : ''));

        if ($driver === 'database') {
            $table = (string) config("queue.connections.{$connection}.table", 'jobs');

            try {
                $hasTables = Schema::hasTable($table) && Schema::hasTable('failed_jobs');
            } catch (Throwable $exception) {
                $this->result(self::FAIL, 'Queue tables', Str::limit($exception->getMessage(), 120));

                return;
            }

            $this->result($hasTables ? self::PASS : self::FAIL, 'Queue tables', $hasTables ? "{$table}, failed_jobs" : 'jobs / failed_jobs missing');

            if ($hasTables) {
                $failed = DB::table('failed_jobs')->count();
                $stale = DB::table($table)->where('available_at', '<', now()->subHour()->getTimestamp())->count();
                $this->result($failed === 0 ? self::PASS : self::WARNING, 'Failed jobs', $failed === 0 ? 'none' : "{$failed} failed job(s): php artisan queue:failed");
                $this->result($stale === 0 ? self::PASS : self::WARNING, 'Queue worker', $stale === 0 ? 'no jobs waiting longer than an hour' : "{$stale} job(s) waiting over an hour: is the worker running?");
            }
        }
    }

    protected function checkScheduler(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->all();
        $expected = ['seo:ensure-monthly-cycles', 'seo:prune-import-files'];
        $missing = array_values(array_filter($expected, fn (string $name): bool => ! collect($commands)->contains(fn (string $c): bool => str_contains($c, $name))));

        $this->result($missing === [] ? self::PASS : self::FAIL, 'Scheduled commands', $missing === [] ? implode(', ', $expected).' registered (cron: * * * * * php artisan schedule:run)' : 'not registered: '.implode(', ', $missing));
    }

    protected function checkChromium(): void
    {
        try {
            $binary = app(PdfReportGenerator::class)->chromiumBinary();
        } catch (Throwable $exception) {
            $this->result(self::FAIL, 'Chromium', $exception->getMessage());

            return;
        }

        $flags = (array) config('reports.chromium_flags', []);
        $this->result(in_array('--no-sandbox', $flags, true) ? self::WARNING : self::PASS, 'Chromium sandbox', in_array('--no-sandbox', $flags, true) ? '--no-sandbox is set: acceptable only inside an isolated container' : 'sandbox enabled (default)');
        $this->result((int) config('reports.chromium_timeout') >= 30 ? self::PASS : self::WARNING, 'Chromium timeout', config('reports.chromium_timeout').' s');

        if ($this->option('skip-chromium')) {
            $this->result(self::PASS, 'Chromium', $binary.' (version check skipped)');

            return;
        }

        try {
            // Windows builds of Chrome print nothing for --version (and may linger), so the
            // executable's own version resource is read instead.
            $result = PHP_OS_FAMILY === 'Windows'
                ? Process::timeout(15)->run(['powershell', '-NoProfile', '-NonInteractive', '-Command', '(Get-Item -LiteralPath "'.str_replace('"', '', $binary).'").VersionInfo.ProductVersion'])
                : Process::timeout(15)->run([$binary, '--version']);
            $version = trim($result->output() ?: $result->errorOutput());
            $this->result($result->successful() && $version !== '' ? self::PASS : self::WARNING, 'Chromium', $result->successful() && $version !== '' ? "{$binary}: {$version}" : "{$binary}: found, but its version could not be read (exit {$result->exitCode()})");
        } catch (Throwable $exception) {
            $this->result(self::WARNING, 'Chromium', "{$binary}: found, but its version could not be read: ".Str::limit($exception->getMessage(), 100));
        }
    }

    protected function result(string $status, string $check, string $detail): void
    {
        $this->results[] = [$status, $check, $detail];
    }
}
