<?php

namespace App\Actions\Notes;

use App\Models\MonthlyNote;
use App\Services\Notes\MonthlyNoteGuard;
use Illuminate\Support\Facades\DB;

class UpdateMonthlyNoteAction
{
    public function __construct(
        protected MonthlyNoteGuard $guard,
    ) {}

    /**
     * Edit a note's type, title, body or order. Refused once the cycle is
     * locked. The author and cycle never change.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(MonthlyNote $note, array $attributes): MonthlyNote
    {
        return DB::transaction(function () use ($note, $attributes): MonthlyNote {
            $this->guard->ensureNoteNotLocked($note, 'edit its notes');

            if (array_key_exists('type', $attributes)) {
                $note->type = $this->guard->normaliseType($attributes['type']);
            }

            if (array_key_exists('title', $attributes)) {
                $note->title = $this->guard->normaliseTitle($attributes['title']);
            }

            if (array_key_exists('body', $attributes)) {
                $note->body = $this->guard->normaliseBody($attributes['body']);
            }

            if (array_key_exists('sort_order', $attributes)) {
                $note->sort_order = $this->guard->normaliseSortOrder($attributes['sort_order']) ?? $note->sort_order;
            }

            $note->save();

            return $note;
        });
    }
}
