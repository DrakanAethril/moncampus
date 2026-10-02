<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

use App\Entity\User;
use App\Repository\ClassBoardRepository;
use App\Service\GroupBatchNaming;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The names of a person's boards, the rule of the saved draws (design/validated/tableau-virtuel.md,
 * §4): a name proposed at creation or given to a copy is *numbered* when taken, a rename to a taken
 * name is *refused*. Two boards of one owner never share a name - a UNIQUE index says so.
 */
final class ClassBoardNaming
{
    public function __construct(
        private readonly ClassBoardRepository $repository,
        private readonly GroupBatchNaming $naming,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * « Tableau du 1er octobre » - the name the creation panel proposes.
     */
    public function proposed(\DateTimeImmutable $day, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMMM');
        $dayOfMonth = (int) $day->format('j');
        $dayLabel = 'fr' === $locale && 1 === $dayOfMonth ? '1er' : (string) $dayOfMonth;

        return $this->translator->trans('classBoardProposedName', [
            '%day%' => $dayLabel,
            '%month%' => (string) $formatter->format($day),
        ]);
    }

    /**
     * $desired, or « $desired (2) » when the owner already has a board by that name.
     */
    public function unique(User $owner, string $desired): string
    {
        return $this->naming->unique($desired, $this->repository->findNamesForOwner($owner));
    }

    public function isTaken(User $owner, string $name, ?int $exceptBoardId = null): bool
    {
        $folded = mb_strtolower(trim($name));
        foreach ($this->repository->findForOwner($owner) as $board) {
            if ($board->getId() !== $exceptBoardId && mb_strtolower(trim($board->getName())) === $folded) {
                return true;
            }
        }

        return false;
    }

    public function copyOf(User $owner, string $name): string
    {
        return $this->unique($owner, $this->translator->trans('classBoardCopyName', ['%name%' => $name]));
    }
}
