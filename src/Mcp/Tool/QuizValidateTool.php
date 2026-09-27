<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpQuizDocuments;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;

/**
 * A dry run of quiz_create: the document read by the same reader, nothing written.
 */
final readonly class QuizValidateTool implements McpTool
{
    public function __construct(private McpQuizDocuments $documents)
    {
    }

    public function name(): string
    {
        return 'quiz_validate';
    }

    public function title(): string
    {
        return 'Vérifier un quiz';
    }

    public function description(): string
    {
        return 'Vérifie un document « moncampus-quiz/1 » sans rien créer : renvoie le nombre de questions acceptées et la liste des questions refusées, avec la raison. À utiliser avant quiz_create.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document' => ['type' => 'object', 'description' => 'Le document « moncampus-quiz/1 » complet (voir format_guide).'],
            ],
            'required' => ['document'],
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::QuizLibrary];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $payload = $this->documents->read($call->documentJson('document'));

        return McpToolResult::data(
            [] === $payload['errors']
                ? \sprintf('Document valide : %d questions.', \count($payload['questions']))
                : \sprintf('%d questions acceptées, %d problèmes à corriger avant de créer.', \count($payload['questions']), \count($payload['errors'])),
            [
                'valid' => [] === $payload['errors'],
                'name' => $payload['name'],
                'acceptedQuestions' => \count($payload['questions']),
                'errors' => $payload['errors'],
            ],
        );
    }
}
