<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\MonthlyCycleStatus;
use App\Models\ContentItem;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Services\Content\ContentIntegrityGuard;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Add / edit content fields. Dynamic requirements (published needs month,
 * date and URL) mirror the guard; the content actions enforce them again.
 */
class ContentItemForm
{
    /**
     * @return array<int, Component>
     */
    public static function components(Project $project, ?ContentItem $item = null, ?int $defaultCycleId = null): array
    {
        $cycles = $project->monthlyCycles()
            ->where('status', '!=', MonthlyCycleStatus::Locked->value)
            ->latestPeriodFirst()
            ->get();

        // Active keywords, plus the item's current keyword even if archived.
        $keywords = $project->keywords()
            ->where(fn ($query) => $query->active()->when($item?->target_keyword_id, fn ($q, int $id) => $q->orWhere('id', $id)))
            ->orderBy('keyword')
            ->get();

        // Active accessible users, plus the item's current (possibly inactive) assignee.
        $assignable = app(ContentIntegrityGuard::class)->assignableUsers($project);
        $assigneeOptions = $assignable->pluck('name', 'id')->all();

        if ($item?->assigned_user_id && ! isset($assigneeOptions[$item->assigned_user_id])) {
            $assigneeOptions[$item->assigned_user_id] = ($item->assignee?->name ?? 'Former user').' (inactive)';
        }

        $isPublished = function (Get $get): bool {
            $status = $get('status');

            // The Select is enum-cast, so state may be an enum instance or a raw value.
            return ($status instanceof ContentStatus ? $status : ContentStatus::tryFrom((string) $status)) === ContentStatus::Published;
        };

        return [
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Select::make('content_type')
                ->label('Content type')
                ->options(ContentType::class)
                ->required()
                ->native(false),
            Select::make('status')
                ->options(ContentStatus::class)
                ->required()
                ->default(ContentStatus::Idea->value)
                ->native(false)
                ->live(),
            Select::make('monthly_cycle_id')
                ->label('Reporting month')
                ->options($cycles->mapWithKeys(fn (MonthlyCycle $cycle): array => [$cycle->id => $cycle->periodLabel()])->all())
                ->placeholder('Unscheduled (project-level)')
                ->default($defaultCycleId)
                ->nullable()
                ->native(false)
                ->required($isPublished)
                ->rule(Rule::exists('monthly_cycles', 'id')
                    ->where('project_id', $project->getKey())
                    ->where('status', '!=', MonthlyCycleStatus::Locked->value))
                ->helperText('Required once published. Locked months cannot be selected.'),
            Select::make('target_keyword_id')
                ->label('Target keyword')
                ->options($keywords->mapWithKeys(fn (Keyword $keyword): array => [$keyword->id => $keyword->keyword.($keyword->isArchived() ? ' (archived)' : '')])->all())
                ->searchable()
                ->nullable()
                ->native(false)
                ->rule(Rule::exists('keywords', 'id')->where('project_id', $project->getKey())),
            Select::make('assigned_user_id')
                ->label('Assigned to')
                ->options($assigneeOptions)
                ->searchable()
                ->nullable()
                ->native(false)
                ->in(array_keys($assigneeOptions)),
            DatePicker::make('planned_publish_date')
                ->label('Planned publish date')
                ->native(false),
            DateTimePicker::make('published_at')
                ->label('Published date')
                ->seconds(false)
                ->native(false)
                ->required($isPublished)
                ->visible($isPublished),
            TextInput::make('published_url')
                ->label('Published URL')
                ->url()
                ->maxLength(ContentIntegrityGuard::URL_MAX)
                ->required($isPublished)
                ->visible($isPublished)
                ->columnSpanFull(),
            Textarea::make('notes')
                ->rows(3)
                ->maxLength(5000)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromItem(ContentItem $item): array
    {
        return [
            'title' => $item->title,
            'content_type' => $item->content_type->value,
            'status' => $item->status->value,
            'monthly_cycle_id' => $item->monthly_cycle_id,
            'target_keyword_id' => $item->target_keyword_id,
            'assigned_user_id' => $item->assigned_user_id,
            'planned_publish_date' => $item->planned_publish_date?->toDateString(),
            'published_at' => $item->published_at?->toDateTimeString(),
            'published_url' => $item->published_url,
            'notes' => $item->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributesFromData(array $data): array
    {
        return [
            'title' => $data['title'] ?? null,
            'content_type' => $data['content_type'] ?? null,
            'status' => $data['status'] ?? ContentStatus::Idea->value,
            'monthly_cycle_id' => $data['monthly_cycle_id'] ?? null,
            'target_keyword_id' => $data['target_keyword_id'] ?? null,
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'planned_publish_date' => $data['planned_publish_date'] ?? null,
            'published_at' => $data['published_at'] ?? null,
            'published_url' => $data['published_url'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    public static function assignableUsers(Project $project): Collection
    {
        return app(ContentIntegrityGuard::class)->assignableUsers($project);
    }
}
