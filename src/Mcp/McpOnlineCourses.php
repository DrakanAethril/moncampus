<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\FileLibraryNode;
use App\Entity\OnlineCourse;
use App\Entity\OnlineCourseMaterial;
use App\Entity\User;
use App\Enum\OnlineCourseMaterialKind;
use App\Repository\OnlineCourseMaterialRepository;
use App\Repository\OnlineCoursePageRepository;
use App\Repository\OnlineCourseRepository;
use App\Security\Voter\OnlineCourseVoter;
use App\Service\CourseMaterialRenderer;
use App\Service\GotenbergUnavailableException;
use App\Service\OnlineCourse\OnlineCourseContentOrigin;
use App\Service\OnlineCourse\OnlineCourseMaterialRefused;
use App\Service\OnlineCourse\OnlineCourseWriter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the online-course tools of the connector share (design/validated/cours-en-ligne.md, §12):
 * finding a course or a material by id through the same Voter as the screens, saying who may write
 * at all, reading the source of a material from a tool's arguments, and describing a course the
 * same way in every answer.
 *
 * « Introuvable » is the one answer for an id that does not exist and one the teacher may not
 * touch - the 404-not-403 rule of the screens.
 *
 * @phpstan-type MaterialSource array{file: UploadedFile|FileLibraryNode|null, html: ?string}
 */
