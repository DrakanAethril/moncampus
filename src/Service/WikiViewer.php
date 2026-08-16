<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The person asking, as primitives - counterpart of App\Service\WikiSubject.
 *
 * $isAssignedProgramStudent is the one fact the wiki cannot answer on its own: "is this student
 * enrolled in one of the classes this wiki is assigned to". It is resolved by the caller holding
 * the entity graph and handed in, so App\Service\WikiAccess stays free of repositories.
 */
final readonly class WikiViewer
{
    /** @param list<string> $roles */
    public function __construct(
        public ?int $id,
        public array $roles,
        public bool $isAssignedProgramStudent = false,
    ) {
    }
}
