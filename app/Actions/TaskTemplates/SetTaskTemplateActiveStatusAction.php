<?php

namespace App\Actions\TaskTemplates;

use App\Models\TaskTemplate;

class SetTaskTemplateActiveStatusAction
{
    /**
     * Deactivation only stops new onboarding generation; existing generated
     * tasks and the template's items are untouched.
     */
    public function handle(TaskTemplate $template, bool $isActive): TaskTemplate
    {
        $template->forceFill(['is_active' => $isActive])->save();

        return $template;
    }
}
