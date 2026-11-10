<?php

declare(strict_types=1);

namespace App\Controller\SeatingPlan;

use App\Entity\SeatingPlan;
use App\Entity\User;
use App\Repository\SeatingPlanRepository;
use App\Security\Voter\SeatingPlanVoter;

/**
 * The helpers the two seating plan controllers share. A plan one may not open answers 404, never
 * 403: for whoever it is not shared with, it does not exist (design/validated/plans-de-classe.md, §7).
 */
trait SeatingPlanControllerTrait
{
    private const string CSRF_TOKEN_ID = 'seating_plan';

    private function findPlan(int $id, SeatingPlanRepository $repository, string $attribute = SeatingPlanVoter::VIEW): SeatingPlan
    {
        $plan = $repository->find($id);
        if (null === $plan || !$this->isGranted($attribute, $plan)) {
            throw $this->createNotFoundException();
        }

        return $plan;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
