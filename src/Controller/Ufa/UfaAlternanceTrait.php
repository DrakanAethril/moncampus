<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Entity\User;
use App\Enum\AlternanceReminderStep;
use App\Service\AlternanceStepStatus;
use Symfony\Component\HttpFoundation\Request;

/**
 * Helpers shared by the per-tab controllers this class was split into.
 *
 * Moved verbatim out of the former fat controller - no behaviour change.
 */
trait UfaAlternanceTrait
{
    private const string STAFF_ACCESS_EXPRESSION = 'is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")';

    private function reminderStepFor(string $statusStep): ?AlternanceReminderStep
    {
        return match ($statusStep) {
            AlternanceStepStatus::STEP_ENGAGEMENT_TUTOR => AlternanceReminderStep::EngagementTutor,
            AlternanceStepStatus::STEP_ENGAGEMENT_STUDENT => AlternanceReminderStep::EngagementStudent,
            AlternanceStepStatus::STEP_TUTOR => AlternanceReminderStep::Tutor,
            AlternanceStepStatus::STEP_STUDENT => AlternanceReminderStep::Student,
            AlternanceStepStatus::STEP_SUPERVISOR => AlternanceReminderStep::Supervisor,
            default => null,
        };
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    // For plain <form method="post"> submissions - the token travels as a body field (name="_token").
    private function assertValidFormToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
