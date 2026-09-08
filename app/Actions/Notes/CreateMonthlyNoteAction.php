<?php

namespace App\Actions\Notes;

use App\Models\MonthlyCycle;
use App\Models\MonthlyNote;
use App\Models\User;
use App\Services\Notes\MonthlyNoteGuard;
use Illuminate\Support\Facades\DB;

class CreateMonthlyNoteAction
{
    public function __construct(
        protected MonthlyNoteGuard $guard,
    ) {}

    /**
     * Capture a note for a reporting month. The author is the acting user
     * (never an arbitrary field) and must be active with project access.
     *
     * @param  array<string, mixed>  $attributes  type, title, body, sort_order
     */
    public function handle(MonthlyCycle $cycle, array $attributes, User $author): MonthlyNote
    {
        return DB::transaction(function () use ($cycle, $attributes, $author): MonthlyNote {
            $this->guard->ensureCycleNotLocked($cycle, 'add notes to it');
            $this->guard->ensureAuthor($cycle, $author);

            $type = $this->guard->normaliseType($attributes['type'] ?? null);

            $sortOrder = $this->guard->normaliseSortOrder($attributes['sort_order'] ?? null)
                ?? ((int) $cycle->monthlyNotes()->where('type', $type->value)->max('sort_order') + 1);

            $note = new MonthlyNote([
                'type' => $type,
                'title' => $this->guard->normaliseTitle($attributes['title'] ?? null),
                'body' => $this->guard->normaliseBody($attributes['body'] ?? null),
                'sort_order' => $sortOrder,
            ]);

            $note->monthly_cycle_id = $cycle->getKey();
            $note->created_by = $author->getKey();
            $note->save();

            return $note;
        });
    }
}
