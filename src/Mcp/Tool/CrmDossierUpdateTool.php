<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\CrmOrigin;
use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmSituation;
use App\Enum\Feature;
use App\Mcp\McpCrm;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\CrmOriginRepository;
use App\Service\Crm\CrmCandidateInput;
use App\Service\Crm\CrmDossierImport;
use App\Service\Crm\CrmDossierInput;
use App\Service\Crm\CrmDossierWriter;
use App\Service\Crm\CrmRefusal;
use App\Service\Crm\CrmSearch;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Modifies a dossier: the candidate's details, its correction (formation, option, path, origins,
 * prior schooling), its situation, its modality, the SFP status, the refund. Only the fields named
 * are written; nothing is written when one of them is refused.
 */
final readonly class CrmDossierUpdateTool implements McpTool
{
    public function __construct(
        private McpCrm $crm,
        private CrmDossierWriter $writer,
        private CrmDossierImport $import,
        private CrmOriginRepository $origins,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'crm_dossier_update';
    }

    public function title(): string
    {
        return 'Modifier un dossier d\'inscription';
    }

    public function description(): string
    {
        return 'Modifie un dossier du CRM des inscriptions, en ne touchant qu\'aux champs nommés : `candidate` (nom, prénom, mail, téléphone, étranger, responsables - pour tous ses dossiers), la correction (`programId`, `optionId`, `path`, `origins`, `priorSchooling` : les pièces restent, celles qui ne sont plus demandées passent en saisies conservées), `situation` (avec `situationReason` et `refundDue`), `modality` (avec `modalityDate` AAAA-MM-JJ et `modalityReason` : l\'historique est gardé), `sfp`, `refunded: true` (remboursement fait). Chaque changement est écrit au journal. Rien n\'est écrit si un champ est refusé. Pour les pièces : crm_pieces_set.';
    }

    public function inputSchema(): array
    {
        /** @var array<string, array<string, mixed>> $dossier */
        $dossier = McpCrm::dossierSchema(false)['properties'];

        return [
            'type' => 'object',
            'properties' => [
                'dossierId' => ['type' => 'integer'],
                'candidate' => ['type' => 'object', 'properties' => array_intersect_key($dossier, array_flip(['lastName', 'firstName', 'email', 'phone', 'foreign', 'guardians']))],
                'programId' => $dossier['programId'],
                'optionId' => $dossier['optionId'],
                'path' => $dossier['path'],
                'origins' => $dossier['origins'],
                'priorSchooling' => $dossier['priorSchooling'],
                'situation' => $dossier['situation'],
                'situationReason' => $dossier['situationReason'],
                'refundDue' => $dossier['refundDue'],
                'modality' => $dossier['modality'],
                'modalityDate' => ['type' => 'string'],
                'modalityReason' => ['type' => 'string'],
                'sfp' => $dossier['sfp'],
                'refunded' => ['type' => 'boolean'],
            ],
            'required' => ['dossierId'],
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::Crm];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $dossier = $this->crm->dossier($call->requiredId('dossierId'));
        $args = $call->arguments;
        $user = $call->user;

        try {
            if ($args->has('candidate')) {
                $c = $args->object('candidate');
                $candidate = $dossier->getCandidate();
                $this->writer->updateCandidate($candidate, CrmCandidateInput::of(
                    $c->has('lastName') ? $c->string('lastName') : $candidate->getLastName(),
                    $c->has('firstName') ? $c->string('firstName') : $candidate->getFirstName(),
                    $c->has('email') ? $c->string('email') : $candidate->getEmail(),
                    $c->has('phone') ? $c->string('phone') : $candidate->getPhone(),
                    $c->has('foreign') ? $c->bool('foreign') : $candidate->isForeignStudent(),
                    $c->has('guardians') ? array_map(static fn (JsonRequestPayload $g): array => ['lastName' => $g->string('lastName'), 'firstName' => $g->string('firstName'), 'email' => $g->string('email'), 'phone' => $g->string('phone')], $c->objects('guardians')) : $candidate->getGuardians(),
                ));
            }

            if ($args->has('programId') || $args->has('optionId') || $args->has('path') || $args->has('origins') || $args->has('priorSchooling')) {
                $program = $args->has('programId') ? $this->import->program($args->int('programId')) : $dossier->getProgram();
                if (null === $program) {
                    throw new McpToolException(\sprintf('La formation %s n\'est pas suivie dans une année ouverte du CRM.', $args->string('programId')));
                }
                $option = $dossier->getOption();
                if ($args->has('optionId')) {
                    $option = null;
                    foreach ($program->getOptions() as $candidateOption) {
                        if ($candidateOption->getId() === $args->int('optionId')) {
                            $option = $candidateOption;
                        }
                    }
                } elseif ($program !== $dossier->getProgram()) {
                    $option = null;
                }
                $path = $args->has('path') ? CrmPath::tryFrom($args->string('path')) : $dossier->getPath();
                if (null === $path) {
                    throw new McpToolException('`path` vaut enrolment ou reenrolment.');
                }
                $this->writer->correct($dossier, new CrmDossierInput(
                    $program,
                    $option,
                    $path,
                    null,
                    $args->has('origins') ? $this->origins($args->strings('origins')) : $dossier->getOrigins()->toArray(),
                    $args->has('priorSchooling') ? $args->string('priorSchooling') : $dossier->getPriorSchooling(),
                ), $user);
            }

            if ($args->has('modality')) {
                $modality = CrmModality::tryFrom($args->string('modality')) ?? throw new McpToolException('`modality` vaut '.implode(', ', CrmModality::values()).'.');
                $date = '' === $args->string('modalityDate') ? $this->writer->today() : (\DateTimeImmutable::createFromFormat('!Y-m-d', $args->string('modalityDate')) ?: throw new McpToolException('`modalityDate` s\'écrit AAAA-MM-JJ.'));
                $this->writer->changeModality($dossier, $modality, $date, $args->string('modalityReason'), $user);
            }
            if ($args->has('sfp')) {
                $this->writer->setSfp($dossier, $args->bool('sfp'), $user);
            }
            if ($args->has('situation')) {
                $situation = CrmSituation::tryFrom($args->string('situation')) ?? throw new McpToolException('`situation` vaut active, pending, waitlist, resigned ou cancelled.');
                $this->writer->changeSituation($dossier, $situation, $args->string('situationReason'), $args->bool('refundDue'), $user);
            }
            if ($args->bool('refunded')) {
                $this->writer->markRefunded($dossier, $user);
            }
        } catch (CrmRefusal $refusal) {
            throw $this->crm->refusal($refusal, 'Le dossier n\'est pas modifié :');
        }

        $this->entityManager->flush();

        return McpToolResult::data(\sprintf('Dossier de %s modifié : %s', $dossier->getCandidate()->getFullName(), $this->crm->url($dossier)), $this->crm->summary($dossier));
    }

    /**
     * @param list<string> $labels
     *
     * @return list<CrmOrigin>
     *
     * @throws McpToolException
     */
    private function origins(array $labels): array
    {
        $all = $this->origins->findBy([], ['position' => 'ASC']);
        $found = [];
        foreach ($labels as $label) {
            $match = array_values(array_filter($all, static fn (CrmOrigin $o): bool => CrmSearch::normalize($o->getLabel()) === CrmSearch::normalize($label)))[0] ?? null;
            if (null === $match) {
                throw new McpToolException(\sprintf('« %s » n\'est pas une origine de la liste : %s.', $label, implode(', ', array_map(static fn (CrmOrigin $o): string => $o->getLabel(), $all))));
            }
            $found[] = $match;
        }

        return $found;
    }
}
