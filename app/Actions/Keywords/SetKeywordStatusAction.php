<?php

namespace App\Actions\Keywords;

use App\Enums\KeywordStatus;
use App\Models\Keyword;

class SetKeywordStatusAction
{
    /**
     * Pause, archive or reactivate a keyword. Archiving is the normal way to
     * stop tracking: the keyword and all ranking history remain.
     */
    public function handle(Keyword $keyword, KeywordStatus|string $status): Keyword
    {
        $keyword->status = $status instanceof KeywordStatus ? $status : KeywordStatus::from($status);
        $keyword->save();

        return $keyword;
    }
}
