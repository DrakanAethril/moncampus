<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Service\OnlineCourse\OnlineCourseMaterialRefused;
use App\Service\OnlineCourse\OnlineCourseMaterialStore;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A new revision of an existing material - same tab, same public address
 * (design/validated/cours-en-ligne.md, §6). The revision being replaced is kept: the teacher goes
 * back to it with one button on the course's card, which is why a replacement on a published
 * course needs no ceremony of its own.
 */
final readonly class CourseMaterialReplaceTool implements McpTool
{
    public function __construct(
        private McpOnlineCourses $onlineCourses,
        private OnlineCourseMaterialStore $store,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'course_material_replace';
    }

    public function title(): string
    {
        return 'Remplacer un support de cours';
    }

    public function description(): string
    {
        return 'Remplace le contenu d\'un support d\'un cours en ligne, par les mêmes sources que course_material_add (`fileId`, `html` pour un cours interactif, `markdown` pour un PDF ou une fiche). L\'onglet et son adresse publique ne changent pas ; la révision remplacée reste disponible sur la fiche du cours pour y revenir. Sur un cours publié, la nouvelle version est en ligne aussitôt.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'materialId' => ['type' => 'integer', 'description' => 'Identifiant du support (course_get).'],
                'fileId' => ['type' => 'integer', 'description' => 'Un fichier de la bibliothèque de fichiers.'],
                'html' => ['type' => 'string', 'description' => 'Une page HTML entière (cours interactif seulement).'],
                'markdown' => ['type' => 'string', 'description' => 'Le contenu en Markdown, rendu en PDF.'],
            ],
            'required' => ['materialId'],
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
        $material = $this->onlineCourses->material($call->requiredId('materialId'));
        $course = $material->getCourse();
        $source = $this->onlineCourses->source($call, $material->getKind(), $material->getLabel() ?? $course->getTitle());

        try {
            if (null !== $source['html']) {
                $this->store->replaceInteractivePage($material, $source['html']);
            } else {
                $this->store->replace($material, $source['file'] ?? throw new \LogicException('A source is a file or a page.'));
            }
        } catch (OnlineCourseMaterialRefused $refused) {
            throw $this->onlineCourses->refusal($refused);
        }
        $this->entityManager->flush();

        return McpToolResult::data(
            \sprintf('Support remplacé (révision %d) dans le cours « %s »%s.', $material->getLiveRevisionNumber(), $course->getTitle(), $course->isPublished() ? ', en ligne aussitôt' : ''),
            ['material' => $this->onlineCourses->describeMaterial($material), 'course' => $this->onlineCourses->describe($course)],
        );
    }
}
