<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpCrm;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Service\Crm\CrmDossierWriter;
use App\Service\Crm\CrmPieceValue;
use App\Service\Crm\CrmRefusal;
use App\Service\Crm\CrmSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enters or rewrites pieces of a dossier - the dossier's « Marquer fait », by code. Removing an
 * entry stays a gesture of the screen: nothing is deleted through the connector.
 */
final readonly class CrmPiecesSetTool implements McpTool
{
    public function __construct(
        private McpCrm $crm,
        private CrmSettings $settings,
        private CrmDossierWriter $writer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'crm_pieces_set';
    }

    public function title(): string
    {
        return 'Saisir des pièces d\'un dossier d\'inscription';
    }

    public function description(): string
    {
        return 'Saisit ou réécrit des pièces d\'un dossier du CRM des inscriptions, désignées par leur code (crm_reference). `value` selon le type de la pièce, `date` AAAA-MM-JJ (aujourd\'hui par défaut ; une date à venir seulement si la pièce le permet), `paymentMode` pour l\'acompte. Tout est vérifié avant d\'écrire : à la première pièce refusée, rien n\'est écrit. Chaque saisie est écrite au journal.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dossierId' => ['type' => 'integer'],
                'pieces' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'code' => ['type' => 'string'],
                    'value' => ['description' => 'Rien (case), une des valeurs (choix, liste), un texte, un nombre d\'euros, {"start","end"} (période).'],
                    'date' => ['type' => 'string'],
                    'paymentMode' => ['type' => 'string'],
                ], 'required' => ['code']]],
            ],
            'required' => ['dossierId', 'pieces'],
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
        $lines = $call->arguments->objects('pieces');
        if ([] === $lines) {
            throw new McpToolException('`pieces` est vide.');
        }

        $problems = [];
        $plan = [];
        foreach ($lines as $line) {
            $piece = $this->settings->piece($line->string('code'));
            if (null === $piece) {
                $problems[] = \sprintf('« %s » n\'est pas un code de pièce (crm_reference).', $line->string('code'));
                continue;
            }
            $date = null;
            if ('' !== $line->string('date')) {
                $iso = CrmPieceValue::isoDate($line->string('date'));
                if (null === $iso) {
                    $problems[] = \sprintf('« %s » : la date « %s » s\'écrit AAAA-MM-JJ.', $piece->getLabel(), $line->string('date'));
                    continue;
                }
                $date = new \DateTimeImmutable($iso);
            }
            try {
                CrmPieceValue::parse($piece, $line->toArray()['value'] ?? null);
                if ($piece->isAsksPaymentMode()) {
                    CrmPieceValue::paymentMode($line->string('paymentMode'));
                }
                if (null !== $date && !$piece->isFutureDateAllowed() && $date > $this->writer->today()) {
                    throw CrmRefusal::because('crmRefusalFutureDate', ['%piece%' => $piece->getLabel()]);
                }
            } catch (CrmRefusal $refusal) {
                $problems = [...$problems, ...$this->crm->refusal($refusal, '')->details];
                continue;
            }
            $plan[] = [$piece, $line, $date];
        }
        if ([] !== $problems) {
            throw new McpToolException('Aucune pièce n\'est saisie :', $problems);
        }

        foreach ($plan as [$piece, $line, $date]) {
            $this->writer->enterPiece($dossier, $piece, $line->toArray()['value'] ?? null, $date, $line->string('paymentMode'), $call->user);
        }
        $this->entityManager->flush();

        $summary = $this->crm->summary($dossier);

        return McpToolResult::data(\sprintf('%d pièce(s) saisie(s) pour %s ; étape : %s.', \count($plan), $dossier->getCandidate()->getFullName(), $this->settings->progress($dossier)->step()->label()), $summary);
    }
}
