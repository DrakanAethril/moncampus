<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\AssignmentAttachment;
use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Entity\VideoResource;
use App\Entity\VideoResourceFile;
use App\Enum\AssignmentAttachmentSourceType;
use App\Enum\AssignmentNature;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Créer un travail » from a file of the library (design/validated/file-library.md).
 *
 * It exists because of what the Vidéos tool lost: its **front door**. `VideoResource` still carries
 * the cue points, the watch tracking, the statistics screen and the mobile API - none of which the
 * library replaces, and none of which was asked to disappear. What the library replaces is the
 * *hosting*, uploading a video being the only reason to open that tool first. So the tool keeps
 * everything and gains a back door: a video in the library carries « Créer un travail », which
 * creates the resource and its file **referencing the same object**, and opens the wizard.
 *
 * The nature follows the file rather than being asked, which is how the two media natures keep the
 * only entry points they have ever had:
 *
 * | The file is | The work is |
 * |---|---|
 * | mp4 / webm / mov | à visionner, through a VideoResource created here |
 * | an audio file | à écouter, the file attached as its support |
 * | anything else | à rendre |
 */
class FileLibraryWorkFactory
{
    /** Same three extensions the per-file ceiling is picked on - one list, one meaning. */
    private const array VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov'];
    private const array AUDIO_EXTENSIONS = ['mp3', 'm4a', 'wav', 'ogg', 'opus', 'flac'];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function natureFor(FileLibraryNode $node): AssignmentNature
    {
        return match (true) {
            $this->isVideo($node) => AssignmentNature::Watching,
            $this->isAudio($node) => AssignmentNature::Listening,
            default => AssignmentNature::ToSubmit,
        };
    }

    /**
     * The title the wizard opens on: the file's name **without its extension**, which is what a
     * teacher would have typed anyway.
     */
    public function titleFor(FileLibraryNode $node): string
    {
        $name = $node->getName();
        $extension = $node->getExtension();

        return '' === $extension ? $name : mb_substr($name, 0, -(mb_strlen($extension) + 1));
    }

    public function isVideo(FileLibraryNode $node): bool
    {
        return \in_array($node->getExtension(), self::VIDEO_EXTENSIONS, true);
    }

    public function isAudio(FileLibraryNode $node): bool
    {
        return \in_array($node->getExtension(), self::AUDIO_EXTENSIONS, true);
    }

    /**
     * Attaches the library file to a work being created, as a **link**: the row carries the node's
     * own storage key plus a foreign key back to it, so the file weighs once and deleting it from the
     * library removes it from here.
     */
    public function attach(Assignment $assignment, FileLibraryNode $node): AssignmentAttachment
    {
        $attachment = new AssignmentAttachment($assignment, $node->getName(), AssignmentAttachmentSourceType::Library);
        $attachment->setStorageKey($node->getStorageKey());
        $attachment->setLibraryNode($node);

        $this->entityManager->persist($attachment);

        return $attachment;
    }

    /**
     * The Vidéos tool's back door: a VideoResource on the work's class, holding one file that
     * **references the library object** rather than copying it.
     *
     * No copy here, deliberately - see design/validated/file-library.md's "The one exception: vidéo
     * suivie, and it is deferred". The copy is forked only when the reference is about to become
     * false, which is *replace* or *delete*, and App\Service\FileLibraryVideoFork does it. In the
     * ordinary case there is one object, shared, charged once to the quota - and « Remplacer »
     * corrects the video everywhere too, which an eager copy silently exempted it from.
     */
    public function createVideoResource(Assignment $assignment, FileLibraryNode $node, User $author): ?VideoResource
    {
        $program = $assignment->getProgram();

        if (null === $program || null === $node->getStorageKey()) {
            return null;
        }

        $resource = new VideoResource($program, $author);
        $resource->setName($this->titleFor($node));

        $file = new VideoResourceFile($node->getStorageKey(), 1);
        $file
            ->setOriginalName($node->getName())
            ->setFileSize($node->getSizeBytes() ?? 0)
            ->setDurationSeconds($node->getDurationSeconds() ?? 0)
            ->setUploadedBy($author)
            ->setLibraryNode($node);

        $resource->addFile($file);

        $this->entityManager->persist($resource);
        $this->entityManager->persist($file);

        return $resource;
    }
}
