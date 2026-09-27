<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Repository\ProgramRepository;
use App\Security\FeatureAccess;

/**
 * « Qui suis-je pour MonCampus ? » - the context a conversation starts from: the teacher's name,
 * the formations they teach, the features lit for them. Nothing about anybody else.
 */
final readonly class WhoamiTool implements McpTool
{
    public function __construct(
        private ProgramRepository $programs,
        private FeatureAccess $featureAccess,
    ) {
    }

    public function name(): string
    {
        return 'whoami';
    }

    public function title(): string
    {
        return 'Mon compte MonCampus';
    }

    public function description(): string
    {
        return 'Renvoie le nom de l\'utilisateur connecté, ses rôles, les formations (classes) où il enseigne et les fonctionnalités de MonCampus qui lui sont ouvertes. À appeler en début de conversation pour savoir dans quel contexte on travaille.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $user = $call->user;
        $programs = [];
        foreach ($this->programs->findAllForTeacher($user) as $program) {
            $programs[] = ['id' => $program->getId(), 'shortName' => $program->getDisplayShortName(), 'name' => $program->getDisplayName()];
        }

        $features = [];
        foreach ($this->featureAccess->all($user) as $feature => $enabled) {
            if ($enabled && Feature::ClaudeConnector->value !== $feature) {
                $features[] = $feature;
            }
        }

        return McpToolResult::data(
            \sprintf('Connecté en tant que %s.', $user->getDisplayName() ?? $user->getUserIdentifier()),
            [
                'name' => $user->getDisplayName() ?? $user->getUserIdentifier(),
                'roles' => array_values(array_filter($user->getRoles(), static fn (string $role): bool => 'ROLE_USER' !== $role)),
                'programsTaught' => $programs,
                'features' => $features,
            ],
        );
    }
}
