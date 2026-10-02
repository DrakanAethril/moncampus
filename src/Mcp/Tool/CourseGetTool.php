<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;

/**
 * One online course of the teacher's, whole: its card, its description, each material with its
 * revision and the address it is served from (design/validated/cours-en-ligne.md, §12).
 */
final readonly class CourseGetTool implements McpTool
{
    public function __construct(
        private McpOnlineCourses $onlineCourses,
    ) {
    }

    public function name(): string
    {
        return 'course_get';
    }

    public function title(): string
    {
        return 'Lire un cours en ligne';
    }

    public function description(): string
    {
        return 'Renvoie un cours en ligne de l\'enseignant : fiche (titre, adresse, résumé, description en HTML, tags, durée), état, supports (nature, révision en ligne, nom du fichier, adresse de lecture), ce qui manque pour le publier, lien public et lien de la fiche.';
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
        return true;
    }

    public function features(): array
    {
        return [Feature::OnlineCourses];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $this->onlineCourses->assertAuthor();
        $course = $this->onlineCourses->course($call->requiredId('courseId'));

        return McpToolResult::data(
            \sprintf('Cours « %s » (%s) : %s', $course->getTitle(), $course->getStatus()->value, $this->onlineCourses->editUrl($course)),
            $this->onlineCourses->describe($course, withDescription: true),
        );
    }
}
