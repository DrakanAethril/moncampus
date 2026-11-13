<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\CrmDossier;
use App\Entity\CrmPiece;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Enum\CrmJournalKind;
use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmSituation;
use App\Repository\CrmDossierRepository;
use App\Repository\CrmOriginRepository;
use App\Service\Crm\CrmDossierProgress;
use App\Service\Crm\CrmPieceValue;
use App\Service\Crm\CrmRefusal;
use App\Service\Crm\CrmSettings;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the CRM tools of the connector share: finding a dossier, the reference a document is
 * written against (formations, options, pieces, origins), a dossier as Claude reads it, and a
 * refusal turned into a tool error.
 *
 * The connector adds and modifies dossiers - students, never the configuration: years, formations
 * followed, pieces and origins are set in Réglages du CRM.
 */
final readonly class McpCrm
{
    public function __construct(
        private CrmSettings $settings,
        private CrmDossierRepository $dossiers,
        private CrmOriginRepository $origins,
        private McpLinks $links,
        private TranslatorInterface $translator,
    ) {
    }

    /** @throws McpToolException */
    public function dossier(int $id): CrmDossier
    {
        $dossier = $this->dossiers->find($id);
        $year = $dossier?->getProgram()->getSchoolYear();
        if (null === $dossier || null === $year || !$this->settings->isOpened($year)) {
            throw new McpToolException(\sprintf('Aucun dossier n°%d dans les années ouvertes du CRM.', $id));
        }

        return $dossier;
    }

    /** @throws McpToolException */
    public function year(?string $label): SchoolYear
    {
        foreach ($this->settings->openedYears() as $year) {
            if (null === $label || '' === trim($label) || CrmSettings::yearLabel($year) === trim($label) || (string) $year->getId() === trim($label)) {
                return null === $label || '' === trim($label) ? ($this->settings->defaultYear() ?? $year) : $year;
            }
        }

        throw new McpToolException(\sprintf('L\'année « %s » n\'est pas ouverte dans le CRM. Années ouvertes : %s.', $label, implode(', ', array_map(CrmSettings::yearLabel(...), $this->settings->openedYears())) ?: 'aucune'));
    }

    public function refusal(CrmRefusal $refusal, string $message): McpToolException
    {
        return new McpToolException($message, $refusal->texts($this->translator, 'fr'));
    }

    public function url(CrmDossier $dossier): string
    {
        return $this->links->url('app_crm_dossier', ['id' => $dossier->getId()]);
    }

    /** @return array<string, mixed> */
    public function reference(SchoolYear $year): array
    {
        return [
            'year' => CrmSettings::yearLabel($year),
            'openedYears' => array_map(CrmSettings::yearLabel(...), $this->settings->openedYears()),
            'programs' => array_map(fn (Program $p): array => [
                'programId' => $p->getId(),
                'shortName' => $p->getShortName(),
                'name' => $p->getName(),
                'section' => CrmSettings::sectionName($p),
                'defaultModality' => $this->settings->defaultModality($p)->value,
                'optionRequired' => $this->settings->asksOption($p),
                'options' => array_map(static fn ($o): array => ['optionId' => $o->getId(), 'shortName' => $o->getShortName(), 'name' => $o->getName()], $this->settings->optionsOf($p)),
                'hiddenPieces' => array_values(array_map(static fn (CrmPiece $piece): string => $piece->getCode(), array_filter($this->settings->catalogue(), fn (CrmPiece $piece): bool => 'hidden' === CrmDossierProgress::constraint($piece, $this->settings->settingsOf($p))->value))),
            ], $this->settings->followedPrograms($year)),
            'pieces' => array_map(static fn (CrmPiece $piece): array => [
                'code' => $piece->getCode(),
                'label' => $piece->getLabel(),
                'phase' => $piece->getPhase()->value,
                'countsTowardsCompletion' => $piece->getPhase()->countsTowardsCompletion(),
                'kind' => $piece->getKind()->value,
                'choices' => $piece->getChoices(),
                'modalities' => array_map(static fn (CrmModality $m): string => $m->value, $piece->getModalities()),
                'paths' => array_map(static fn (CrmPath $p): string => $p->value, $piece->getPaths()),
                'foreignOnly' => $piece->isForeignOnly(),
                'defaultConstraint' => $piece->defaultConstraint()->value,
                'futureDateAllowed' => $piece->isFutureDateAllowed(),
                'lastValueCompletes' => $piece->isLastValueCompletes(),
                'asksPaymentMode' => $piece->isAsksPaymentMode(),
            ], $this->settings->catalogue()),
            'origins' => array_map(static fn ($o): string => $o->getLabel(), $this->origins->findBy([], ['position' => 'ASC'])),
            'modalities' => array_map(static fn (CrmModality $m): array => ['value' => $m->value, 'label' => $m->label()], CrmModality::cases()),
            'paths' => array_map(static fn (CrmPath $p): array => ['value' => $p->value, 'label' => $p->label()], CrmPath::cases()),
            'situations' => array_map(static fn (CrmSituation $s): array => ['value' => $s->value, 'label' => $s->label()], CrmSituation::cases()),
            'journalKinds' => array_map(static fn (CrmJournalKind $k): string => $k->value, CrmJournalKind::teamKinds()),
            'paymentModes' => CrmPieceValue::PAYMENT_MODES,
        ];
    }

    /** @return array<string, mixed> */
    public function summary(CrmDossier $dossier): array
    {
        $progress = $this->settings->progress($dossier);

        return [
            'dossierId' => $dossier->getId(),
            'name' => $dossier->getCandidate()->getFullName(),
            'program' => CrmSettings::dossierProgramName($dossier),
            'year' => null === $dossier->getProgram()->getSchoolYear() ? null : CrmSettings::yearLabel($dossier->getProgram()->getSchoolYear()),
            'situation' => $dossier->getSituation()->value,
            'step' => $progress->step()->value,
            'url' => $this->url($dossier),
        ];
    }

    /** @return array<string, mixed> */
    public function describe(CrmDossier $dossier): array
    {
        $progress = $this->settings->progress($dossier);
        $candidate = $dossier->getCandidate();
        $piece = static fn ($entry): array => [
            'code' => $entry->getPiece()->getCode(),
            'label' => $entry->getPiece()->getLabel(),
            'value' => $entry->getValue(),
            'shown' => CrmPieceValue::format($entry->getPiece(), $entry->getValue()),
            'date' => $entry->getDoneOn()?->format('Y-m-d'),
            'paymentMode' => $entry->getPaymentMode(),
            'imported' => $entry->isImported(),
        ];

        return $this->summary($dossier) + [
            'candidate' => [
                'lastName' => $candidate->getLastName(),
                'firstName' => $candidate->getFirstName(),
                'email' => $candidate->getEmail(),
                'phone' => $candidate->getPhone(),
                'foreign' => $candidate->isForeignStudent(),
                'guardians' => $candidate->getGuardians(),
            ],
            'programId' => $dossier->getProgram()->getId(),
            'optionId' => $dossier->getOption()?->getId(),
            'path' => $dossier->getPath()->value,
            'modality' => $dossier->getModality()->value,
            'origins' => array_map(static fn ($o): string => $o->getLabel(), $dossier->getOrigins()->toArray()),
            'priorSchooling' => $dossier->getPriorSchooling(),
            'sfp' => $dossier->isSfp(),
            'refund' => $dossier->getRefund()?->value,
            'openedOn' => $dossier->getOpenedOn()->format('Y-m-d'),
            'pieces' => array_map(fn (CrmPiece $p): array => [
                'code' => $p->getCode(),
                'label' => $p->getLabel(),
                'phase' => $p->getPhase()->value,
                'constraint' => $progress->constraintOf($p)->value,
                'state' => $progress->isDone($p) ? 'done' : ($progress->isInProgress($p) ? 'in_progress' : 'todo'),
                'entry' => null === $progress->entry($p) ? null : $piece($progress->entry($p)),
            ], $progress->applicable()),
            'kept' => array_map($piece, $progress->kept()),
            'journal' => array_map(static fn ($line): array => [
                'date' => $line->getOccurredOn()->format('Y-m-d'),
                'kind' => $line->getKind()->value,
                'author' => $line->getAuthorName(),
                'text' => $line->getText(),
            ], $dossier->getJournal()->toArray()),
        ];
    }

    /**
     * The JSON Schema of a dossier as `crm_dossier_create` and each line of `crm_import` describe it.
     *
     * @return array<string, mixed>
     */
    public static function dossierSchema(bool $line): array
    {
        $properties = [
            'programId' => ['type' => 'integer', 'description' => 'La formation (programId de crm_reference) : une formation suivie d\'une année ouverte.'],
            'optionId' => ['type' => 'integer', 'description' => 'L\'option, obligatoire quand la formation la demande (optionRequired).'],
            'lastName' => ['type' => 'string'],
            'firstName' => ['type' => 'string'],
            'email' => ['type' => 'string', 'description' => 'Facultatif. Un mail ne sert qu\'à un dossier par formation.'],
            'phone' => ['type' => 'string'],
            'foreign' => ['type' => 'boolean', 'description' => 'Étudiant étranger, sous réserve de visa.'],
            'guardians' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'object', 'properties' => ['lastName' => ['type' => 'string'], 'firstName' => ['type' => 'string'], 'email' => ['type' => 'string'], 'phone' => ['type' => 'string']]]],
            'path' => ['type' => 'string', 'enum' => ['enrolment', 'reenrolment'], 'default' => 'enrolment'],
            'modality' => ['type' => 'string', 'enum' => CrmModality::values(), 'description' => 'Absente : la modalité par défaut de la formation.'],
            'situation' => ['type' => 'string', 'enum' => ['active', 'pending', 'waitlist', 'resigned', 'cancelled'], 'default' => 'active'],
            'situationReason' => ['type' => 'string'],
            'refundDue' => ['type' => 'boolean', 'description' => 'Situation close et acompte saisi : acompte à rembourser.'],
            'sfp' => ['type' => 'boolean', 'description' => 'Apprentissage : commence sous statut SFP.'],
            'origins' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Libellés de la liste des origines (crm_reference).'],
            'priorSchooling' => ['type' => 'string'],
            'pieces' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'code' => ['type' => 'string'],
                'value' => ['description' => 'Selon le type : rien (case), une des valeurs (choix, liste), un texte, un nombre d\'euros, {"start":"AAAA-MM-JJ","end":"AAAA-MM-JJ"} (période).'],
                'date' => ['type' => ['string', 'null'], 'description' => $line ? 'AAAA-MM-JJ ; absente ou null : fait, date inconnue (repris du classeur).' : 'AAAA-MM-JJ ; absente : aujourd\'hui.'],
                'paymentMode' => ['type' => 'string', 'description' => 'Acompte : Chèque, Virement, Carte bancaire, Espèces ou Prélèvement.'],
            ], 'required' => ['code']]],
            'notes' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['date' => ['type' => 'string'], 'text' => ['type' => 'string']], 'required' => ['text']], 'description' => 'Notes écrites au journal du dossier.'],
        ];
        if ($line) {
            $properties = ['ref' => ['type' => 'string', 'description' => 'Où est la ligne, rendu tel quel : « SIO 1, ligne 12 ».']] + $properties;
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $line ? ['ref', 'programId', 'lastName', 'firstName'] : ['programId', 'lastName', 'firstName']];
    }
}
