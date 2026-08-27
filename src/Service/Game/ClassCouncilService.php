<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\ClassCouncilMention;
use App\Entity\EvaluationPeriod;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\CouncilMention;
use App\Repository\ClassCouncilMentionRepository;
use App\Repository\GameEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The class council's mentions, and the one moment they turn into points.
 *
 * **Nothing is credited while the council is open.** Thirty students are entered in one pass while
 * the council deliberates, everything is corrected in place, and the points are inserted in a single
 * gesture when the council is closed - at which point the mentions stop moving. Crediting as the
 * teacher types would mean a ranking moving during a deliberation, which is the one thing screen 6
 * exists to prevent: the council feeds the game, the game must never weigh on the council.
 *
 * Re-opening a closed council is an administrator's act. The mentions become editable again and the
 * points are undone by inverse lines, never by a recount - a student who saw their hundred points
 * arrive has to be able to see them leave (§9).
 */
final class ClassCouncilService
{
    private const string SOURCE = 'ClassCouncilMention';

    public function __construct(
        private readonly ClassCouncilMentionRepository $mentions,
        private readonly GameEntryRepository $entries,
        private readonly GameLedger $ledger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * One student's row of the council, created on first touch.
     *
     * A row exists only once somebody has said something about that student, which is what makes
     * « 18 / 30 saisies » a real count rather than always thirty.
     */
    public function stateMention(Program $program, EvaluationPeriod $period, User $student, CouncilMention $mention, User $author): ?ClassCouncilMention
    {
        $row = $this->mentions->findOneFor($student, $period);

        if (null !== $row && $row->isLocked()) {
            return null;
        }

        if (null === $row) {
            $row = new ClassCouncilMention($student, $program, $period);
            $row->setCreatedBy($author);
            $this->entityManager->persist($row);
        }

        $row->setMention($mention);
        $row->setLastUpdatedBy($author);
        $row->setLastUpdatedDate(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $row;
    }

    public function stateComment(Program $program, EvaluationPeriod $period, User $student, ?string $comment, User $author): ?ClassCouncilMention
    {
        $row = $this->mentions->findOneFor($student, $period);

        if (null !== $row && $row->isLocked()) {
            return null;
        }

        if (null === $row) {
            $row = new ClassCouncilMention($student, $program, $period);
            $row->setCreatedBy($author);
            $this->entityManager->persist($row);
        }

        $row->setComment(null === $comment ? null : trim($comment));
        $row->setLastUpdatedBy($author);
        $row->setLastUpdatedDate(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $row;
    }

    /**
     * Close the council: lock every mention and insert the points, once.
     *
     * Idempotent through the ledger's own refusal on (sourceType, sourceId, ruleCode) - closing a
     * council twice credits nobody twice.
     *
     * @return int how many mentions were credited
     */
    public function close(Program $program, EvaluationPeriod $period, ?\DateTimeImmutable $at = null): int
    {
        $at ??= new \DateTimeImmutable();
        $credited = 0;

        foreach ($this->mentions->findForPeriod($program, $period) as $row) {
            $row->lock($at);

            $points = $row->getMention()->points();

            if ($points <= 0) {
                // « Aucune mention » and « avertissement » are both worth zero, and a zero-point
                // line in a student's journal reads as a bug rather than as a decision.
                continue;
            }

            $entry = $this->ledger->record(
                $row->getStudent(),
                $program,
                $period,
                GameRuleCatalog::RECOGNITION_COUNCIL,
                self::SOURCE,
                (int) $row->getId(),
                $at,
                $points,
            );

            if (null !== $entry) {
                ++$credited;
            }
        }

        $this->entityManager->flush();

        return $credited;
    }

    /**
     * Re-open a closed council - an administrator's act, traced by AuditableTrait on each row.
     *
     * The points already inserted are undone by inverse lines here and re-inserted by the next
     * close(), which is why the ledger's refusal is keyed on the rule and the source: the reversal
     * carries the same pair, and what the sum reads is the net.
     */
    public function reopen(Program $program, EvaluationPeriod $period, User $author): void
    {
        foreach ($this->mentions->findForPeriod($program, $period) as $row) {
            if (!$row->isLocked()) {
                continue;
            }

            $row->unlock();
            $row->setLastUpdatedBy($author);
            $row->setLastUpdatedDate(new \DateTimeImmutable());

            foreach ($this->entries->findBySource($row->getStudent(), self::SOURCE, (int) $row->getId(), GameRuleCatalog::RECOGNITION_COUNCIL) as $entry) {
                if (null === $this->entries->findReversalOf($entry) && !$entry->isReversal()) {
                    $this->ledger->reverse($entry, $author, null);
                }
            }
        }

        $this->entityManager->flush();
    }

    public function isClosed(Program $program, EvaluationPeriod $period): bool
    {
        foreach ($this->mentions->findForPeriod($program, $period) as $row) {
            if ($row->isLocked()) {
                return true;
            }
        }

        return false;
    }
}
