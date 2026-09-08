<?php

namespace App\Actions\Projects;

use App\Exceptions\UnknownTargetKeyException;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyncProjectTargetOverridesAction
{
    /**
     * Replace the project's target overrides with the given map of
     * target_key => value.
     *
     * - Every key must be defined by the project's current package; V1 does
     *   not allow project-only target keys.
     * - Values must be non-negative integers.
     * - Keys absent from the map lose their override (package default applies).
     * - The label is copied from the package target so the override is
     *   self-describing.
     *
     * @param  array<string, int|string>  $overrides
     */
    public function handle(Project $project, array $overrides): Project
    {
        $package = $project->package()->with('targets')->first();
        $targets = $package?->targets->keyBy('target_key') ?? collect();

        $unknown = array_values(array_diff(array_keys($overrides), $targets->keys()->all()));

        if ($unknown !== []) {
            throw UnknownTargetKeyException::for($unknown, $package?->name);
        }

        $values = [];

        foreach ($overrides as $key => $value) {
            if (! is_numeric($value) || (int) $value < 0 || (int) $value != $value) {
                throw new InvalidArgumentException("Override [{$key}] must be a non-negative integer.");
            }

            $values[$key] = (int) $value;
        }

        DB::transaction(function () use ($project, $targets, $values): void {
            $project->targetOverrides()->whereNotIn('target_key', array_keys($values))->delete();

            foreach ($values as $key => $value) {
                $project->targetOverrides()->updateOrCreate(
                    ['target_key' => $key],
                    ['label' => $targets[$key]->label, 'target_value' => $value],
                );
            }
        });

        $project->unsetRelation('targetOverrides');

        return $project;
    }
}
