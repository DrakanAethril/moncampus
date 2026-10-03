<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Service\LearningPath\LearningPathRefused;
use App\Service\LearningPath\LearningPathWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Puts a learning path online - for people holding an account, never for a visitor
 * (design/validated/cours-en-ligne.md, §12). Through the same writer as the screen's button.
 */
final readonly class PathPublishTool implements McpTool
{
    public function __construct(
        private McpOnlineCourses $onlineCourses,
        private LearningPathWriter $writer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'path_publish';
    }

    public function title(): string
    {
        return 'Publier un parcours';
    }

    public function description(): string
    {
        return 'Met en ligne un parcours de l\'enseignant : les personnes qui ont un compte peuvent le commencer par son lien (followUrl). Il faut un titre et au moins une étape qui peut être suivie (un cours publié, ou un quiz qui a des questions).';
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
        return false;
    }

    public function features(): array
    {
        return [Feature::OnlineCourses];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $this->onlineCourses->assertAuthor();
        $path = $this->onlineCourses->path($call->requiredId('pathId'));

        try {
            $this->writer->publish($path);
        } catch (LearningPathRefused $refused) {
            throw $this->onlineCourses->pathRefusal($refused);
        }
        $this->entityManager->flush();

        $data = $this->onlineCourses->describePath($path);

        return McpToolResult::data(\sprintf('Le parcours « %s » est en ligne pour les personnes qui ont un compte : %s', $path->getTitle(), $this->onlineCourses->followUrl($path)), $data);
    }
}
