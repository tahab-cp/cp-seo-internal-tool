<?php

namespace App\Services\Notes;

use App\Enums\MonthlyNoteType;
use App\Exceptions\UnauthorizedProjectUserException;
use App\Models\MonthlyCycle;
use App\Models\MonthlyNote;
use App\Models\User;
use App\Services\ActiveUserGuard;
use App\Services\MonthlyCycles\Concerns\LocksMonthlyCycles;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Domain rules for monthly notes, independent of forms: locked cycles are
 * immutable (Super Admin included, until Milestone 14); the author must be
 * active and able to access the project; a note needs a body.
 */
class MonthlyNoteGuard
{
    use LocksMonthlyCycles;

    public const TITLE_MAX = 150;

    public const BODY_MAX = 5000;

    public function __construct(
        protected ActiveUserGuard $activeUsers,
    ) {}

    /**
     * Row-lock the cycle (inside the caller's transaction) and refuse if it
     * is locked.
     */
    public function ensureCycleNotLocked(MonthlyCycle $cycle, string $operation): void
    {
        $this->lockCycle($cycle, $operation);
    }

    public function ensureNoteNotLocked(MonthlyNote $note, string $operation): void
    {
        $this->lockCycle($note->monthly_cycle_id, $operation);
    }

    public function ensureAuthor(MonthlyCycle $cycle, User $author): void
    {
        $this->activeUsers->ensureActive([$author->getKey()], 'the note author');

        $project = $cycle->project;

        if (! Gate::forUser($author)->allows('view', $project)) {
            throw UnauthorizedProjectUserException::for($author, $project, 'the note author');
        }
    }

    public function normaliseType(mixed $type): MonthlyNoteType
    {
        if ($type instanceof MonthlyNoteType) {
            return $type;
        }

        return MonthlyNoteType::tryFrom((string) $type)
            ?? throw new InvalidArgumentException("Unknown note type [{$type}].");
    }

    public function normaliseTitle(mixed $title): ?string
    {
        $title = trim((string) $title);

        if ($title === '') {
            return null;
        }

        if (mb_strlen($title) > self::TITLE_MAX) {
            throw new InvalidArgumentException('The note title may not exceed '.self::TITLE_MAX.' characters.');
        }

        return $title;
    }

    public function normaliseBody(mixed $body): string
    {
        $body = trim((string) $body);

        if ($body === '') {
            throw new InvalidArgumentException('A note needs a body.');
        }

        if (mb_strlen($body) > self::BODY_MAX) {
            throw new InvalidArgumentException('The note body may not exceed '.self::BODY_MAX.' characters.');
        }

        return $body;
    }

    public function normaliseSortOrder(mixed $sortOrder): ?int
    {
        if ($sortOrder === null || $sortOrder === '') {
            return null;
        }

        if (! is_numeric($sortOrder) || (int) $sortOrder != $sortOrder || (int) $sortOrder < 0) {
            throw new InvalidArgumentException("The note order must be a whole number of 0 or more; [{$sortOrder}] given.");
        }

        return (int) $sortOrder;
    }
}
