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
 * A new material on a course (design/validated/cours-en-ligne.md, §12), from one of three sources:
 * a file of the bibliothèque, a whole HTML page for an interactive course, or Markdown rendered as a
 * PDF. Whatever the source, it goes through App\Service\OnlineCourse\OnlineCourseMaterialStore -
 * the type gate, the archive reader, the revision folders - exactly as a file sent from the screen.
 */
final readonly class CourseMaterialAddTool implements McpTool
{
    public function __construct(
        private McpOnlineCourses $onlineCourses,
        private OnlineCourseMaterialStore $store,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'course_material_add';
    }

    public function title(): string
    {
        return 'Ajouter un support à un cours';
    }

    public function description(): string
    {
        return 'Ajoute un support à un cours en ligne de l\'enseignant. `kind` : « interactive » (cours interactif), « pdf » (version PDF), « summary » (fiche de synthèse) ou « video ». Une seule source : `fileId` (un fichier de la bibliothèque : PDF, vidéo, ou archive .zip contenant un index.html pour un cours interactif ; il est copié dans le cours), `html` (une page HTML entière et autonome, pour un cours interactif — appelle d\'abord format_guide avec « cours_interactif ») ou `markdown` (rendu en PDF, pour une version PDF ou une fiche de synthèse). `label` nomme l\'onglet s\'il faut distinguer deux supports de même nature. Sur un cours publié, le support est en ligne aussitôt.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'courseId' => ['type' => 'integer', 'description' => 'Identifiant du cours (course_list).'],
                'kind' => ['type' => 'string', 'enum' => McpOnlineCourses::KINDS],
                'label' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Nom de l\'onglet, facultatif (« Partie 2 »).'],
                'fileId' => ['type' => 'integer', 'description' => 'Un fichier de la bibliothèque de fichiers (file_list).'],
                'html' => ['type' => 'string', 'description' => 'Une page HTML entière (cours interactif seulement).'],
                'markdown' => ['type' => 'string', 'description' => 'Le contenu en Markdown, rendu en PDF (version PDF ou fiche de synthèse).'],
            ],
            'required' => ['courseId', 'kind'],
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
        $kind = $this->onlineCourses->kind($call->arguments->string('kind'));
        $label = trim($call->arguments->string('label'));
        $source = $this->onlineCourses->source($call, $kind, '' !== $label ? $label : $course->getTitle());

        try {
            $material = null !== $source['html']
                ? $this->store->addInteractivePage($course, $source['html'], $label)
                : $this->store->add($course, $kind, $source['file'] ?? throw new \LogicException('A source is a file or a page.'), $label);
        } catch (OnlineCourseMaterialRefused $refused) {
            throw $this->onlineCourses->refusal($refused);
        }
        $this->entityManager->flush();

        $data = $this->onlineCourses->describe($course);

        return McpToolResult::data(
            \sprintf('Support ajouté au cours « %s »%s : %s', $course->getTitle(), $course->isPublished() ? ' (en ligne aussitôt)' : '', $this->onlineCourses->editUrl($course)),
            ['material' => $this->onlineCourses->describeMaterial($material), 'course' => $data],
            ['kind' => 'online_course_material', 'id' => (int) $material->getId()],
        );
    }
}
