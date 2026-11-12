<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\CrmCandidate;
use App\Entity\CrmDossier;
use App\Entity\CrmDossierPiece;
use App\Entity\CrmJournalEntry;
use App\Entity\CrmModalityChange;
use App\Entity\CrmPiece;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\CrmJournalKind;
use App\Enum\CrmModality;
use App\Enum\CrmPieceKind;
use App\Enum\CrmRefund;
use App\Enum\CrmSituation;
use App\Repository\CrmDossierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Every write on a dossier, for the screens, the connector and the import alike - and the journal
 * that goes with each: a line says what changed, in the words of the day, and is never rewritten.
 *
 * Rules held here and nowhere else:
 *
 * - **A student mail serves one dossier per formation** (a formation being one year's): the same
 *   mail in another formation is accepted, one already held in this formation by somebody else is
 *   refused. Nothing is ever attached to an existing candidate because of a mail or a name.
 * - A formation that asks for an option at pre-enrolment refuses a dossier without one, and an
 *   option must be one of the formation's.
 * - A dossier is opened in the formation's default modality unless another is named; changing it
 *   later keeps a history and leaves what was entered under the old one in « Saisies conservées ».
 * - A piece takes a date to come only if it allows one; a date unknown exists only for what was
 *   taken over from the team's spreadsheet.
 *
 * It never flushes: the caller does, once, so that a refusal leaves nothing half written.
 */
final readonly class CrmDossierWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CrmDossierRepository $dossiers,
        private CrmSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Paris'))->setTime(0, 0);
    }

    public static function nameOf(User $user): string
    {
        return $user->getDisplayName() ?? $user->getUsername();
    }

    /**
     * Opens a dossier for a new candidate.
     *
     * @throws CrmRefusal with every reason at once
     */
    public function open(CrmCandidateInput $candidateInput, CrmDossierInput $input, User $by, ?\DateTimeImmutable $openedOn = null, string $openingLine = 'Dossier ouvert.'): CrmDossier
    {
        $problems = [...$candidateInput->problems(), ...$this->placementProblems($input)];
        if (null !== $candidateInput->email && null !== ($holder = $this->dossiers->findHoldingMail($input->program, $candidateInput->email))) {
            $problems[] = $this->mailTaken($holder);
        }
        if ([] !== $problems) {
            throw new CrmRefusal($problems);
        }

        $candidate = new CrmCandidate($candidateInput->lastName, $candidateInput->firstName);
        $this->applyCandidate($candidate, $candidateInput);

        $today = $this->today();
        $modality = $input->modality ?? $this->settings->defaultModality($input->program);
        $dossier = new CrmDossier($candidate, $input->program, $input->path, $modality, $openedOn ?? $today, $by);
        $dossier->setOption($this->settings->asksOption($input->program) || null !== $input->option ? $input->option : null);
        $dossier->replaceOrigins($input->origins);
        $dossier->setPriorSchooling($input->priorSchooling);
        $dossier->addModalityChange(new CrmModalityChange($dossier, $modality, $openedOn ?? $today, $by, self::nameOf($by), null));
        $this->journal($dossier, CrmJournalKind::System, $openingLine, $by);

        $this->entityManager->persist($candidate);
        $this->entityManager->persist($dossier);

        return $dossier;
    }

    /**
     * Moves a dossier to another formation, option or path, or rewrites its origins and prior
     * schooling. The pieces stay; those the new place does not ask go to « Saisies conservées ».
     *
     * @return bool whether anything changed
     *
     * @throws CrmRefusal
     */
    public function correct(CrmDossier $dossier, CrmDossierInput $input, User $by): bool
    {
        $problems = $this->placementProblems($input, $dossier);
        $mail = $dossier->getCandidate()->getEmail();
        if ($input->program !== $dossier->getProgram() && null !== $mail && null !== ($holder = $this->dossiers->findHoldingMail($input->program, $mail, $dossier->getCandidate()))) {
            $problems[] = $this->mailTaken($holder);
        }
        if ([] !== $problems) {
            throw new CrmRefusal($problems);
        }

        $changes = [];
        if ($input->program !== $dossier->getProgram()) {
            $changes[] = \sprintf('Formation : %s → %s', $dossier->getProgram()->getShortName(), $input->program->getShortName());
            $dossier->setProgram($input->program);
        }
        $option = $this->settings->asksOption($input->program) || null !== $input->option ? $input->option : null;
        if ($option !== $dossier->getOption()) {
            if ($this->settings->asksOption($input->program)) {
                $changes[] = \sprintf('Option : %s → %s', $dossier->getOption()?->getShortName() ?? 'à préciser', $option?->getShortName() ?? 'à préciser');
            }
            $dossier->setOption($option);
        }
        if ($input->path !== $dossier->getPath()) {
            $changes[] = \sprintf('Parcours : %s → %s', $dossier->getPath()->label(), $input->path->label());
            $dossier->setPath($input->path);
        }
        $changes = [...$changes, ...$this->rewriteOrigins($dossier, $input->origins, $input->priorSchooling)];

        if ([] === $changes) {
            return false;
        }

        $this->journal($dossier, CrmJournalKind::System, 'Dossier corrigé. '.implode(' ; ', $changes).'.', $by);

        return true;
    }

    /**
     * The origins and the prior schooling alone - the head of a dossier edits them in place.
     *
     * @param list<\App\Entity\CrmOrigin> $origins
     */
    public function rewriteOriginAndPrior(CrmDossier $dossier, array $origins, ?string $priorSchooling, User $by): bool
    {
        $changes = $this->rewriteOrigins($dossier, $origins, $priorSchooling);
        if ([] === $changes) {
            return false;
        }
        $this->journal($dossier, CrmJournalKind::System, implode(' ; ', $changes).'.', $by);

        return true;
    }

    /**
     * The candidate's details, for all their dossiers.
     *
     * @throws CrmRefusal
     */
    public function updateCandidate(CrmCandidate $candidate, CrmCandidateInput $input): void
    {
        $problems = $input->problems();
        if (null !== $input->email) {
            foreach ($candidate->getDossiers() as $dossier) {
                $holder = $this->dossiers->findHoldingMail($dossier->getProgram(), $input->email, $candidate);
                if (null !== $holder) {
                    $problems[] = $this->mailTaken($holder);
                    break;
                }
            }
        }
        if ([] !== $problems) {
            throw new CrmRefusal($problems);
        }

        $this->applyCandidate($candidate, $input);
    }

    public function changeSituation(CrmDossier $dossier, CrmSituation $situation, ?string $reason, bool $refundDue, User $by): void
    {
        $reason = null === $reason ? '' : trim($reason);
        if ($situation !== $dossier->getSituation()) {
            $this->journal($dossier, CrmJournalKind::System, \sprintf('Situation : %s → %s.%s', $dossier->getSituation()->label(), $situation->label(), '' === $reason ? '' : ' '.$reason), $by);
            $dossier->setSituation($situation);
        }

        $deposit = $this->depositEntry($dossier);
        if ($refundDue && null === $dossier->getRefund() && null !== $deposit) {
            $dossier->setRefund(CrmRefund::Due);
            $this->journal($dossier, CrmJournalKind::System, \sprintf('Acompte de %s à rembourser.', CrmPieceValue::format($deposit->getPiece(), $deposit->getValue())), $by);
        }
    }

    public function markRefunded(CrmDossier $dossier, User $by): void
    {
        if (CrmRefund::Due !== $dossier->getRefund()) {
            return;
        }
        $dossier->setRefund(CrmRefund::Done);
        $this->journal($dossier, CrmJournalKind::System, 'Acompte remboursé.', $by);
    }

    public function changeModality(CrmDossier $dossier, CrmModality $modality, \DateTimeImmutable $effectiveOn, ?string $reason, User $by): void
    {
        if ($modality === $dossier->getModality()) {
            return;
        }

        $reason = null === $reason ? '' : trim($reason);
        $this->journal($dossier, CrmJournalKind::System, \sprintf('Modalité : %s → %s.%s', $dossier->getModality()->label(), $modality->label(), '' === $reason ? '' : ' '.$reason), $by);
        $dossier->addModalityChange(new CrmModalityChange($dossier, $modality, $effectiveOn, $by, self::nameOf($by), $reason));
        $dossier->setModality($modality);
        if (CrmModality::Apprenticeship !== $modality) {
            $dossier->setSfp(false);
        }
    }

    public function setSfp(CrmDossier $dossier, bool $sfp, User $by): void
    {
        if ($sfp === $dossier->isSfp() || ($sfp && CrmModality::Apprenticeship !== $dossier->getModality())) {
            return;
        }
        $dossier->setSfp($sfp);
        $this->journal($dossier, CrmJournalKind::System, $sfp ? 'Commence sous statut SFP (contrat pas encore signé).' : 'Fin du statut SFP.', $by);
    }

    /**
     * Enters a piece, or rewrites it.
     *
     * @param bool $dateUnknown only for what is taken over from the team's spreadsheet: done, its date unknown
     *
     * @throws CrmRefusal
     */
    public function enterPiece(CrmDossier $dossier, CrmPiece $piece, mixed $rawValue, ?\DateTimeImmutable $doneOn, ?string $paymentMode, User $by, bool $imported = false, bool $dateUnknown = false): CrmDossierPiece
    {
        $value = CrmPieceValue::parse($piece, $rawValue);
        $mode = $piece->isAsksPaymentMode() ? CrmPieceValue::paymentMode($paymentMode) : null;

        $date = $dateUnknown && $imported ? null : ($doneOn ?? $this->today());
        if (null !== $date && !$piece->isFutureDateAllowed() && $date > $this->today()) {
            throw CrmRefusal::because('crmRefusalFutureDate', ['%piece%' => $piece->getLabel()]);
        }

        $entry = $dossier->entryFor($piece);
        if (null === $entry) {
            $entry = new CrmDossierPiece($dossier, $piece, $date, $value, $by, $imported ? 'Repris du classeur' : self::nameOf($by));
            $dossier->addEntry($entry);
            $this->entityManager->persist($entry);
        } else {
            $entry->rewrite($date, $value, $by, $imported ? 'Repris du classeur' : self::nameOf($by));
        }
        $entry->setPaymentMode($mode);
        $entry->setImported($imported);

        $shown = CrmPieceValue::format($piece, $value);
        $text = CrmPieceKind::List === $piece->getKind()
            ? \sprintf('« %s » : %s.', $piece->getLabel(), $shown)
            : \sprintf('« %s » marqué fait%s%s.', $piece->getLabel(), '' === $shown ? '' : ' : '.$shown, null === $mode ? '' : ' ('.mb_strtolower($mode).')');
        $this->journal($dossier, CrmJournalKind::Entry, $text, $by, null, $piece);

        return $entry;
    }

    public function clearPiece(CrmDossier $dossier, CrmPiece $piece, User $by): bool
    {
        $entry = $dossier->entryFor($piece);
        if (null === $entry) {
            return false;
        }
        $dossier->removeEntry($entry);
        $this->journal($dossier, CrmJournalKind::Entry, \sprintf('« %s » retiré.', $piece->getLabel()), $by, null, $piece);

        return true;
    }

    /** @throws CrmRefusal */
    public function addNote(CrmDossier $dossier, CrmJournalKind $kind, string $text, User $by, ?\DateTimeImmutable $occurredOn = null): CrmJournalEntry
    {
        $text = trim($text);
        if ('' === $text) {
            throw CrmRefusal::because('crmRefusalNoteEmpty');
        }
        if (!\in_array($kind, CrmJournalKind::teamKinds(), true)) {
            $kind = CrmJournalKind::Note;
        }

        return $this->journal($dossier, $kind, $text, $by, $occurredOn);
    }

    /** Deletes a dossier, and its candidate with it when they have no other one. */
    public function delete(CrmDossier $dossier): void
    {
        $candidate = $dossier->getCandidate();
        $candidate->getDossiers()->removeElement($dossier);
        $this->entityManager->remove($dossier);
        if ($candidate->getDossiers()->isEmpty()) {
            $this->entityManager->remove($candidate);
        }
    }

    public function journal(CrmDossier $dossier, CrmJournalKind $kind, string $text, User $by, ?\DateTimeImmutable $occurredOn = null, ?CrmPiece $piece = null): CrmJournalEntry
    {
        $entry = new CrmJournalEntry($dossier, $kind, $text, $occurredOn ?? $this->today(), $by, self::nameOf($by), $piece);
        $dossier->addJournalEntry($entry);
        $this->entityManager->persist($entry);

        return $entry;
    }

    /** @return list<TranslatableMessage> */
    private function placementProblems(CrmDossierInput $input, ?CrmDossier $dossier = null): array
    {
        $problems = [];
        if ($this->settings->asksOption($input->program) && null === $input->option) {
            $problems[] = new TranslatableMessage('crmRefusalOptionMissing', ['%options%' => implode(' ou ', array_map(static fn ($o): string => $o->getShortName(), $this->settings->optionsOf($input->program)))]);
        }
        if (null !== $input->option && !$input->program->getOptions()->contains($input->option)) {
            $problems[] = new TranslatableMessage('crmRefusalOptionForeign', ['%option%' => $input->option->getShortName(), '%program%' => $input->program->getShortName()]);
        } elseif (null !== $input->option && $this->settings->asksOption($input->program) && !\in_array($input->option, $this->settings->optionsOf($input->program), true)) {
            // An option no longer followed is kept by the dossier that already carries it, and
            // given to no other.
            $kept = null !== $dossier && $dossier->getProgram() === $input->program && $dossier->getOption() === $input->option;
            if (!$kept) {
                $problems[] = new TranslatableMessage('crmRefusalOptionUntracked', ['%option%' => $input->option->getShortName(), '%program%' => $input->program->getShortName(), '%options%' => implode(' ou ', array_map(static fn ($o): string => $o->getShortName(), $this->settings->optionsOf($input->program)))]);
            }
        }

        return $problems;
    }

    private function mailTaken(CrmDossier $holder): TranslatableMessage
    {
        return new TranslatableMessage('crmRefusalMailTaken', [
            '%name%' => $holder->getCandidate()->getFullName(),
            '%program%' => self::programAndYear($holder->getProgram()),
        ]);
    }

    public static function programAndYear(Program $program): string
    {
        return $program->getShortName().(null !== $program->getSchoolYear() ? ' '.CrmSettings::yearLabel($program->getSchoolYear()) : '');
    }

    private function applyCandidate(CrmCandidate $candidate, CrmCandidateInput $input): void
    {
        $candidate->setLastName($input->lastName);
        $candidate->setFirstName($input->firstName);
        $candidate->setEmail($input->email);
        $candidate->setPhone($input->phone);
        $candidate->setForeignStudent($input->foreignStudent);
        $candidate->setGuardians($input->guardians);
    }

    /**
     * @param list<\App\Entity\CrmOrigin> $origins
     *
     * @return list<string>
     */
    private function rewriteOrigins(CrmDossier $dossier, array $origins, ?string $priorSchooling): array
    {
        $changes = [];
        $before = $dossier->originText();
        $dossier->replaceOrigins($origins);
        $after = $dossier->originText();
        if ($before !== $after) {
            $changes[] = \sprintf('Origine : %s → %s', '' === $before ? 'non renseignée' : $before, '' === $after ? 'non renseignée' : $after);
        }

        $prior = null === $priorSchooling || '' === trim($priorSchooling) ? null : trim($priorSchooling);
        if ($prior !== $dossier->getPriorSchooling()) {
            $changes[] = \sprintf('Parcours antérieur : %s → %s', $dossier->getPriorSchooling() ?? 'non renseigné', $prior ?? 'non renseigné');
            $dossier->setPriorSchooling($prior);
        }

        return $changes;
    }

    private function depositEntry(CrmDossier $dossier): ?CrmDossierPiece
    {
        foreach ($dossier->getPieces() as $entry) {
            if (CrmPiece::DEPOSIT === $entry->getPiece()->getCode()) {
                return $entry;
            }
        }

        return null;
    }
}
