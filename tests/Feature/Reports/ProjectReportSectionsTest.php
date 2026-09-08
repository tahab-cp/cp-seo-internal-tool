<?php

namespace Tests\Feature\Reports;

use App\Actions\Projects\CreateProjectAction;
use App\Actions\Reports\EnsureProjectReportSectionsAction;
use App\Actions\Reports\UpdateProjectReportSectionsAction;
use App\Enums\ReportSectionKey;
use App\Filament\Resources\Projects\Pages\ProjectReportSections;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectReportSection;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectReportSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
    }

    public function test_defaults_initialise_idempotently_with_a_central_definition(): void
    {
        $this->assertSame(0, $this->project->reportSections()->count());

        $sections = app(EnsureProjectReportSectionsAction::class)->handle($this->project);

        $this->assertCount(10, $sections);
        $this->assertSame(
            ['executive_summary', 'site_authority', 'organic_search', 'website_traffic', 'top_keywords', 'landing_pages', 'rankings', 'audience_country', 'backlinks', 'recommendations'],
            $sections->map(fn (ProjectReportSection $s): string => $s->section_key->value)->all(),
        );
        $this->assertSame([10, 20, 30, 40, 50, 60, 70, 80, 90, 100], $sections->pluck('sort_order')->all());
        $this->assertTrue($sections->every(fn (ProjectReportSection $s): bool => $s->is_enabled));
        $this->assertSame(['audience_country'], $sections->reject(fn (ProjectReportSection $s): bool => $s->is_required)->map(fn ($s) => $s->section_key->value)->values()->all());
        $this->assertSame('Audience by Country', $sections->firstWhere('section_key', ReportSectionKey::AudienceCountry)->title);
        $this->assertCount(10, ReportSectionKey::defaultDefinitions());

        app(EnsureProjectReportSectionsAction::class)->handle($this->project);
        app(EnsureProjectReportSectionsAction::class)->handle($this->project);

        $this->assertSame(10, $this->project->reportSections()->count());
        $this->assertTrue($this->project->reportSections->first()->project->is($this->project));
    }

    public function test_section_key_is_unique_per_project(): void
    {
        app(EnsureProjectReportSectionsAction::class)->handle($this->project);

        try {
            ProjectReportSection::factory()->forProject($this->project)->key(ReportSectionKey::Backlinks)->create();
            $this->fail('Expected the unique constraint to reject a duplicate section key.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // Same key on another project is fine.
        ProjectReportSection::factory()->key(ReportSectionKey::Backlinks)->create();
        $this->assertSame(11, ProjectReportSection::query()->count());
    }

    public function test_initialising_defaults_preserves_customised_rows_and_only_adds_missing_ones(): void
    {
        ProjectReportSection::factory()->forProject($this->project)->create([
            'section_key' => 'rankings', 'title' => 'Keyword Positions', 'is_enabled' => false, 'is_required' => false, 'sort_order' => 5,
        ]);

        app(EnsureProjectReportSectionsAction::class)->handle($this->project);

        $rankings = $this->project->reportSections()->where('section_key', 'rankings')->firstOrFail();
        $this->assertSame('Keyword Positions', $rankings->title);
        $this->assertFalse($rankings->is_enabled);
        $this->assertFalse($rankings->is_required);
        $this->assertSame(5, $rankings->sort_order);
        $this->assertSame(10, $this->project->reportSections()->count());
        $this->assertSame('Keyword Positions', $this->project->reportSections()->ordered()->first()->title);
    }

    public function test_new_projects_receive_the_default_configuration(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());

        $project = app(CreateProjectAction::class)->handle([
            'client_id' => Client::factory()->create()->id,
            'name' => 'Fresh Project',
            'website_url' => 'https://fresh.example',
            'status' => 'onboarding',
        ]);

        $this->assertSame(10, $project->reportSections()->count());
        $this->assertTrue($project->reportSections()->where('section_key', 'executive_summary')->value('is_required'));
        $this->assertSame(0, $project->monthlyCycles()->count());
        $this->assertDatabaseCount('monthly_reports', 0);
    }

    public function test_manager_and_admin_can_modify_configuration_but_not_invent_keys(): void
    {
        foreach ([User::factory()->seoManager()->create(), User::factory()->superAdmin()->create()] as $user) {
            $this->assertTrue($user->can('manageReportSections', $this->project));
        }

        $sections = app(UpdateProjectReportSectionsAction::class)->handle($this->project, [
            ['section_key' => 'recommendations', 'title' => 'What next', 'is_enabled' => true, 'is_required' => true],
            ['section_key' => 'executive_summary', 'title' => 'Summary', 'is_enabled' => true, 'is_required' => true],
            ['section_key' => 'audience_country', 'is_enabled' => false, 'is_required' => false],
            ['section_key' => 'rankings', 'is_required' => false],
        ]);

        $this->assertSame('What next', $sections->firstWhere('section_key', ReportSectionKey::Recommendations)->title);
        $this->assertSame(10, $sections->firstWhere('section_key', ReportSectionKey::Recommendations)->sort_order);
        $this->assertSame(20, $sections->firstWhere('section_key', ReportSectionKey::ExecutiveSummary)->sort_order);
        $this->assertFalse($sections->firstWhere('section_key', ReportSectionKey::AudienceCountry)->is_enabled);
        $this->assertFalse($sections->firstWhere('section_key', ReportSectionKey::Rankings)->is_required);
        $this->assertSame(10, $sections->count());
        $this->assertSame('recommendations', $sections->first()->section_key->value);

        foreach ([
            [['section_key' => 'custom_block', 'title' => 'Custom']],
            [['section_key' => 'backlinks', 'title' => '']],
            [['section_key' => 'backlinks', 'title' => str_repeat('t', 121)]],
            [['section_key' => 'backlinks', 'sort_order' => -1]],
            [['section_key' => 'backlinks'], ['section_key' => 'backlinks']],
        ] as $rows) {
            try {
                app(UpdateProjectReportSectionsAction::class)->handle($this->project, $rows);
                $this->fail('Expected rejection for '.json_encode($rows));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(10, $this->project->reportSections()->count());
        $this->assertNull(ProjectReportSection::query()->where('section_key', 'custom_block')->first());
    }

    public function test_executive_cannot_modify_configuration(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $project = Project::factory()->ownedBy($executive)->create();
        app(EnsureProjectReportSectionsAction::class)->handle($project);

        $this->actingAs($executive);

        $this->assertFalse($executive->can('manageReportSections', $project));
        $this->assertTrue($executive->can('view', $project));

        $this->get(ProjectResource::getUrl('report-sections', ['record' => $project]))->assertForbidden();
        $this->get(ProjectResource::getUrl('view', ['record' => $project]))
            ->assertOk()
            ->assertDontSee(ProjectResource::getUrl('report-sections', ['record' => $project]));

        Livewire::test(ProjectReportSections::class, ['record' => $project->getRouteKey()])->assertForbidden();

        $this->assertSame('Executive Summary', $project->reportSections()->where('section_key', 'executive_summary')->value('title'));
    }

    public function test_settings_page_lets_managers_edit_titles_flags_and_order(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());

        $this->get(ProjectResource::getUrl('report-sections', ['record' => $this->project]))
            ->assertOk()
            ->assertSee('Changes affect future reports only. Existing monthly reports keep their snapshotted configuration.')
            ->assertSee('data-report-section="executive_summary"', false);

        // Mounting initialised the defaults for an existing project.
        $this->assertSame(10, $this->project->reportSections()->count());

        $rows = $this->project->reportSections()->ordered()->get()->map(fn (ProjectReportSection $s): array => [
            'section_key' => $s->section_key->value,
            'title' => $s->title,
            'is_enabled' => $s->is_enabled,
            'is_required' => $s->is_required,
        ])->all();

        // Move Backlinks to the top, rename it, disable Audience, make Rankings optional.
        $backlinks = array_splice($rows, 8, 1)[0];
        $backlinks['title'] = 'Link Building';
        array_unshift($rows, $backlinks);
        $rows = array_map(function (array $row): array {
            if ($row['section_key'] === 'audience_country') {
                $row['is_enabled'] = false;
            }
            if ($row['section_key'] === 'rankings') {
                $row['is_required'] = false;
            }

            return $row;
        }, $rows);

        Livewire::test(ProjectReportSections::class, ['record' => $this->project->getRouteKey()])
            ->assertActionVisible('editSections')
            ->callAction('editSections', data: ['sections' => $rows])
            ->assertHasNoFormErrors()
            ->assertNotified('Report sections saved')
            ->assertSee('Link Building');

        $ordered = $this->project->reportSections()->ordered()->get();
        $this->assertSame('backlinks', $ordered->first()->section_key->value);
        $this->assertSame('Link Building', $ordered->first()->title);
        $this->assertSame(10, $ordered->first()->sort_order);
        $this->assertFalse($ordered->firstWhere('section_key', ReportSectionKey::AudienceCountry)->is_enabled);
        $this->assertFalse($ordered->firstWhere('section_key', ReportSectionKey::Rankings)->is_required);

        Livewire::test(ProjectReportSections::class, ['record' => $this->project->getRouteKey()])
            ->callAction('editSections', data: ['sections' => [['section_key' => 'backlinks', 'title' => '', 'is_enabled' => true, 'is_required' => true]]])
            ->assertHasFormErrors(['sections.0.title']);

        $this->assertSame('Link Building', $this->project->reportSections()->where('section_key', 'backlinks')->value('title'));
    }

    public function test_report_sections_settings_are_project_scoped(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $labels = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();

        $this->assertNotContains('Report sections', $labels);
        $this->get('/admin')->assertOk()->assertDontSee('/admin/report-sections');
        $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))
            ->assertOk()
            ->assertSee(ProjectResource::getUrl('report-sections', ['record' => $this->project]));
    }
}
