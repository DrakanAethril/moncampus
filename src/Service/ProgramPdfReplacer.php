<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces one of a Program's optional PDF attachments - the syllabus, the alternance calendar -
 * from an upload that has already been staged.
 *
 * **The order is the point, and it is the same as ProfileController::uploadAvatar():** the new
 * object is stored, its key persisted, and only then is the old one deleted. A failure halfway
 * through leaves a program pointing at a file that exists, never at one that no longer does.
 *
 * Extracted from App\Controller\Settings\ProgramController when the UFA formation sheet gained its
 * own « Documents » tab: two screens now write the same column, from two different menus, and the
 * ordering above is exactly the kind of detail that gets copied wrong the second time.
 */
class ProgramPdfReplacer
{
    /** App\Service\FileUploadService namespace prefixes for Program's two optional PDF uploads. */
    public const string SYLLABUS_PREFIX = 'programs/syllabus/';

    public const string ALTERNANCE_CALENDAR_PREFIX = 'programs/alternance-calendar/';

    public const string TIMETABLE_DOCUMENT_PREFIX = 'programs/timetable-document/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileUploadService $fileUploadService,
        private readonly UploadIntake $uploadIntake,
    ) {
    }

    /**
     * No-op unless a file was actually submitted this time: an edit form is re-submitted without
     * re-choosing an already-uploaded file, and that must not clear anything.
     *
     * @param \Closure(string): mixed $setNewKey
     */
    public function replace(mixed $file, string $prefix, Program $program, ?string $oldKey, \Closure $setNewKey): bool
    {
        if (!$file instanceof StagedUpload) {
            return false;
        }

        $newKey = $this->uploadIntake->store($file, $prefix, sprintf('%d-%d.%s', $program->getId(), time(), UploadIntake::extension($file)));

        $setNewKey($newKey);
        $this->entityManager->flush();

        if (null !== $oldKey) {
            $this->fileUploadService->delete($oldKey);
        }

        return true;
    }
}
