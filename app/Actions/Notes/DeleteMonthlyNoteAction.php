<?php

namespace App\Actions\Notes;

use App\Models\MonthlyNote;
use App\Services\Notes\MonthlyNoteGuard;
use Illuminate\Support\Facades\DB;

class DeleteMonthlyNoteAction
{
    public function __construct(
        protected MonthlyNoteGuard $guard,
    ) {}

    /**
     * Remove a note from an unlocked month. Notes are working narrative,
     * not operational history, so a hard delete is acceptable here.
     */
    public function handle(MonthlyNote $note): void
    {
        DB::transaction(function () use ($note): void {
            $this->guard->ensureNoteNotLocked($note, 'delete its notes');

            $note->delete();
        });
    }
}
