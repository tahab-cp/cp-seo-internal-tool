<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Dashboard\TeamWorkloadService;
use App\Support\Dashboard\TeamWorkloadRow;
use App\Support\MonthlyCycles\CyclePeriod;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Organisation-wide workload indicators for Super Admin and SEO Manager.
 * Counts only: never a rating, utilisation or ranking.
 */
class TeamWorkload extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Team workload';

    protected static ?string $title = 'Team workload';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.team-workload';

    protected ?string $subheading = 'Current operational workload across active SEO team members.';

    /**
     * @var Collection<int, TeamWorkloadRow>|null
     */
    protected ?Collection $rows = null;

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        return $user?->hasPermission(Permission::ViewAllProjects) === true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getPeriod(): CyclePeriod
    {
        return CyclePeriod::current();
    }

    /**
     * @return Collection<int, TeamWorkloadRow>
     */
    public function getRows(): Collection
    {
        return $this->rows ??= app(TeamWorkloadService::class)->rows($this->getPeriod());
    }

    /**
     * Page-level totals summed from the rows already loaded: no extra
     * queries and no new domain calculation.
     *
     * @return array{members: int, primary: int, open: int, overdue: int}
     */
    public function getSummary(): array
    {
        $rows = $this->getRows();

        return [
            'members' => $rows->count(),
            'primary' => (int) $rows->sum(fn (TeamWorkloadRow $row): int => $row->primaryProjects),
            'open' => (int) $rows->sum(fn (TeamWorkloadRow $row): int => $row->openTasks),
            'overdue' => (int) $rows->sum(fn (TeamWorkloadRow $row): int => $row->overdueTasks),
        ];
    }
}
