<?php

namespace App\Support\Dashboard;

use App\Models\User;

/**
 * A workload INDICATOR for one active team member: counts of what is on
 * their plate right now. Deliberately no rating, utilisation or ranking.
 */
final readonly class TeamWorkloadRow
{
    public function __construct(
        public User $user,
        public int $primaryProjects,
        public int $teamProjects,
        public int $openTasks,
        public int $overdueTasks,
        public int $dueThisWeekTasks,
        public int $reportsInPreparation,
    ) {}

    public function roleLabel(): string
    {
        return $this->user->role()?->getLabel() ?? '—';
    }
}
