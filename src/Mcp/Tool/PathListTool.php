<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\LearningPath;
use App\Enum\Feature;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Repository\LearningPathRepository;

/**
 * The teacher's own learning paths (design/validated/cours-en-ligne.md, §12), drafts included. How
 * many people follow each is a count; who they are is never read by the connector.
 */
final readonly class PathListTool implements McpTool
{
    public function __construct(
        private LearningPathRepository $paths,
        private McpOnlineCourses $onlineCourses,
    ) {
    }

    public function name(): string
    {
        return 'path_list';
    }

    public function title(): string
    {
        return 'Lister mes parcours';
    }

    public function description(): string
    {
        return 'Liste les parcours de l\'enseignant (brouillons compris) : titre, état, étapes (cours et quiz de validation, avec leurs identifiants), nombre de personnes qui les suivent, lien de l\'éditeur et lien du parcours. Un parcours ne se suit qu\'avec un compte.';
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
        return [Feature::OnlineCourses];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $this->onlineCourses->assertAuthor();
        $paths = $this->paths->findForOwner($call->user);

        return McpToolResult::data(
            \sprintf('%d parcours.', \count($paths)),
            ['paths' => array_map(fn (LearningPath $path): array => $this->onlineCourses->describePath($path), $paths)],
        );
    }
}
