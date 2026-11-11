<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\CrmJournalKind;
use App\Enum\Feature;
use App\Mcp\McpCrm;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Service\Crm\CrmDossierWriter;
use App\Service\Crm\CrmPieceValue;
use App\Service\Crm\CrmRefusal;
use Doctrine\ORM\EntityManagerInterface;

/** A note, a call, a mail or a meeting in a dossier's journal. */
final readonly class CrmJournalAddTool implements McpTool
{
    public function __construct(private McpCrm $crm, private CrmDossierWriter $writer, private EntityManagerInterface $entityManager)
    {
    }

    public function name(): string
    {
        return 'crm_journal_add';
    }

    public function title(): string
    {
        return 'Écrire au journal d\'un dossier d\'inscription';
    }

    public function description(): string
    {
        return 'Ajoute une ligne au journal d\'un dossier du CRM des inscriptions : `kind` note, call (appel), mail ou meeting (rendez-vous), `text`, et `date` AAAA-MM-JJ (aujourd\'hui par défaut).';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'dossierId' => ['type' => 'integer'],
            'kind' => ['type' => 'string', 'enum' => ['note', 'call', 'mail', 'meeting'], 'default' => 'note'],
            'text' => ['type' => 'string'],
            'date' => ['type' => 'string'],
        ], 'required' => ['dossierId', 'text']];
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
        $iso = CrmPieceValue::isoDate($call->arguments->string('date'));
        try {
            $this->writer->addNote($dossier, CrmJournalKind::tryFrom($call->arguments->string('kind')) ?? CrmJournalKind::Note, $call->arguments->string('text'), $call->user, null === $iso ? null : new \DateTimeImmutable($iso));
        } catch (CrmRefusal $refusal) {
            throw $this->crm->refusal($refusal, 'Rien n\'est écrit au journal :');
        }
        $this->entityManager->flush();

        return McpToolResult::data(\sprintf('Ajouté au journal de %s.', $dossier->getCandidate()->getFullName()), $this->crm->summary($dossier));
    }
}
