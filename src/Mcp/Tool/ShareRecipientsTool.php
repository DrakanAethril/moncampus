<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Enum\Feature;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Repository\UserRepository;
use App\Service\ContentShareAccess;
use App\Service\ContentShareAudience;

/**
 * Whom a share can name - the share modal's two pickers: colleagues found by name (only accounts
 * that could read a share, never a student, a tutor or an outside account), and the tree of groups,
 * each with **the measured count of who would read it**. The tree's root is « campus »: picking it
 * shares with everybody while looking like a small gesture, which is why the count is handed over
 * before the share is made, never estimated.
 */
final readonly class ShareRecipientsTool implements McpTool
{
    private const int MAX_USERS = 20;

    public function __construct(
        private UserRepository $users,
        private ContentShareAudience $audience,
    ) {
    }

    public function name(): string
    {
        return 'share_recipients';
    }

    public function title(): string
    {
        return 'Trouver à qui partager';
    }

    public function description(): string
    {
        return 'Donne les destinataires possibles d\'un partage entre collègues (`share_create`) : les collègues dont le nom contient `query` (enseignants et personnels seulement, 20 au plus, avec leur `userId`), et, avec `groups: true`, l\'arbre des équipes (`groupId`, nom, profondeur) avec, pour chacune, le nombre de personnes qui liraient le partage. La racine « campus » touche tout l\'établissement.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Une partie du nom ou de l\'identifiant du collègue cherché.'],
                'groups' => ['type' => 'boolean', 'description' => 'true pour recevoir aussi l\'arbre des équipes.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::ContentSharing];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $query = trim($call->arguments->string('query'));
        $colleagues = [];

        if ('' !== $query) {
            $matches = \array_slice($this->users->findActiveMatchingAnyRole(ContentShareAccess::READER_ROLES, [(int) $call->user->getId()], $query), 0, self::MAX_USERS);
            $colleagues = array_map(static fn (User $user): array => [
                'userId' => $user->getId(),
                'name' => $user->getDisplayName() ?? $user->getUsername(),
            ], $matches);
        }

        $groups = [];

        if ($call->arguments->bool('groups')) {
            foreach ($this->audience->pickableGroups() as $row) {
                $id = (int) $row['group']->getId();
                $groups[] = [
                    'groupId' => $id,
                    'name' => $row['group']->getName(),
                    'depth' => $row['depth'],
                    'readerCount' => $this->audience->memberCount([$id]),
                ];
            }
        }

        return McpToolResult::data(
            \sprintf('%d collègue(s), %d équipe(s).', \count($colleagues), \count($groups)),
            ['colleagues' => $colleagues, 'groups' => $groups],
        );
    }
}
