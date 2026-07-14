<?php

namespace App\Entity;

use App\Enum\MessageAudienceType;
use Doctrine\Common\Collections\Collection;

/**
 * Anything addressed to an audience resolved by App\Service\AudienceResolver - MessageThread,
 * Announcement, AgendaEvent. $program is only meaningful for ProgramStudents/ProgramTeachers,
 * $manualRecipients only for Manual - same convention on every implementation.
 */
interface AudienceTargetable
{
    public function getAudienceType(): ?MessageAudienceType;

    public function getProgram(): ?Program;

    /** @return Collection<int, User> */
    public function getManualRecipients(): Collection;
}
