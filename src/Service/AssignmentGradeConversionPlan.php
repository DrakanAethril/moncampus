<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\GradeStatus;

/**
 * What App\Service\AssignmentGradeConversionPlanner answers: the cells to write, and the students it
 * refused to write anything for.
 *
 * The two travel together rather than the planner throwing: « je n'ai pas de réponse pour Untel »
 * is not an error condition, it is the screen's own subject - the modal names those students and
 * asks. A planner that threw would leave the screen re-deriving the same list to know whom to ask.
 *
 * @phpstan-type ConversionCell array{studentId: int, status: GradeStatus, value: ?float}
 */
final class AssignmentGradeConversionPlan
{
    /**
     * @param list<ConversionCell> $cells               one per student the conversion can settle
     * @param list<int>            $unresolvedStudentIds students with neither a usable mark nor a choice
     */
    public function __construct(
        public readonly array $cells,
        public readonly array $unresolvedStudentIds,
    ) {
    }

    public function isComplete(): bool
    {
        return [] === $this->unresolvedStudentIds;
    }
}
