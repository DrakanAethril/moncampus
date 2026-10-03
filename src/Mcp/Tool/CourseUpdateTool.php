<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpOnlineCourseFields;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\OnlineCourseTagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Changes the card of a course - **only the fields named** (design/validated/cours-en-ligne.md,
 * §12). A published course changes online at once: claude.ai asks the teacher before the call.
 */
final readonly class CourseUpdateTool implements McpTool
{
    public function __construct(
        private McpOnlineCourseFields $fields,
        private McpOnlineCourses $onlineCourses,
        private OnlineCourseTagRepository $tags,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'course_update';
    }

    public function title(): string
    {
        return 'Modifier un cours en ligne';
    }

    public function description(): string
    {
        return 'Modifie la fiche d\'un cours en ligne de l\'enseignant : seuls les champs fournis sont écrits, les autres restent tels quels. « tags » remplace la liste entière ; « imageFileId » (une image de la bibliothèque) remplace la vignette ; « quizId » lie le quiz de test (lien « Test » de la carte du cours), null le retire ; « testPassPercent » fixe son seuil de réussite (80 % par défaut). L\'adresse (« slug ») ne change plus après la première publication. Un cours publié est modifié en ligne immédiatement.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['courseId' => ['type' => 'integer', 'description' => 'Identifiant du cours (course_list).'], ...McpOnlineCourseFields::schema()],
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

        $image = $this->fields->image($call);
        $written = $this->fields->apply($course, $call);
        if (null !== $image) {
            $this->fields->applyImage($course, $image);
            $written[] = 'imageFileId';
        }
        if ([] === $written) {
            throw new McpToolException('Aucun champ à modifier : nomme au moins un de « title », « slug », « summary », « description », « estimatedMinutes », « tags », « quizId », « testPassPercent », « imageFileId ».');
        }

        $this->entityManager->flush();
        $this->tags->deleteUnusedForOwner($course->getOwner());

        return McpToolResult::data(
            \sprintf('Cours « %s » modifié (%s).%s', $course->getTitle(), implode(', ', $written), $course->isPublished() ? ' Il est en ligne : la modification est visible.' : ''),
            $this->onlineCourses->describe($course),
        );
    }
}
