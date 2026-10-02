<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Security\Voter\OnlineCourseVoter;
use App\Service\OnlineCourse\OnlineCourseWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Takes a course offline: it becomes a draft again and nothing is removed - its files, its address
 * and its card stay the course's (design/validated/cours-en-ligne.md, §12).
 */
final readonly class CourseUnpublishTool implements McpTool
{
    public function __construct(
        private McpOnlineCourses $onlineCourses,
        private OnlineCourseWriter $writer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'course_unpublish';
    }

    public function title(): string
    {
        return 'Dépublier un cours en ligne';
    }

    public function description(): string
    {
        return 'Retire un cours de l\'enseignant de sa page publique : il redevient un brouillon. Rien n\'est supprimé ; course_publish le remet en ligne à la même adresse.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'courseId' => ['type' => 'integer', 'description' => 'Identifiant du cours (course_list).'],
            ],
            'required' => ['courseId'],
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
        $course = $this->onlineCourses->course($call->requiredId('courseId'));
        if (!$course->isPublished()) {
            throw new McpToolException(\sprintf('Le cours « %s » n\'est pas en ligne : c\'est déjà un brouillon.', $course->getTitle()));
        }

        $course = $this->onlineCourses->course((int) $course->getId(), OnlineCourseVoter::UNPUBLISH);
        $this->writer->unpublish($course);
        $this->entityManager->flush();

        return McpToolResult::data(\sprintf('Le cours « %s » n\'est plus en ligne ; il est redevenu un brouillon.', $course->getTitle()), $this->onlineCourses->describe($course));
    }
}
