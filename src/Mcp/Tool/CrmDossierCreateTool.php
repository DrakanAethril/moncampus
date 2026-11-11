<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpCrm;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Service\Crm\CrmDossierImport;
use App\Service\Crm\CrmRefusal;
use Doctrine\ORM\EntityManagerInterface;

/** « Nouveau dossier » through the connector: the screen's rules, every reason of a refusal at once. */
final readonly class CrmDossierCreateTool implements McpTool
{
    public function __construct(
        private McpCrm $crm,
        private CrmDossierImport $import,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'crm_dossier_create';
    }

    public function title(): string
    {
        return 'Ouvrir un dossier d\'inscription';
    }

    public function description(): string
    {
        return 'Ouvre un dossier dans le CRM des inscriptions pour un nouveau candidat : formation (et option si elle est demandée), coordonnées, responsables, parcours, modalité (par défaut celle de la formation), origines, parcours antérieur, et au besoin les pièces déjà faites et des notes de journal. Le dossier n\'est jamais rattaché à un candidat existant ; un mail déjà pris dans la formation est refusé. Les identifiants et les codes viennent de crm_reference. Le refus donne toutes les raisons à la fois.';
    }

    public function inputSchema(): array
    {
        return McpCrm::dossierSchema(false);
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
        try {
            $dossier = $this->import->write($this->import->plan($call->arguments, false), $call->user, null);
        } catch (CrmRefusal $refusal) {
            throw $this->crm->refusal($refusal, 'Le dossier n\'est pas ouvert :');
        }
        $this->entityManager->flush();

        return McpToolResult::data(
            \sprintf('Dossier ouvert pour %s : %s', $dossier->getCandidate()->getFullName(), $this->crm->url($dossier)),
            $this->crm->summary($dossier),
            ['kind' => 'crm_dossier', 'id' => (int) $dossier->getId()],
        );
    }
}
