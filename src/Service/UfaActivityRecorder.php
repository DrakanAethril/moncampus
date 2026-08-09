<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\UfaActivity;
use App\Entity\User;
use App\Enum\UfaActivityType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le seul point d'écriture du journal UFA (App\Entity\UfaActivity). Ajouter un suivi = un case
 * dans App\Enum\UfaActivityType, sa clé de traduction, et un appel ici.
 *
 * À appeler APRÈS le flush de l'action observée : le journal la constate, il ne participe pas à
 * sa transaction. Une écriture de journal qui échouerait ne doit pas annuler une signature.
 *
 * Le payload est rempli ici et non par les appelants, pour que la même phrase soit composable
 * quel que soit l'écran d'où l'action est partie - le tuteur qui signe lui-même et le staff qui
 * signe pour son compte produisent la même ligne, seul l'acteur change.
 */
class UfaActivityRecorder
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, string> $extraPayload */
    public function record(
        UfaActivityType $type,
        InternshipTutorLink $tutorLink,
        ?User $actor,
        ?InternshipEvaluationPeriod $period = null,
        array $extraPayload = [],
    ): void {
        $student = $tutorLink->getStudent();
        $tutor = $tutorLink->getTutor();

        $activity = new UfaActivity($type, $actor, [
            'student' => $this->name($student),
            'tutor' => $this->name($tutor),
            'actor' => $this->name($actor),
            'period' => $period?->getName() ?? '',
            // Pour les phrases qui mentionnent la période entre parenthèses : sans ce suffixe
            // pré-composé, une relance d'engagement - qui ne porte sur aucune période - affichait
            // des parenthèses vides.
            'periodSuffix' => null !== $period ? ' ('.$period->getName().')' : '',
            ...$extraPayload,
        ]);

        $activity->setTutorLink($tutorLink);
        $activity->setEvaluationPeriod($period);
        $activity->setProgram($tutorLink->getProgram());
        $activity->setTestData($tutorLink->isTestAlternance());

        $this->entityManager->persist($activity);
        $this->entityManager->flush();
    }

    private function name(?User $user): string
    {
        return $user?->getDisplayName() ?? $user?->getUsername() ?? '';
    }
}
