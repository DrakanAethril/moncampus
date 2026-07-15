<?php

namespace App\Entity;

use App\Enum\MessageAudienceType;
use Doctrine\Common\Collections\Collection;

/**
 * Anything addressed to an audience resolved by App\Service\AudienceResolver - MessageThread,
 * Announcement, AgendaEvent. $programs/$includeStudents/$includeTeachers are only meaningful for
 * the Program audience type, $manualRecipients only for Manual - same convention on every
 * implementation.
 */
interface AudienceTargetable
{
    public function getAudienceType(): ?MessageAudienceType;

    /** @return Collection<int, Program> */
    public function getPrograms(): Collection;

    public function isIncludeStudents(): bool;

    public function isIncludeTeachers(): bool;

    /** @return Collection<int, User> */
    public function getManualRecipients(): Collection;
}
