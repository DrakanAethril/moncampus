<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\CrmDossierPiece;
use App\Entity\CrmJournalEntry;
use App\Entity\CrmPiece;
use App\Enum\CrmPieceKind;
use App\Repository\CrmPieceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The piece catalogue's rules (Réglages › Pièces du dossier):
 *
 * - A piece is edited whole until it has a **history** - a value entered in a dossier, or a journal
 *   line about it. Then its kind is frozen, the values already chosen stay in its list, and it is
 *   no longer deleted (it is taken off a modality instead).
 * - The deposit, the NPEC and the visa keep their kind for ever: the CRM reads them.
 * - A list has at least two values; a piece is asked for at least one modality.
 *
 * It never flushes.
 */
final readonly class CrmPieceEditor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CrmPieceRepository $pieces,
        private CrmSettings $settings,
    ) {
    }

    /** How many dossiers hold a value of the piece or a journal line about it. */
    public function historyCount(CrmPiece $piece): int
    {
        $dossiers = [];
        foreach ($this->entityManager->getRepository(CrmDossierPiece::class)->findBy(['piece' => $piece]) as $entry) {
            $dossiers[(int) $entry->getDossier()->getId()] = true;
        }
        foreach ($this->entityManager->getRepository(CrmJournalEntry::class)->findBy(['piece' => $piece]) as $line) {
            $dossiers[(int) $line->getDossier()->getId()] = true;
        }

        return \count($dossiers);
    }

    /** @return list<string> the values chosen in some dossier - which therefore stay in the list */
    public function usedChoices(CrmPiece $piece): array
    {
        $used = [];
        foreach ($this->entityManager->getRepository(CrmDossierPiece::class)->findBy(['piece' => $piece]) as $entry) {
            if (\is_string($entry->getValue())) {
                $used[$entry->getValue()] = true;
            }
        }

        return array_keys($used);
    }

    public function isKindFrozen(CrmPiece $piece): bool
    {
        return $piece->isSystem() || $this->historyCount($piece) > 0;
    }

    /** @throws CrmRefusal */
    public function create(CrmPieceInput $input): CrmPiece
    {
        $this->check($input, null);
        $kind = CrmPieceKind::Choice === $input->kind ? CrmPieceKind::List : $input->kind;
        $last = 0;
        foreach ($this->settings->catalogue() as $piece) {
            $last = max($last, $piece->getPosition());
        }

        $piece = new CrmPiece($this->freeCode($input->label), trim($input->label), self::short($input), $input->phase, $kind, $input->modalities, $input->paths, $last + 1);
        $this->apply($piece, $input, $kind);
        $this->entityManager->persist($piece);
        $this->settings->forgetCatalogue();

        return $piece;
    }

    /** @throws CrmRefusal */
    public function update(CrmPiece $piece, CrmPieceInput $input): void
    {
        $kind = $this->isKindFrozen($piece) ? $piece->getKind() : $input->kind;
        $this->check($input, $piece, $kind);

        $piece->setLabel(trim($input->label));
        $piece->setShortLabel(self::short($input));
        $piece->setPhase($input->phase);
        $piece->setKind($kind);
        $piece->setModalities($input->modalities);
        $piece->setPaths($input->paths);
        $this->apply($piece, $input, $kind);
        $this->settings->forgetCatalogue();
    }

    /** @throws CrmRefusal */
    public function delete(CrmPiece $piece): void
    {
        $count = $this->historyCount($piece);
        if ($piece->isSystem() || $count > 0) {
            throw CrmRefusal::because('crmRefusalPieceHasHistory', ['%piece%' => $piece->getLabel()]);
        }
        $this->entityManager->remove($piece);
        $this->settings->forgetCatalogue();
    }

    /** @throws CrmRefusal */
    private function check(CrmPieceInput $input, ?CrmPiece $piece, ?CrmPieceKind $kind = null): void
    {
        $kind ??= $input->kind;
        $problems = [];
        if ('' === trim($input->label)) {
            $problems[] = new TranslatableMessage('crmRefusalPieceLabelMissing');
        }
        if ([] === $input->modalities) {
            $problems[] = new TranslatableMessage('crmRefusalPieceModalityMissing');
        }
        if ([] === $input->paths) {
            $problems[] = new TranslatableMessage('crmRefusalPiecePathMissing');
        }
        if ($kind->hasChoices()) {
            $choices = array_values(array_unique(array_filter(array_map('trim', $input->choices), static fn (string $c): bool => '' !== $c)));
            if (\count($choices) < 2) {
                $problems[] = new TranslatableMessage('crmRefusalPieceChoicesTooFew');
            }
            if (null !== $piece) {
                $lost = array_values(array_diff($this->usedChoices($piece), $choices));
                if ([] !== $lost) {
                    $problems[] = new TranslatableMessage('crmRefusalPieceChoicesUsed', ['%values%' => implode(', ', $lost)]);
                }
            }
        }
        if ([] !== $problems) {
            throw new CrmRefusal($problems);
        }
    }

    private function apply(CrmPiece $piece, CrmPieceInput $input, CrmPieceKind $kind): void
    {
        $piece->setForeignOnly($input->foreignOnly);
        $piece->setDefaultConstraint($input->defaultConstraint);
        $piece->setFutureDateAllowed($input->futureDateAllowed);
        $piece->setChoices($kind->hasChoices() ? $input->choices : []);
        $piece->setLastValueCompletes(CrmPieceKind::List === $kind && $input->lastValueCompletes);
        $piece->setAsksPaymentMode(CrmPieceKind::Amount === $kind && ($input->asksPaymentMode || CrmPiece::DEPOSIT === $piece->getCode()));
    }

    private static function short(CrmPieceInput $input): string
    {
        $short = trim($input->shortLabel);
        if ('' !== $short) {
            return mb_substr($short, 0, 30);
        }
        $label = trim($input->label);

        return mb_strlen($label) > 12 ? mb_substr($label, 0, 11).'.' : $label;
    }

    private function freeCode(string $label): string
    {
        $base = mb_substr((new AsciiSlugger('fr'))->slug($label, '_')->lower()->toString(), 0, 56);
        $base = '' === $base ? 'piece' : $base;
        $code = $base;
        for ($i = 2; null !== $this->pieces->findOneBy(['code' => $code]); ++$i) {
            $code = $base.'_'.$i;
        }

        return $code;
    }
}
