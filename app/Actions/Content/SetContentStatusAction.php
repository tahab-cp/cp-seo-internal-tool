<?php

namespace App\Actions\Content;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Services\Content\ContentIntegrityGuard;

class SetContentStatusAction
{
    public function __construct(
        protected ContentIntegrityGuard $guard,
        protected UpdateContentItemAction $update,
    ) {}

    /**
     * Move an item to another status. Forward and backward moves are both
     * allowed in unlocked periods. Publishing needs a reporting month, date
     * and URL (supplied here or already present); leaving "published"
     * clears them. Refused in locked cycles.
     *
     * @param  array<string, mixed>  $publishDetails  monthly_cycle_id, published_at, published_url (when publishing)
     */
    public function handle(ContentItem $item, ContentStatus|string $status, array $publishDetails = []): ContentItem
    {
        $status = $this->guard->normaliseStatus($status);

        $attributes = ['status' => $status];

        if ($status->isPublished()) {
            foreach (['monthly_cycle_id', 'published_at', 'published_url'] as $field) {
                if (array_key_exists($field, $publishDetails)) {
                    $attributes[$field] = $publishDetails[$field];
                }
            }

            // Publishing without a date means "now".
            if (! array_key_exists('published_at', $attributes) && $item->published_at === null) {
                $attributes['published_at'] = now();
            }
        }

        return $this->update->handle($item, $attributes);
    }
}
