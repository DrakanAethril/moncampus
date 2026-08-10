<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MessageAudienceType;
use Doctrine\Common\Collections\Collection;

/**
 * Anything addressed to an audience resolved by App\Service\AudienceResolver - MessageThread,
 * Announcement, AgendaEvent, SignupList.
 *
 * The audience is a *set* of App\Enum\MessageAudienceType, not one of them: "les étudiants de SIO1
 * et tous les personnels" is a single selection, and the resolved audience is the deduplicated
 * union of what each named type reaches. Every combination stays live - a teacher hired after the
 * fact is inside an "all teachers" audience the moment their account exists, exactly as they would
 * be if that type had been the only one named.
 *
 * $programs/$includeStudents/$includeTeachers are only meaningful when the set contains Program,
 * $manualRecipients only when it contains Manual - same convention on every implementation, and
 * the reason each of those branches is skipped rather than emptied when its type is absent.
 *
 * Implementations store the set through App\Entity\AudienceTargetableTrait, which keeps it sorted
 * into MessageAudienceType's declaration order - see MessageAudienceType::sort() for what depends
 * on that.
 */
interface AudienceTargetable
{
    /** @return list<MessageAudienceType> */
    public function getAudienceTypes(): array;

    public function hasAudienceType(MessageAudienceType $type): bool;

    /** @return Collection<int, Program> */
    public function getPrograms(): Collection;

    public function isIncludeStudents(): bool;

    public function isIncludeTeachers(): bool;

    /** @return Collection<int, User> */
    public function getManualRecipients(): Collection;
}
