<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssignmentAttachment;
use App\Entity\DocumentationArticleAttachment;
use App\Entity\FileLibraryNode;
use App\Entity\LessonLogAttachment;
use App\Entity\LibraryResource;
use App\Entity\MessageAttachment;
use App\Entity\QuizQuestion;
use App\Entity\SignupListAttachment;
use App\Entity\VideoResourceFile;
use App\Entity\WikiAttachment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where a library file is used, and what happens to those uses when it is deleted
 * (design/validated/file-library.md, "The link model").
 *
 * > **Un lien est une référence, pas une copie. Le fichier existe une fois, il pèse une fois, et le
 * > supprimer de la bibliothèque le retire partout où il était lié.**
 *
 * That rule is the whole feature, and it has one danger: deleting a file breaks eleven assignments.
 * The screen is what handles it - deletion goes through a modal that lists every usage by name, with
 * a link to each - which is why this class exists at all. Nothing is inferred and nothing is silent.
 *
 * ## Nine queries rather than one
 *
 * There is no `file_library_link` table, deliberately: a polymorphic `(target_type, target_id)` row
 * reads well and lies, because nothing deletes it when its host is deleted, so the usage list slowly
 * fills with usages that no longer exist. Nine real foreign keys cannot drift - and nine indexed
 * lookups on a single id are cheaper than the join a polymorphic table would need anyway.
 *
 * ## What "removing the links" means
 *
 * For eight of the nine, it is deleting the attachment row: the file is gone, and an attachment that
 * points at nothing is worse than an absent one. Two exceptions:
 *
 * - **`quiz_question`** keeps its row and loses its image - the question is the teacher's work, and
 *   deleting it because a picture went would be destroying the wrong thing;
 * - **`video_resource_file`** is not touched here at all. It is the one exception of the whole
 *   design: the work keeps its own copy of the video, forked at the moment the reference is about to
 *   become false (lot 5), because a « à visionner » measures the file from the inside - 62 % of
 *   another cut is not 62 %.
 */
class FileLibraryLinks
{
    /**
     * The nine tables, each with what a usage line has to say: which feature it belongs to, and what
     * to call the thing it is attached to.
     *
     * Ordered as the deletion modal lists them - the teacher's own work first, the places it was
     * published second.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Every use of this file, for the usage panel and for the deletion modal.
     *
     * @return list<array{where: string, what: string, url: ?string}> `where` is a translation key
     */
    public function usagesOf(FileLibraryNode $node): array
    {
        $usages = [];

        foreach ($this->attachmentsOf($node, AssignmentAttachment::class) as $row) {
            $assignment = $row->getAssignment();
            $usages[] = [
                'where' => 'fileLibraryUsageAssignmentLabel',
                'what' => $assignment?->getTitle() ?? $row->getLabel(),
                'url' => null === $assignment?->getId() ? null : $this->urls->generate('app_assignment_edit', ['id' => $assignment->getId()]),
            ];
        }

        foreach ($this->attachmentsOf($node, LessonLogAttachment::class) as $row) {
            $usages[] = [
                'where' => 'fileLibraryUsageLessonLogLabel',
                'what' => $row->getLabel() ?? '',
                'url' => null,
            ];
        }

        foreach ($this->attachmentsOf($node, MessageAttachment::class) as $row) {
            $message = $row->getMessage();
            $thread = $message?->getThread();
            $usages[] = [
                'where' => 'fileLibraryUsageMessageLabel',
                'what' => $thread?->getSubject() ?? $row->getOriginalFilename() ?? '',
                'url' => null === $thread?->getId() ? null : $this->urls->generate('app_messages_show', ['id' => $thread->getId()]),
            ];
        }

        foreach ($this->attachmentsOf($node, DocumentationArticleAttachment::class) as $row) {
            $usages[] = [
                'where' => 'fileLibraryUsageDocumentationLabel',
                'what' => $row->getLabel(),
                'url' => null,
            ];
        }

        foreach ($this->attachmentsOf($node, WikiAttachment::class) as $row) {
            $usages[] = [
                'where' => 'fileLibraryUsageWikiLabel',
                'what' => $row->getLabel(),
                'url' => null,
            ];
        }

        foreach ($this->attachmentsOf($node, SignupListAttachment::class) as $row) {
            $usages[] = [
                'where' => 'fileLibraryUsageSignupListLabel',
                'what' => $row->getOriginalFilename() ?? '',
                'url' => null,
            ];
        }

        foreach ($this->attachmentsOf($node, LibraryResource::class) as $row) {
            $usages[] = [
                'where' => 'fileLibraryUsageSequenceResourceLabel',
                'what' => $row->getLabel(),
                'url' => null,
            ];
        }

        foreach ($this->attachmentsOf($node, QuizQuestion::class) as $row) {
            $usages[] = [
                'where' => 'fileLibraryUsageQuizQuestionLabel',
                'what' => $row->getLabel(),
                'url' => null,
            ];
        }

        foreach ($this->attachmentsOf($node, VideoResourceFile::class) as $row) {
            $resource = $row->getResource();
            $usages[] = [
                'where' => 'fileLibraryUsageVideoLabel',
                'what' => $resource?->getName() ?? $row->getOriginalName(),
                'url' => null === $resource?->getId() ? null : $this->urls->generate('app_video_resource_files', ['resourceId' => $resource->getId()]),
            ];
        }

        return $usages;
    }

    public function countUsagesOf(FileLibraryNode $node): int
    {
        return \count($this->usagesOf($node));
    }

    /**
     * « Supprimer partout »: the links go now.
     *
     * Nothing is flushed here - the caller owns its unit of work, as everywhere else. And nothing
     * touches `video_resource_file`: see the class docblock, that one keeps its own copy.
     */
    public function removeLinksTo(FileLibraryNode $node): void
    {
        foreach ([
            AssignmentAttachment::class,
            LessonLogAttachment::class,
            MessageAttachment::class,
            DocumentationArticleAttachment::class,
            WikiAttachment::class,
            SignupListAttachment::class,
            LibraryResource::class,
        ] as $class) {
            foreach ($this->attachmentsOf($node, $class) as $row) {
                $this->entityManager->remove($row);
            }
        }

        // The question survives its picture: it is the teacher's work, and deleting it because an
        // image went would destroy the wrong thing.
        foreach ($this->attachmentsOf($node, QuizQuestion::class) as $question) {
            $question->setImageStorageKey(null);
            $question->setLibraryNode(null);
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function attachmentsOf(FileLibraryNode $node, string $class): array
    {
        /** @var list<T> $rows */
        $rows = $this->entityManager->getRepository($class)->findBy(['libraryNode' => $node]);

        return $rows;
    }
}