final readonly class McpOnlineCourses
{
    public const array KINDS = ['interactive', 'pdf', 'summary', 'video'];

    public function __construct(
        private AuthorizationCheckerInterface $authorization,
        private OnlineCourseRepository $courses,
        private OnlineCourseMaterialRepository $materials,
        private OnlineCoursePageRepository $pages,
        private OnlineCourseContentOrigin $origin,
        private OnlineCourseWriter $writer,
        private McpLibraryAccess $library,
        private CourseMaterialRenderer $renderer,
        private McpLinks $links,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The screens of « Cours en ligne » are a teacher's tool - a student or a tutor who was lit the
     * feature by mistake still has no courses to write. The tools hold the same rule.
     *
     * @throws McpToolException
     */
    public function assertAuthor(): void
    {
        foreach (['ROLE_TEACHER', 'ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'] as $role) {
            if ($this->authorization->isGranted($role)) {
                return;
            }
        }

        throw new McpToolException('Les cours en ligne sont un outil des enseignants : ce compte n\'en a pas.');
    }

    public function course(int $id, string $attribute = OnlineCourseVoter::EDIT): OnlineCourse
    {
        $course = $this->courses->find($id);

        return $course instanceof OnlineCourse && $this->authorization->isGranted($attribute, $course)
            ? $course
            : throw new McpToolException(\sprintf('Cours %d introuvable parmi vos cours en ligne.', $id));
    }

    public function material(int $id): OnlineCourseMaterial
    {
        $material = $this->materials->find($id);

        return $material instanceof OnlineCourseMaterial && $this->authorization->isGranted(OnlineCourseVoter::EDIT, $material->getCourse())
            ? $material
            : throw new McpToolException(\sprintf('Support %d introuvable parmi vos cours en ligne.', $id));
    }

    /**
     * @throws McpToolException
     */
    public function kind(string $value): OnlineCourseMaterialKind
    {
        return OnlineCourseMaterialKind::tryFrom($value)
            ?? throw new McpToolException('L\'argument « kind » vaut « interactive », « pdf », « summary » (fiche de synthèse) ou « video ».');
    }

    /**
     * The bytes a material is made from, read from exactly one of three arguments:
     *
     * - `fileId`, a file of the teacher's bibliothèque (a PDF, a video, a .zip) - copied into the
     *   course, never referenced;
     * - `html`, a whole page written by Claude - an interactive course only;
     * - `markdown`, rendered as a PDF the way `file_create` renders a handout - a PDF or a summary
     *   sheet only.
     *
     * @return MaterialSource
     *
     * @throws McpToolException
     */
    public function source(McpToolCall $call, OnlineCourseMaterialKind $kind, string $title): array
    {
        $fileId = $call->optionalId('fileId');
        $html = $call->arguments->string('html');
        $markdown = $call->arguments->string('markdown');

        $given = array_filter([null !== $fileId, '' !== trim($html), '' !== trim($markdown)]);
        if (1 !== \count($given)) {
            throw new McpToolException('Donne exactement une source : « fileId » (un fichier de la bibliothèque), « html » (une page entière, pour un cours interactif) ou « markdown » (rendu en PDF, pour une version PDF ou une fiche de synthèse).');
        }

        if (null !== $fileId) {
            return ['file' => $this->library->file($fileId), 'html' => null];
        }

        if ('' !== trim($html)) {
            if (!$kind->isBundle()) {
                throw new McpToolException('« html » ne sert qu\'au cours interactif (kind: "interactive"). Pour un PDF ou une fiche, écris du « markdown ».');
            }

            return ['file' => null, 'html' => $html];
        }

        if (!$kind->isDocument()) {
            throw new McpToolException('« markdown » ne sert qu\'à une version PDF ou à une fiche de synthèse (kind: "pdf" ou "summary").');
        }

        try {
            $bytes = $this->renderer->pdf($title, $markdown);
        } catch (GotenbergUnavailableException) {
            throw new McpToolException('Le service de mise en page PDF ne répond pas. Réessayez dans un instant.');
        }

        $path = tempnam(sys_get_temp_dir(), 'online-course-pdf-');
        if (false === $path || false === file_put_contents($path, $bytes)) {
            throw new McpToolException('Le PDF n\'a pas pu être préparé. Réessayez.');
        }

        $name = trim((string) preg_replace('/[\/\\\\:|*?"<>\x00-\x1F]+/u', ' ', $title));

        // test: true - the file was written here, it never came through PHP's upload handling.
        return ['file' => new UploadedFile($path, ('' === $name ? 'support' : $name).'.pdf', 'application/pdf', null, true), 'html' => null];
    }

    /**
     * A refusal of the store, in the words the screen shows - French, since claude.ai often shows
     * a tool's error as it is.
     */
    public function refusal(OnlineCourseMaterialRefused $refused): McpToolException
    {
        return new McpToolException($this->translator->trans($refused->getMessage(), $refused->parameters, 'messages', 'fr'));
    }

    /**
     * A course as every tool answers it: what it is, where it stands, what it carries, where it can
     * be checked.
     *
     * @return array<string, mixed>
     */
    public function describe(OnlineCourse $course, bool $withDescription = false): array
    {
        $data = [
            'courseId' => $course->getId(),
            'title' => $course->getTitle(),
            'slug' => $course->getSlug(),
            'slugFrozen' => $course->isSlugFrozen(),
            'status' => $course->getStatus()->value,
            'summary' => $course->getSummary(),
            'estimatedMinutes' => $course->getEstimatedMinutes(),
            'tags' => $this->tagLabels($course),
            'materials' => array_map(fn (OnlineCourseMaterial $material): array => $this->describeMaterial($material), array_values($course->getMaterials()->toArray())),
            'editUrl' => $this->editUrl($course),
            'publicUrl' => $this->publicUrl($course),
            'missingToPublish' => $course->isPublished() ? [] : $this->missing($course),
        ];

        if ($withDescription) {
            $data['descriptionHtml'] = $course->getDescription();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function describeMaterial(OnlineCourseMaterial $material): array
    {
        $live = $material->getLive();

        return [
            'materialId' => $material->getId(),
            'kind' => $material->getKind()->value,
            'label' => $material->getLabel(),
            'revision' => $live?->getNumber(),
            'revisedAt' => $live?->getCreatedAt()->format('Y-m-d H:i'),
            'fileName' => $live?->getOriginalName(),
            'fileCount' => $live?->getFileCount(),
            'previousRevisionKept' => null !== $material->getOther(),
            'contentUrl' => null === $live ? null : $this->origin->url($live),
        ];
    }

    /**
     * In the order the screens list them - the database's, not the order they were typed in.
     *
     * @return list<string>
     */
    private function tagLabels(OnlineCourse $course): array
    {
        $labels = array_values(array_map(static fn ($tag): string => $tag->getLabel(), $course->getTags()->toArray()));
        sort($labels, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $labels;
    }

    public function editUrl(OnlineCourse $course): string
    {
        return $this->links->url('app_online_courses_edit', ['id' => $course->getId()]);
    }

    /**
     * The address of the course on its author's page - where a draft is previewed by its author,
     * and where a published course is read by anybody. Null while the author has no page yet.
     */
    public function publicUrl(OnlineCourse $course): ?string
    {
        $page = $this->pages->findOneByOwner($course->getOwner());

        return null === $page ? null : $this->links->url('app_public_courses_course', ['handle' => $page->getHandle(), 'slug' => $course->getSlug()]);
    }

    public function pageUrl(User $owner): ?string
    {
        $page = $this->pages->findOneByOwner($owner);

        return null === $page ? null : $this->links->url('app_public_courses_page', ['handle' => $page->getHandle()]);
    }

    /**
     * What stands between a course and going online, in words the model can repeat.
     *
     * @return list<string>
     */
    public function missing(OnlineCourse $course): array
    {
        return array_map(static fn (string $reason): string => match ($reason) {
            'onlineCoursePublishNeedsTitleMessage' => 'un titre',
            'onlineCoursePublishNeedsSummaryMessage' => 'un résumé (summary)',
            'onlineCoursePublishNeedsMaterialMessage' => 'au moins un support',
            'onlineCoursePublishNeedsPageMessage' => 'l\'adresse de la page publique de l\'enseignant, à choisir à l\'écran (Outils › Cours en ligne › Ma page)',
            default => $reason,
        }, $this->writer->publishRefusals($course));
    }
}
