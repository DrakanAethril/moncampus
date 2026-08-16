<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\WikiType;

/**
 * The facts about a wiki that decide who may touch it, as primitives.
 *
 * Deliberately not the App\Entity\Wiki itself: the rule is then testable without an entity graph
 * and answerable from a repository row as easily as from a hydrated wiki - the same posture
 * App\Service\DocumentationAccess takes.
 *
 * $hasStudentAudience is the derived, never-stored fact of the design: a wiki has one when it
 * carries at least one assigned Program or at least one member holding ROLE_STUDENT. It is what
 * moves a wiki between the supervised and the private regime, so it is computed at the call site
 * (App\Service\WikiAccess::hasStudentAudience()) rather than remembered anywhere.
 */
final readonly class WikiSubject
{
    /** @param list<int> $memberIds */
    public function __construct(
        public WikiType $type,
        public ?int $ownerId,
        public bool $ownerIsStudent,
        public ?int $creatorId,
        public array $memberIds,
        public bool $hasStudentAudience,
    ) {
    }
}
