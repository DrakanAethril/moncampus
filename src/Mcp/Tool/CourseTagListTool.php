<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Repository\OnlineCourseTagRepository;

/**
 * The teacher's own tags, with how many of their courses carry each - so that Claude reuses « SQL »
 * rather than coin « Sql (bases) » next to it (design/validated/cours-en-ligne.md, §9).
 */
final readonly class CourseTagListTool implements McpTool
{
    public function __construct(
        private OnlineCourseTagRepository $tags,
        private McpOnlineCourses $onlineCourses,
    ) {
    }

    public function name(): string
    {
        return 'course_tag_list';
    }

    public function title(): string
    {
        return 'Lister mes tags de cours';
    }

    public function description(): string
    {
        return 'Liste les tags de cours en ligne de l\'enseignant et le nombre de cours qui portent chacun. À consulter avant de taguer un cours : réutilise un tag existant plutôt que d\'en créer un presque identique.';
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
        $tags = $this->tags->searchForOwner($call->user, '', 500);

        return McpToolResult::data(\sprintf('%d tag(s).', \count($tags)), ['tags' => $tags]);
    }
}
