<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;

/**
 * One learning path of the teacher's, with its steps in order (design/validated/cours-en-ligne.md,
 * §12) - what path_set_steps is written from.
 */
final readonly class PathGetTool implements McpTool
{
    public function __construct(
        private McpOnlineCourses $onlineCourses,
    ) {
    }

    public function name(): string
    {
        return 'path_get';
    }

    public function title(): string
    {
        return 'Lire un parcours';
    }

    public function description(): string
    {
        return 'Renvoie un parcours de l\'enseignant : titre, état, étapes dans l\'ordre (cours avec leur état de publication, quiz de validation avec leur seuil et leur nombre de questions tirées), étapes indisponibles, ce qui manque pour le publier, nombre de personnes qui le suivent.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['pathId' => ['type' => 'integer', 'description' => 'Identifiant du parcours (path_list).']],
            'required' => ['pathId'],
            'additionalProperties' => false,
        ];
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
        $path = $this->onlineCourses->path($call->requiredId('pathId'));

        return McpToolResult::data(\sprintf('Parcours « %s » (%s).', $path->getTitle(), $path->getStatus()->value), $this->onlineCourses->describePath($path));
    }
}
