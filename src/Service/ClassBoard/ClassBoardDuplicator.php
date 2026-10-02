<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

use App\Entity\ClassBoard;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Dupliquer » (design/validated/tableau-virtuel.md, §4): the class, the background, the whole
 * layout and the drawings, under « … (copie) » - numbered if that is taken too. Persisted and
 * flushed here.
 */
final class ClassBoardDuplicator
{
    public function __construct(
        private readonly ClassBoardNaming $naming,
        private readonly ClassBoardDrawings $drawings,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function duplicate(ClassBoard $source): ClassBoard
    {
        $owner = $source->getOwner();
        $copy = new ClassBoard($owner, $this->naming->copyOf($owner, $source->getName()), $source->getProgram());
        $copy->setBackground($source->getBackground());
        $copy->setLayout($source->getLayout());

        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        // The drawings are copied once the copy has its id, which their keys carry.
        if ([] !== ClassBoardDrawings::keys($copy->getLayout())) {
            $this->drawings->copyInto($copy);
            $this->entityManager->flush();
        }

        return $copy;
    }
}
