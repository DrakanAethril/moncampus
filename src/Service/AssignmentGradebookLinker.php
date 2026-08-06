<?php

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\Evaluation;
use App\Enum\AssignmentAudienceType;
use App\Enum\EvaluationModality;
use App\Enum\EvaluationStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Si le travail est noté, une évaluation est créée automatiquement dans le carnet de notes à la
 * réception des rendus » (design_handoff_creation_travail 2a, étape Type).
 *
 * À la réception, et non à la publication : un travail noté que personne ne rend n'a pas à laisser
 * une colonne vide dans le carnet. La première production reçue fait naître l'évaluation, les
 * suivantes la retrouvent - c'est Assignment::$gradebookEvaluation qui garde le lien, et son
 * existence qui sert de garde-fou contre les doublons.
 *
 * L'évaluation naît sur la matière du travail. Sans matière (travail donné hors séance, dans une
 * classe où l'enseignant en assure plusieurs), il n'y a pas d'endroit où la ranger au carnet et
 * rien n'est créé : le travail reste noté, sa note se saisit à la main.
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
        $evaluation->setStatus(EvaluationStatus::Planned);
        $evaluation->setLessonSession($assignment->getLessonSession());
        // Un dépôt par groupe se note une fois pour tout le groupe : la note du rendu alimente le
        // carnet pour chacun de ses membres.
        $evaluation->setModality(AssignmentAudienceType::GroupBatch === $assignment->getAudienceType()
            ? EvaluationModality::Group
            : EvaluationModality::Individual);

        $assignment->setGradebookEvaluation($evaluation);
        $this->entityManager->persist($evaluation);

        return $evaluation;
    }
}
