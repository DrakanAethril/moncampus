<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Service\LearningPath\LearningPathWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A new learning path, **always a draft** (design/validated/cours-en-ligne.md, §12): its steps are
 * set with path_set_steps, and it goes online with path_publish.
 */
final readonly class PathCreateTool implements McpTool
{
    public function __construct(
        private LearningPathWriter $writer,
        private McpOnlineCourses $onlineCourses,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'path_create';
    }

    public function title(): string
    {
        return 'Créer un parcours';
    }

    public function description(): string
    {
        return 'Crée un parcours de l\'enseignant, en brouillon : une suite de ses cours en ligne, avec des quiz de validation là où il en veut (facultatifs). Titre obligatoire, résumé, description en Markdown. Les étapes se posent ensuite avec path_set_steps ; la mise en ligne avec path_publish. Un parcours ne se suit qu\'avec un compte.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 200],
                'summary' => ['type' => 'string', 'maxLength' => 300, 'description' => 'Une ou deux phrases : ce qu\'on lit avant de commencer.'],
                'description' => ['type' => 'string', 'description' => 'Présentation du parcours, en Markdown.'],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::OnlineCourses];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $this->onlineCourses->assertAuthor();

        $title = $call->requiredString('title');
        $summary = trim($call->arguments->string('summary'));
        if (mb_strlen($title) > 200 || mb_strlen($summary) > 300) {
            throw new McpToolException('Le titre compte 200 caractères au plus, le résumé 300.');
        }

        $path = $this->writer->create($call->user, $title);
        $path->setSummary($summary);
        $this->writer->describeMarkdown($path, $call->arguments->string('description'));
        $this->entityManager->flush();

        $data = $this->onlineCourses->describePath($path);

        return McpToolResult::data(
            \sprintf('Parcours « %s » créé en brouillon. Pose ses étapes avec path_set_steps.', $path->getTitle()),
            $data,
            ['kind' => 'learning_path', 'id' => (int) $path->getId()],
        );
    }
}
