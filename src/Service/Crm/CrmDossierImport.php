<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\CrmDossier;
use App\Entity\CrmOrigin;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\CrmJournalKind;
use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmSituation;
use App\Repository\CrmDossierRepository;
use App\Repository\CrmOriginRepository;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Dossiers described by the Claude connector - one at a time (`crm_dossier_create`) or the lines
 * of the team's spreadsheet (`crm_import`), the import the user runs and talks over with Claude.
 *
 * Each line is read and checked **whole** before anything of it is written, so that a line that does
 * not pass leaves nothing behind and comes back with its reference and every reason at once. The
 * rules of the import (decided on 2026-11-11):
 *
 * - a candidate already in the same formation (same name, accents and case aside) is a line
 *   **ignored**, never an update - which is what makes a second import of the same file harmless;
 * - a mail serves one dossier per formation: one already held there, or given by two lines of the
 *   same formation, refuses the line(s);
 * - a line without a modality takes the formation's default, and its journal says so;
 * - a piece without a date is done, its date unknown (« repris du classeur »);
 * - an origin must be one of Réglages › Origines (the spreadsheet's unknown ones are « Inconnue »).
 */
final readonly class CrmDossierImport
{
    public const int MAX_ROWS = 300;

    public function __construct(
        private CrmSettings $settings,
        private CrmDossierWriter $writer,
        private CrmDossierRepository $dossiers,
        private CrmOriginRepository $origins,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Reads one dossier described by the connector.
     *
     * @param bool $imported a line of the spreadsheet: a piece without a date is done on a date unknown
     *
     * @throws CrmRefusal with every reason at once
     */
    public function plan(JsonRequestPayload $row, bool $imported): CrmDossierPlan
    {
        $reasons = [];
        $notices = [];

        $program = $this->program($row->int('programId'));
        if (null === $program) {
            $reasons[] = new TranslatableMessage('crmImportProgramUnknown', ['%id%' => $row->string('programId', '?')]);
        }

        $option = null;
        if (null !== $program && null !== ($optionId = $row->int('optionId'))) {
            foreach ($program->getOptions() as $candidate) {
                if ($candidate->getId() === $optionId) {
                    $option = $candidate;
                }
            }
            if (null === $option) {
                $reasons[] = new TranslatableMessage('crmImportOptionUnknown', ['%id%' => $optionId, '%program%' => $program->getShortName()]);
            }
        }

        $candidate = CrmCandidateInput::of(
            $row->string('lastName'),
            $row->string('firstName'),
            $row->string('email'),
            $row->string('phone'),
            $row->bool('foreign'),
            array_map(static fn (JsonRequestPayload $g): array => [
                'lastName' => $g->string('lastName'),
                'firstName' => $g->string('firstName'),
                'email' => $g->string('email'),
                'phone' => $g->string('phone'),
            ], $row->objects('guardians')),
        );
        $reasons = [...$reasons, ...$candidate->problems()];

        $path = $this->enum(CrmPath::class, $row->string('path', CrmPath::Enrolment->value), 'path', $reasons);
        $modality = '' === $row->string('modality') ? null : $this->enum(CrmModality::class, $row->string('modality'), 'modality', $reasons);
        $situation = $this->enum(CrmSituation::class, $row->string('situation', CrmSituation::Active->value), 'situation', $reasons);
        if (null === $modality && null !== $program && $imported) {
            $notices[] = \sprintf('Modalité %s : celle de la formation par défaut, le classeur ne la disait pas.', $this->settings->defaultModality($program)->label());
        }

        $origins = [];
        $known = $this->origins->findBy([], ['position' => 'ASC']);
        foreach ($row->strings('origins') as $label) {
            $found = null;
            foreach ($known as $origin) {
                if (CrmSearch::normalize($origin->getLabel()) === CrmSearch::normalize($label)) {
                    $found = $origin;
                }
            }
            if (null === $found) {
                $reasons[] = new TranslatableMessage('crmImportOriginUnknown', ['%origin%' => $label, '%origins%' => implode(', ', array_map(static fn (CrmOrigin $o): string => $o->getLabel(), $known))]);
            } elseif (!\in_array($found, $origins, true)) {
                $origins[] = $found;
            }
        }

        $today = $this->writer->today();
        $pieces = [];
        $seen = [];
        foreach ($row->objects('pieces') as $line) {
            $code = $line->string('code');
            $piece = $this->settings->piece($code);
            if (null === $piece) {
                $reasons[] = new TranslatableMessage('crmImportPieceUnknown', ['%code%' => $code]);
                continue;
            }
            if (isset($seen[$code])) {
                $reasons[] = new TranslatableMessage('crmImportPieceTwice', ['%piece%' => $piece->getLabel()]);
                continue;
            }
            $seen[$code] = true;
            try {
                $value = CrmPieceValue::parse($piece, $line->toArray()['value'] ?? null);
                $mode = $piece->isAsksPaymentMode() ? CrmPieceValue::paymentMode($line->string('paymentMode')) : null;
            } catch (CrmRefusal $refusal) {
                $reasons = [...$reasons, ...$refusal->reasons];
                continue;
            }
            $rawDate = $line->string('date');
            $date = null;
            if ('' !== $rawDate) {
                $iso = CrmPieceValue::isoDate($rawDate);
                if (null === $iso) {
                    $reasons[] = new TranslatableMessage('crmImportDateInvalid', ['%piece%' => $piece->getLabel(), '%date%' => $rawDate]);
                    continue;
                }
                $date = new \DateTimeImmutable($iso);
                if (!$piece->isFutureDateAllowed() && $date > $today) {
                    $reasons[] = new TranslatableMessage('crmRefusalFutureDate', ['%piece%' => $piece->getLabel()]);
                    continue;
                }
            }
            $pieces[] = ['piece' => $piece, 'value' => $value, 'date' => $date, 'dateUnknown' => null === $date && $imported, 'paymentMode' => $mode];
        }

        $notes = [];
        foreach ($row->objects('notes') as $note) {
            $text = trim($note->string('text'));
            if ('' === $text) {
                continue;
            }
            $rawDate = $note->string('date');
            $iso = '' === $rawDate ? null : CrmPieceValue::isoDate($rawDate);
            if ('' !== $rawDate && null === $iso) {
                $reasons[] = new TranslatableMessage('crmImportNoteDateInvalid', ['%date%' => $rawDate]);
                continue;
            }
            $notes[] = ['date' => null === $iso ? null : new \DateTimeImmutable($iso), 'text' => $text];
        }

        if (null !== $program) {
            if ($this->settings->asksOption($program) && null === $option) {
                $reasons[] = new TranslatableMessage('crmRefusalOptionMissing', ['%options%' => implode(' ou ', array_map(static fn (Option $o): string => $o->getShortName(), $this->settings->optionsOf($program)))]);
            }
            if (null !== $candidate->email && null !== ($holder = $this->dossiers->findHoldingMail($program, $candidate->email))) {
                $reasons[] = new TranslatableMessage('crmRefusalMailTaken', ['%name%' => $holder->getCandidate()->getFullName(), '%program%' => CrmDossierWriter::programAndYear($program)]);
            }
        }

        if ([] !== $reasons || null === $program || null === $path || null === $situation) {
            throw new CrmRefusal($reasons);
        }

        return new CrmDossierPlan(
            $candidate,
            new CrmDossierInput($program, $option, $path, $modality, $origins, '' === $row->string('priorSchooling') ? null : $row->string('priorSchooling')),
            $situation,
            '' === $row->string('situationReason') ? null : $row->string('situationReason'),
            $row->bool('refundDue'),
            $row->bool('sfp'),
            $pieces,
            $notes,
            $notices,
        );
    }

    /**
     * Writes a dossier planned. Never flushes.
     *
     * @throws CrmRefusal
     */
    public function write(CrmDossierPlan $plan, User $by, ?string $reference): CrmDossier
    {
        $dossier = $this->writer->open(
            $plan->candidate,
            $plan->placement,
            $by,
            null,
            null === $reference ? 'Dossier ouvert.' : \sprintf('Dossier repris du classeur, %s.', $reference),
        );
        foreach ($plan->notices as $notice) {
            $this->writer->journal($dossier, CrmJournalKind::System, $notice, $by);
        }
        foreach ($plan->pieces as $line) {
            $this->writer->enterPiece($dossier, $line['piece'], $line['value'], $line['date'], $line['paymentMode'], $by, null !== $reference, $line['dateUnknown']);
        }
        foreach ($plan->notes as $note) {
            $this->writer->journal($dossier, CrmJournalKind::Note, null === $note['date'] && null !== $reference ? $note['text'].' (repris du classeur, sans date)' : $note['text'], $by, $note['date']);
        }
        if (CrmSituation::Active !== $plan->situation) {
            $this->writer->changeSituation($dossier, $plan->situation, $plan->situationReason ?? (null === $reference ? null : 'Repris du classeur.'), $plan->refundDue, $by);
        }
        $this->writer->setSfp($dossier, $plan->sfp, $by);

        return $dossier;
    }

    /**
     * The lines of the team's spreadsheet. Every line is checked first; the ones that pass are then
     * written (unless `dryRun`), the others come back with their reference and reasons.
     *
     * @param list<JsonRequestPayload> $rows
     *
     * @return array{ready: list<array{ref: string, name: string, program: string, id?: int}>, errors: list<array{ref: string, name: string, reasons: list<string>}>, ignored: list<array{ref: string, name: string, reason: string}>}
     */
    public function import(array $rows, bool $dryRun, User $by): array
    {
        $result = ['ready' => [], 'errors' => [], 'ignored' => []];
        /** @var list<array{ref: string, name: string, plan: CrmDossierPlan}> $planned */
        $planned = [];
        $existing = [];

        foreach ($rows as $index => $row) {
            $ref = '' === trim($row->string('ref')) ? \sprintf('ligne %d de l\'envoi', $index + 1) : trim($row->string('ref'));
            $name = trim(mb_strtoupper($row->string('lastName')).' '.$row->string('firstName'));

            // Somebody already in the formation is ignored before anything else is read: the line
            // is not refused for a mail that is their own.
            $program = $this->program($row->int('programId'));
            $nameKey = self::nameKey($row->string('lastName'), $row->string('firstName'));
            if (null !== $program && '' !== trim($row->string('lastName')) && '' !== trim($row->string('firstName'))) {
                $key = (int) $program->getId();
                $existing[$key] ??= $this->namesIn($program);
                if (isset($existing[$key][$nameKey])) {
                    $result['ignored'][] = ['ref' => $ref, 'name' => $name, 'reason' => \sprintf('Déjà dans le CRM (%s) : la ligne est ignorée, le dossier n\'est pas mis à jour.', $existing[$key][$nameKey])];
                    continue;
                }
            }

            try {
                $plan = $this->plan($row, true);
            } catch (CrmRefusal $refusal) {
                $result['errors'][] = ['ref' => $ref, 'name' => $name, 'reasons' => $refusal->texts($this->translator, 'fr')];
                continue;
            }
            $planned[] = ['ref' => $ref, 'name' => $name, 'plan' => $plan];
        }

        // Within the send: two lines for one person in one formation, one mail given to two people.
        $byName = [];
        $byMail = [];
        foreach ($planned as $i => $line) {
            $program = (int) $line['plan']->placement->program->getId();
            $byName[$program.'|'.self::nameKey($line['plan']->candidate->lastName, $line['plan']->candidate->firstName)][] = $i;
            if (null !== $line['plan']->candidate->email) {
                $byMail[$program.'|'.$line['plan']->candidate->email][] = $i;
            }
        }
        $refused = [];
        foreach ($byName as $indexes) {
            foreach (\array_slice($indexes, 1) as $i) {
                $refused[$i][] = \sprintf('La même personne figure déjà dans cette formation à la ligne « %s » de l\'envoi.', $planned[$indexes[0]]['ref']);
            }
        }
        foreach ($byMail as $key => $indexes) {
            if (\count($indexes) > 1) {
                foreach ($indexes as $i) {
                    $refused[$i][] = \sprintf('L\'adresse %s est donnée à %d personnes de cette formation dans l\'envoi.', substr($key, strpos($key, '|') + 1), \count($indexes));
                }
            }
        }

        foreach ($planned as $i => $line) {
            if (isset($refused[$i])) {
                $result['errors'][] = ['ref' => $line['ref'], 'name' => $line['name'], 'reasons' => $refused[$i]];
                continue;
            }
            $ready = ['ref' => $line['ref'], 'name' => $line['name'], 'program' => CrmDossierWriter::programAndYear($line['plan']->placement->program)];
            if (!$dryRun) {
                try {
                    $ready['dossier'] = $this->write($line['plan'], $by, $line['ref']);
                } catch (CrmRefusal $refusal) {
                    $result['errors'][] = ['ref' => $line['ref'], 'name' => $line['name'], 'reasons' => $refusal->texts($this->translator, 'fr')];
                    continue;
                }
            }
            $result['ready'][] = $ready;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
            foreach ($result['ready'] as $k => $ready) {
                if (isset($ready['dossier'])) {
                    $result['ready'][$k]['id'] = (int) $ready['dossier']->getId();
                }
                unset($result['ready'][$k]['dossier']);
            }
        }

        return $result;
    }

    /** A followed formation of a year opened in the CRM. */
    public function program(?int $id): ?Program
    {
        if (null === $id) {
            return null;
        }
        $program = $this->entityManager->find(Program::class, $id);
        if (!$program instanceof Program || null === $program->getSchoolYear() || !$this->settings->isOpened($program->getSchoolYear()) || !$this->settings->isFollowed($program)) {
            return null;
        }

        return $program;
    }

    public static function nameKey(string $lastName, string $firstName): string
    {
        return preg_replace('/\s+/', ' ', CrmSearch::normalize(trim($lastName).' '.trim($firstName))) ?? '';
    }

    /** @return array<string, string> the names already in a formation, and their dossier's label */
    private function namesIn(Program $program): array
    {
        $names = [];
        foreach ($this->dossiers->forPrograms([$program]) as $dossier) {
            $names[self::nameKey($dossier->getCandidate()->getLastName(), $dossier->getCandidate()->getFirstName())] = CrmSettings::dossierProgramName($dossier).', dossier '.$dossier->getId();
        }

        return $names;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T>           $enum
     * @param list<TranslatableMessage> $reasons
     *
     * @return T|null
     */
    private function enum(string $enum, string $value, string $field, array &$reasons): ?\BackedEnum
    {
        $case = $enum::tryFrom($value);
        if (null === $case) {
            $reasons[] = new TranslatableMessage('crmImportValueUnknown', ['%field%' => $field, '%value%' => $value, '%values%' => implode(', ', array_map(static fn (\BackedEnum $c): string => (string) $c->value, $enum::cases()))]);
        }

        return $case;
    }
}
