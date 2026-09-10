<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Reports\EnsureProjectReportSectionsAction;
use App\Actions\Reports\UpdateProjectReportSectionsAction;
use App\Enums\ReportSectionKey;
use App\Filament\Resources\Projects\Concerns\HasProjectWorkspace;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\ProjectReportSection;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Project settings → Report sections: the project's report template for
 * FUTURE reports. Super Admin and SEO Manager only (403 otherwise).
 * Existing monthly reports keep their snapshot. Presentation reads the
 * existing records only.
 */
class ProjectReportSections extends ResourcePage
{
    use HasProjectWorkspace;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-report-sections';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
        abort_unless(Gate::allows('manageReportSections', $this->getRecord()), 403);

        app(EnsureProjectReportSectionsAction::class)->handle($this->getProject());
    }

    public function getTitle(): string
    {
        return $this->getProject()->name;
    }

    public function getSubheading(): ?string
    {
        return $this->getWorkspaceSubheading();
    }

    public function getProject(): Project
    {
        /** @var Project $project */
        $project = $this->getRecord();

        return $project;
    }

    /**
     * @return Collection<int, ProjectReportSection>
     */
    public function getSections(): Collection
    {
        return $this->getProject()->reportSections()->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editSections')
                ->label('Edit report sections')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->modalHeading('Edit report sections')
                ->modalDescription('Changes affect future reports only. Existing reports keep their saved section layout. Use the arrows to change the order sections appear in.')
                ->modalWidth('4xl')
                ->schema([
                    Repeater::make('sections')
                        ->hiddenLabel()
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->columns(4)
                        ->itemLabel(fn (array $state): ?string => ReportSectionKey::tryFrom((string) ($state['section_key'] ?? ''))?->defaultTitle())
                        ->schema([
                            Hidden::make('section_key'),
                            TextInput::make('title')
                                ->label('Title in the report')
                                ->required()
                                ->maxLength(UpdateProjectReportSectionsAction::TITLE_MAX)
                                ->columnSpan(2),
                            Toggle::make('is_enabled')->label('Included')->inline(false),
                            Toggle::make('is_required')->label('Required')->inline(false),
                        ]),
                ])
                ->fillForm(fn (): array => [
                    'sections' => $this->getSections()->map(fn (ProjectReportSection $section): array => [
                        'section_key' => $section->section_key->value,
                        'title' => $section->title,
                        'is_enabled' => $section->is_enabled,
                        'is_required' => $section->is_required,
                    ])->values()->all(),
                ])
                ->authorize(fn (): bool => Gate::allows('manageReportSections', $this->getProject()))
                ->action(function (array $data, Action $action): void {
                    Gate::authorize('manageReportSections', $this->getProject());

                    try {
                        // Row order in the repeater becomes the sort order.
                        app(UpdateProjectReportSectionsAction::class)->handle($this->getProject(), array_values($data['sections'] ?? []));
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        $action->halt();
                    }

                    Notification::make()->title('Report sections saved')->success()->send();
                }),
        ];
    }
}
