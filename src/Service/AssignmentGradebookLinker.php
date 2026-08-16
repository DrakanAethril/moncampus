<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\Evaluation;
use App\Enum\AssignmentAudienceType;
use App\Enum\EvaluationModality;
use App\Enum\EvaluationStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Si le travail est noté, une évaluation est créée automatiquement dans le carnet de notes à la
 * réception des rendus » (design_handoff_creation_travail 2a, Type step).
 *
 * On reception, and not on publication: a graded assignment nobody hands in has no business leaving
 * an empty column in the gradebook. The first production received gives birth to the evaluation, the
 * following ones find it again - Assignment::$gradebookEvaluation keeps the link, and its existence
 * serves as the safeguard against duplicates.
 *
 * The evaluation is born on the assignment's matière. With no matière (an assignment given outside a
 * séance, in a class where the teacher covers several), there is nowhere to file it in the gradebook
 * and nothing is created: the assignment stays graded, its grade being entered by hand.
 */
class AssignmentGradebookLinker
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function ensureEvaluationExists(Assignment $assignment): ?Evaluation
    {
        if (!$assignment->isGraded() || null !== $assignment->getGradebookEvaluation()) {
            return $assignment->getGradebookEvaluation();
        }

        $topic = $assignment->getTopic();

        if (null === $topic) {
            return null;
        }

        $evaluation = new Evaluation($topic, (string) $assignment->getTitle(), $assignment->getDueDate() ?? new \DateTimeImmutable());
        // The evaluation is born of the assignment, so it is credited to whoever gave it - the
        // student whose deposit happens to trigger it is not its author. Non-null in the database,
        // hence the fallback on the subject's teacher for the odd assignment with no creator.
        $evaluation->setCreatedBy($assignment->getCreatedBy() ?? $topic->getTeacher());
        $evaluation->setStatus(EvaluationStatus::Planned);
        $evaluation->setLessonSession($assignment->getLessonSession());
        // A submission per group is graded once for the whole group: the submission's grade feeds the
        // gradebook for each of its members.
        $evaluation->setModality(AssignmentAudienceType::GroupBatch === $assignment->getAudienceType()
            ? EvaluationModality::Group
            : EvaluationModality::Individual);

        $assignment->setGradebookEvaluation($evaluation);
        $this->entityManager->persist($evaluation);

        return $evaluation;
    }
}
