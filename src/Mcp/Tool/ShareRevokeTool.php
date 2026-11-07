<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpContentShares;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Retirer » and « Rétablir » on one of the teacher's own shares. Withdrawing deletes nothing: the
 * row stays (it still answers « à qui l'ai-je donné ? »), and what colleagues already duplicated is
 * theirs and is not touched - only access to the original goes.
 */
final readonly class ShareRevokeTool implements McpTool
{
    public function __construct(
        private McpContentShares $shares,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'share_revoke';
    }

    public function title(): string
    {
        return 'Retirer ou rétablir un partage';
    }

    public function description(): string
    {
        return 'Retire un partage de l\'enseignant (`shareId`, voir `share_list` avec `box: "mine"`) : ses destinataires ne lisent plus l\'original. Rien n\'est supprimé : les copies déjà dupliquées par les collègues leur restent, et `restore: true` rétablit le partage tel qu\'il était.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'shareId' => ['type' => 'integer'],
                'restore' => ['type' => 'boolean', 'description' => 'true pour rétablir un partage retiré.'],
            ],
            'required' => ['shareId'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::ContentSharing];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $share = $this->shares->ownedShare($call->requiredId('shareId'), $call->user);
        $restore = $call->arguments->bool('restore');

        $restore ? $share->restore() : $share->revoke();
        $this->entityManager->flush();

        return McpToolResult::data(
            \sprintf($restore ? 'Partage de « %s » rétabli.' : 'Partage de « %s » retiré ; les copies déjà faites restent à leurs auteurs.', $share->getSubjectTitle()),
            $this->shares->row($share, $call->user),
        );
    }
}
