<?php

namespace App\Controller;

// Shared by every controller serving a per-Program feature area gated by one of Program's
// timetable/financial/topicSkill/internship management flags - 404s instead of hiding a link
// left reachable by direct URL once its feature is toggled off.
trait ProgramFeatureGuardTrait
{
    private function assertProgramFeatureEnabled(bool $enabled): void
    {
        if (!$enabled) {
            throw $this->createNotFoundException();
        }
    }
}
