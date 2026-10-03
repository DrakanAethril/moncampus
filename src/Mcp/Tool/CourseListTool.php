<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCourseTag;
use App\Enum\Feature;
use App\Enum\OnlineCourseStatus;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\OnlineCourseRepository;

/**
 * The teacher's own online courses (design/validated/cours-en-ligne.md, §12), drafts included, with
 * the ids the other course tools take. Only their own: there is no catalogue across teachers, and
 * the connector does not make one.
 */
final readonly class CourseListTool implements McpTool
{
    public function __construct(
        private OnlineCourseRepository $courses,
        private McpOnlineCourses $onlineCourses,
    ) {
    }

    public function name(): string
    {
        return 'course_list';
    }

    public function title(): string
    {
        return 'Lister mes cours en ligne';
    }

    public function description(): string
    {
        return 'Liste les cours en ligne de l\'enseignant (brouillons compris) : titre, état (draft, public, path_only), tags, supports, lien public et lien de la fiche. Filtres facultatifs : `status`, `tag`. Renvoie les identifiants (courseId, materialId) que prennent les autres outils course_*.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['draft', 'public', 'path_only'], 'description' => 'Ne garder que les cours dans cet état.'],
                'tag' => ['type' => 'string', 'description' => 'Ne garder que les cours qui portent ce tag.'],
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
        return [Feature::OnlineCourses];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $this->onlineCourses->assertAuthor();

        $statusArgument = $call->arguments->string('status');
        $status = '' === $statusArgument ? null : (OnlineCourseStatus::tryFrom($statusArgument) ?? throw new McpToolException('L\'argument « status » vaut « draft », « public » ou « path_only ».'));
        $tag = OnlineCourseTag::normalize($call->arguments->string('tag'));

        $courses = array_values(array_filter(
            $this->courses->findForOwner($call->user),
            static fn (OnlineCourse $course): bool => (null === $status || $course->getStatus() === $status) && ('' === $tag || $course->hasTag($tag)),
        ));

        return McpToolResult::data(
            \sprintf('%d cours en ligne. Page publique de l\'enseignant : %s', \count($courses), $this->onlineCourses->pageUrl($call->user) ?? 'pas encore d\'adresse (à choisir à l\'écran, Ma page)'),
            [
                'pageUrl' => $this->onlineCourses->pageUrl($call->user),
                'courses' => array_map(fn (OnlineCourse $course): array => $this->onlineCourses->describe($course), $courses),
            ],
        );
    }
}
