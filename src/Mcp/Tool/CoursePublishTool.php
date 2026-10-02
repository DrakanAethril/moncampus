<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Enum\OnlineCourseStatus;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Security\Voter\OnlineCourseVoter;
use App\Service\OnlineCourse\OnlineCoursePublicationRefused;
use App\Service\OnlineCourse\OnlineCourseWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Puts a course online (design/validated/cours-en-ligne.md, §12 - « Claude peut publier », the
 * user's decision). Through the same Voter attribute and the same writer as the screen's button,
 * so with the same refusals: a title, a summary, a material, and a page whose address is chosen.
 * It is a tool that writes: claude.ai asks the teacher before calling it.
 */
final readonly class CoursePublishTool implements McpTool
{
    public function __construct(
        private McpOnlineCourses $onlineCourses,
        private OnlineCourseWriter $writer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'course_publish';
    }

    public function title(): string
    {
        return 'Publier un cours en ligne';
    }

    public function description(): string
    {
        return 'Met en ligne un cours de l\'enseignant, sur sa page publique (lisible sans compte). Il faut un titre, un résumé, au moins un support, et que l\'enseignant ait choisi l\'adresse de sa page. Renvoie le lien public. Après la première publication, l\'adresse du cours ne change plus.';
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
        $course = $this->onlineCourses->course($call->requiredId('courseId'), OnlineCourseVoter::PUBLISH);

        try {
            $this->writer->publish($course, OnlineCourseStatus::PublicCourse);
        } catch (OnlineCoursePublicationRefused) {
            throw new McpToolException('Ce cours ne peut pas encore être publié. Il manque :', $this->onlineCourses->missing($course));
        }
        $this->entityManager->flush();

        return McpToolResult::data(
            \sprintf('Le cours « %s » est en ligne : %s', $course->getTitle(), (string) $this->onlineCourses->publicUrl($course)),
            $this->onlineCourses->describe($course),
        );
    }
}
