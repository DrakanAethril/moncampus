<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\Evaluation;
use App\Entity\User;
use App\Repository\AssignmentRepository;
use App\Repository\SelfAssessmentRepository;

/**
 * Withholds, from one student, the grades of the evaluations they still owe an estimate on.
 *
 * A self-assessment asks a student to guess the grade they are about to get. That question is only
 * worth asking while the answer is out of reach: an evaluation with no scheduled visibility is
 * readable in the gradebook the moment it is graded (Evaluation::isVisibleAt() is true on a null
 * date), so without this the student could read the grade first and "estimate" it afterwards.
 *
 * The rule is per student, not per evaluation: the grade comes back as soon as THAT student has
 * validated their own estimate, and a classmate who has not is held back on their own.
 */
class SelfAssessmentGradeGate
{
    public function __construct(
        private readonly AssignmentRepository $assignmentRepository,
        private readonly SelfAssessmentRepository $selfAssessmentRepository,
        private readonly AssignmentAudienceResolver $audienceResolver,
    ) {
    }

    /**
     * The evaluations this student must not read yet, by id.
     *
     * @param list<Evaluation> $evaluations
     *
     * @return array<int, true> evaluation id => held back
     */
    public function withheldEvaluationIds(array $evaluations, User $student, \DateTimeImmutable $now): array
    {
        if ([] === $evaluations) {
            return [];
        }

        // A work aimed at part of the class only holds back the students it was aimed at.
        $addressed = array_values(array_filter(
            $this->assignmentRepository->findPublishedSelfAssessmentsForEvaluations($evaluations, $now),
            fn (Assignment $assignment): bool => $this->audienceResolver->isInAudience($assignment, $student),
        ));
        if ([] === $addressed) {
            return [];
        }

        // A draft is not an answer: only a validated estimate releases the grade.
        $validated = $this->selfAssessmentRepository->findValidationDatesForStudent($addressed, $student);

        $withheld = [];
        foreach ($addressed as $assignment) {
            $evaluationId = $assignment->getEvaluation()?->getId();

            if (null !== $evaluationId && !isset($validated[$assignment->getId()])) {
                $withheld[$evaluationId] = true;
            }
        }

        return $withheld;
    }
}
