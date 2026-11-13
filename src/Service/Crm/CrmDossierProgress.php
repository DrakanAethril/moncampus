<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\CrmDossier;
use App\Entity\CrmDossierPiece;
use App\Entity\CrmPiece;
use App\Entity\CrmProgram;
use App\Enum\CrmPhase;
use App\Enum\CrmPieceConstraint;
use App\Enum\CrmPieceKind;
use App\Enum\CrmStep;

/**
 * Where a dossier stands - the one reading of it, for the board, the dossier, the overview and the
 * connector alike. Nothing of it is stored.
 *
 * - A piece **applies** to a dossier when it is asked on its path, for its modality, of a foreign
 *   student only if the candidate is one, and its formation does not hide it.
 * - It is **done** once entered, except a list ending on a status (lastValueCompletes), which is
 *   done on its last value only and « en cours » before.
 * - The dossier's **step** is the first stage whose required pieces are not all done; a stage with
 *   nothing required is passed over; all done is « Complet ». Optional pieces never hold it back,
 *   nor do the « Documents de suivi » (CrmPhase::FollowUp), required or not.
 * - A dossier is **at** a stage while it is its step; it is at the follow-up documents while one
 *   applies to it and its required ones are not all done - whatever its step.
 * - What was entered for a piece that no longer applies (another modality, a hidden piece) is
 *   **kept**, shown apart, and no longer counts.
 */
final readonly class CrmDossierProgress
{
    /** @param list<CrmPiece> $applicable */
    private function __construct(
        private CrmDossier $dossier,
        private ?CrmProgram $settings,
        private array $applicable,
    ) {
    }

    /**
     * @param list<CrmPiece> $catalogue every piece, in the catalogue's order
     */
    public static function of(CrmDossier $dossier, ?CrmProgram $settings, array $catalogue): self
    {
        $foreign = $dossier->getCandidate()->isForeignStudent();
        $applicable = array_values(array_filter(
            $catalogue,
            static fn (CrmPiece $piece): bool => $piece->isOnPath($dossier->getPath())
                && $piece->isFor($dossier->getModality())
                && (!$piece->isForeignOnly() || $foreign)
                && CrmPieceConstraint::Hidden !== self::constraint($piece, $settings),
        ));

        return new self($dossier, $settings, $applicable);
    }

    /** A formation's own constraint for a piece, or the piece's default. */
    public static function constraint(CrmPiece $piece, ?CrmProgram $settings): CrmPieceConstraint
    {
        return $settings?->pieceConstraint($piece->getCode()) ?? $piece->defaultConstraint();
    }

    /** Whether an entry makes its piece done (see the class comment for the status lists). */
    public static function entryIsDone(CrmPiece $piece, ?CrmDossierPiece $entry): bool
    {
        if (null === $entry) {
            return false;
        }

        if (CrmPieceKind::List !== $piece->getKind() || !$piece->isLastValueCompletes()) {
            return true;
        }

        return $entry->getValue() === $piece->lastChoice();
    }

    public function dossier(): CrmDossier
    {
        return $this->dossier;
    }

    /** @return list<CrmPiece> */
    public function applicable(): array
    {
        return $this->applicable;
    }

    /** @return list<CrmPiece> */
    public function applicableIn(CrmPhase $phase): array
    {
        return array_values(array_filter($this->applicable, static fn (CrmPiece $p): bool => $phase === $p->getPhase()));
    }

    public function applies(CrmPiece $piece): bool
    {
        return \in_array($piece, $this->applicable, true);
    }

    public function constraintOf(CrmPiece $piece): CrmPieceConstraint
    {
        return self::constraint($piece, $this->settings);
    }

    public function isOptional(CrmPiece $piece): bool
    {
        return CrmPieceConstraint::Optional === $this->constraintOf($piece);
    }

    public function entry(CrmPiece $piece): ?CrmDossierPiece
    {
        return $this->dossier->entryFor($piece);
    }

    public function isDone(CrmPiece $piece): bool
    {
        return self::entryIsDone($piece, $this->entry($piece));
    }

    public function isInProgress(CrmPiece $piece): bool
    {
        return null !== $this->entry($piece) && !$this->isDone($piece);
    }

    /** @return array{done: int, total: int} the required pieces of a stage */
    public function phaseCount(CrmPhase $phase): array
    {
        $done = 0;
        $total = 0;
        foreach ($this->applicableIn($phase) as $piece) {
            if ($this->isOptional($piece)) {
                continue;
            }
            ++$total;
            if ($this->isDone($piece)) {
                ++$done;
            }
        }

        return ['done' => $done, 'total' => $total];
    }

    public function isPhaseDone(CrmPhase $phase): bool
    {
        $count = $this->phaseCount($phase);

        return $count['total'] > 0 && $count['done'] === $count['total'];
    }

    public function step(): CrmStep
    {
        foreach (CrmPhase::stages() as $phase) {
            $count = $this->phaseCount($phase);
            if ($count['done'] < $count['total']) {
                return $phase->step() ?? CrmStep::Complete;
            }
        }

        return CrmStep::Complete;
    }

    /** Whether the group concerns this dossier: a required piece for a stage, any piece for the follow-up. */
    public function concerns(CrmPhase $phase): bool
    {
        return $phase->countsTowardsCompletion() ? $this->phaseCount($phase)['total'] > 0 : [] !== $this->applicableIn($phase);
    }

    /** Whether the dossier waits on that group now (see the class comment). */
    public function isAt(CrmPhase $phase): bool
    {
        $step = $phase->step();

        return null !== $step ? $step === $this->step() : $this->concerns($phase) && !$this->isPhaseDone($phase);
    }

    /** @return list<CrmDossierPiece> entries of pieces that no longer apply, in the catalogue's order */
    public function kept(): array
    {
        $kept = array_values(array_filter(
            $this->dossier->getPieces()->toArray(),
            fn (CrmDossierPiece $entry): bool => !$this->applies($entry->getPiece()),
        ));
        usort($kept, static fn (CrmDossierPiece $a, CrmDossierPiece $b): int => $a->getPiece()->getPosition() <=> $b->getPiece()->getPosition());

        return $kept;
    }

    /** A foreign student whose visa is asked and not entered: not counted in the « effectif bas ». */
    public function visaPending(): bool
    {
        if (!$this->dossier->getCandidate()->isForeignStudent()) {
            return false;
        }

        foreach ($this->applicable as $piece) {
            if (CrmPiece::VISA === $piece->getCode()) {
                return null === $this->entry($piece);
            }
        }

        return false;
    }
}
