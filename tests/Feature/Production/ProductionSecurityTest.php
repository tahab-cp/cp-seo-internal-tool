<?php

namespace Tests\Feature\Production;

use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UnlockMonthlyReportAction;
use App\Enums\ImportType;
use App\Filament\Resources\Imports\ImportBatchResource;
use App\Models\MonthlyReportRevision;
use App\Models\Role;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\Support\RunsCsvImports;
use Tests\TestCase;

class ProductionSecurityTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;
    use RunsCsvImports;

    protected User $admin;

    protected User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 10:00:00');
        Storage::fake('local');
        FakePdfReportGenerator::install();

        $this->admin = User::factory()->superAdmin()->create();
        $this->outsider = User::factory()->seoExecutive()->create();
        $this->buildCompleteReport(User::factory()->seoExecutive()->create());
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $this->report = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();
    }

    public function test_a_deactivated_user_loses_panel_access_on_the_next_request(): void
    {
        $this->actingAs($this->manager);
        $this->get('/admin')->assertOk();

        $this->manager->forceFill(['is_active' => false])->save();

        $this->get('/admin')->assertForbidden();
        $this->get(route('filament.admin.reports.pdf', ['project' => $this->project, 'report' => $this->report]))->assertForbidden();
    }

    public function test_an_unrelated_executive_cannot_reach_another_projects_report_revision_or_import_history(): void
    {
        $revision = MonthlyReportRevision::factory()->forReport($this->report)->create();
        $batch = $this->importCsv($this->manager, ImportType::Keywords, $this->project, null, "keyword\nroses\n");

        $this->actingAs($this->outsider);

        $params = ['project' => $this->project->getKey(), 'report' => $this->report->getKey()];
        $this->get(route('filament.admin.reports.pdf', $params))->assertNotFound();
        $this->get(route('filament.admin.reports.preview', $params))->assertNotFound();
        $this->get(route('filament.admin.reports.revisions.pdf', $params + ['revision' => $revision->getKey()]))->assertNotFound();
        $this->get(route('filament.admin.reports.revisions.preview', $params + ['revision' => $revision->getKey()]))->assertNotFound();
        $this->get(ImportBatchResource::getUrl('view', ['record' => $batch]))->assertNotFound();
        $this->get('/admin/projects/'.$this->project->getKey())->assertNotFound();

        // The raw storage path is never a usable URL (the private disk only answers signed application URLs).
        $this->assertContains($this->get('/storage/'.$this->report->generated_pdf_path)->getStatusCode(), [403, 404]);
        $this->assertContains($this->get('/'.$this->report->generated_pdf_path)->getStatusCode(), [403, 404]);
        $this->assertFalse(File::exists(public_path('storage')), 'no public storage symlink');
    }

    public function test_only_a_super_admin_may_unlock_a_finalized_report(): void
    {
        $executive = User::factory()->seoExecutive()->create();

        foreach ([$this->manager, $executive, $this->outsider] as $user) {
            $this->assertFalse($user->can('unlock', $this->report), $user->email);

            try {
                app(UnlockMonthlyReportAction::class)->handle($this->report->fresh(), $user, 'Trying to unlock without the right role.');
                $this->fail('unlock must be refused');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertTrue($this->admin->can('unlock', $this->report));
        $this->assertTrue($this->cycle->fresh()->isLocked(), 'still locked');
        $this->assertFalse($this->manager->can('viewAny', User::class), 'managers do not manage users');
        $this->assertTrue($this->admin->can('viewAny', User::class));
    }

    public function test_seeding_creates_roles_only_and_no_default_password_user(): void
    {
        $users = User::query()->count();

        Artisan::call('db:seed', ['--force' => true]);

        $this->assertSame($users, User::query()->count(), 'no seeded users');
        $this->assertSame(3, Role::query()->count());

        foreach (File::allFiles(database_path('seeders')) as $file) {
            $source = File::get($file->getPathname());
            $this->assertStringNotContainsStringIgnoringCase('password', $source, $file->getFilename());
            $this->assertStringNotContainsString('User::', $source, $file->getFilename());
        }
    }

    public function test_security_headers_are_sent_and_the_health_route_exposes_nothing(): void
    {
        $response = $this->get('/admin/login')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $health = $this->get('/up')->assertOk();
        $body = $health->getContent();

        foreach (['APP_KEY', 'DB_PASSWORD', 'DB_HOST', config('app.key'), 'storage/app'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $body);
        }
    }

    public function test_proxies_are_trusted_only_when_configured(): void
    {
        Route::middleware('web')->get('/_proxy-probe', fn () => request()->isSecure() ? 'secure' : 'plain');
        $probe = fn () => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->get('/_proxy-probe', ['X-Forwarded-Proto' => 'https'])->getContent();

        try {
            TrustProxies::flushState();
            config(['security.trusted_proxies' => []]);
            (new AppServiceProvider(app()))->boot();
            $this->assertSame('plain', $probe(), 'an unknown proxy header is ignored by default');

            config(['security.trusted_proxies' => ['10.0.0.9']]);
            (new AppServiceProvider(app()))->boot();
            $this->assertSame('secure', $probe(), 'a listed proxy is trusted');
        } finally {
            TrustProxies::flushState();
        }
    }

    public function test_the_debug_page_is_off_and_exceptions_do_not_leak_details_in_production_mode(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_boom', fn () => throw new \RuntimeException('secret-internal-detail /var/www/app/File.php'));

        $response = $this->get('/_boom');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('secret-internal-detail', $response->getContent());
        $this->assertStringNotContainsString('File.php', $response->getContent());
    }
}
