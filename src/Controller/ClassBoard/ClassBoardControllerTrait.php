<?php

declare(strict_types=1);

namespace App\Controller\ClassBoard;

use App\Entity\ClassBoard;
use App\Entity\User;
use App\Repository\ClassBoardRepository;
use App\Security\Voter\ClassBoardVoter;

/**
 * The helpers the three board controllers share. A board someone else owns answers 404, never 403:
 * it does not exist for them (design/validated/tableau-virtuel.md, §2).
 */
trait ClassBoardControllerTrait
{
    private function findBoard(int $id, ClassBoardRepository $repository, string $attribute = ClassBoardVoter::VIEW): ClassBoard
    {
        $board = $repository->find($id);
        if (null === $board || !$this->isGranted($attribute, $board)) {
            throw $this->createNotFoundException();
        }

        return $board;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
