<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpOnlineCourseFields;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Service\JsonRequestPayload;
use App\Service\OnlineCourse\OnlineCourseWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A new online course, **always a draft** (design/validated/cours-en-ligne.md, §12): putting it
 * online is a second call, course_publish, never an effect of creating it.
 */
final readonly class CourseCreateTool implements McpTool
{
    public function __construct(
        private OnlineCourseWriter $writer,
        private McpOnlineCourseFields $fields,
        private McpOnlineCourses $onlineCourses,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'course_create';
    }

    public function title(): string
    {
        return 'Créer un cours en ligne';
    }

    public function description(): string
    {
        return 'Crée un cours en ligne de l\'enseignant, en brouillon : titre (obligatoire), résumé, description en Markdown, tags, durée estimée. Les supports s\'ajoutent ensuite avec course_material_add ; la mise en ligne se fait avec course_publish. Renvoie le courseId, le lien de la fiche et le lien d\'aperçu.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => McpOnlineCourseFields::schema(),
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
        $course = $this->writer->create($call->user, $title, '' === trim($call->arguments->string('slug')) ? null : $call->arguments->string('slug'));
        $this->fields->apply($course, $this->withoutSlug($call));
        $this->entityManager->flush();

        $data = $this->onlineCourses->describe($course);

        return McpToolResult::data(
            \sprintf('Cours « %s » créé en brouillon : %s', $course->getTitle(), $this->onlineCourses->editUrl($course)),
            $data,
            ['kind' => 'online_course', 'id' => (int) $course->getId()],
        );
    }

    /**
     * The slug was already decided by create(), numbered if the teacher uses it: applying it again
     * as an edit would refuse what create() just made available.
     */
    private function withoutSlug(McpToolCall $call): McpToolCall
    {
        $arguments = $call->arguments->toArray();
        unset($arguments['slug']);

        return new McpToolCall($call->user, $call->grant, JsonRequestPayload::fromArray($arguments));
    }
}
