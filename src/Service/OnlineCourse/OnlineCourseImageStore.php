<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

use App\Entity\FileLibraryNode;
use App\Entity\OnlineCourse;
use App\Service\FileUploadService;
use App\Service\StagedUpload;
use App\Service\UploadIntake;
use App\Service\UploadPolicy;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The picture of an online course - on its card on the author's page, in « Mes cours », and as the
 * preview a link shows when it is pasted somewhere (`og:image`). The course's card screen and the
 * Claude connector both write it through here.
 *
 * It follows the materials' rules (App\Service\OnlineCourse\OnlineCourseMaterialStore):
 *
 * - it lives under the course's own folder, `online-courses/{course}/{token}/image/`, and is served
 *   by the CDN - the token keeps a draft's picture from being guessed;
 * - **a library file is copied, not referenced**: deleting it from the library must not strip a
 *   public course of its picture;
 * - each picture gets a new random name, so replacing it changes the address and the CDN never
 *   serves the old one from cache; the one replaced goes to the deferred purge.
 *
 * JPEG, PNG or WebP, 5 Mo at most: a picture drawn at card size, not a print.
 */
class OnlineCourseImageStore
{
    public const string MAX_SIZE = '5M';

    public function __construct(
        private readonly UploadIntake $intake,
        private readonly FileUploadService $fileUploads,
    ) {
    }

    public static function policy(): UploadPolicy
    {
        return UploadPolicy::images()->withMaxSize(self::MAX_SIZE);
    }

    /**
     * The field already checked a file sent from the screen; this is for the doors that have none -
     * a library file named by the Claude connector, which may be anything the library holds.
     *
     * @throws OnlineCourseMaterialRefused
     */
    public function assertAccepted(UploadedFile|StagedUpload|FileLibraryNode $file): void
    {
        $policy = self::policy();
        $mimeType = UploadIntake::mimeType($file);

        if (null !== $policy->refusalReason(UploadIntake::originalName($file), '' === $mimeType ? null : $mimeType)) {
            throw new OnlineCourseMaterialRefused('onlineCourseImageWrongTypeMessage', ['%extensions%' => implode(', ', $policy->extensions())]);
        }

        if (UploadIntake::size($file) > $policy->maxSizeInBytes()) {
            throw new OnlineCourseMaterialRefused('onlineCourseImageTooLargeMessage', ['%max%' => $policy->maxSize()]);
        }
    }

    /**
     * Gives the course this picture, in place of the one it had. The course must have been flushed
     * once: its id is part of the folder.
     *
     * @throws OnlineCourseMaterialRefused
     */
    public function set(OnlineCourse $course, UploadedFile|StagedUpload|FileLibraryNode $file): void
    {
        $this->assertAccepted($file);

        $id = $course->getId() ?? throw new \LogicException('A course is given a picture once it has been saved.');
        $prefix = \sprintf('online-courses/%d/%s/image/', $id, $course->getStorageToken());
        $name = bin2hex(random_bytes(8)).'.'.UploadIntake::extension($file);

        if ($file instanceof FileLibraryNode) {
            $source = $file->getStorageKey() ?? throw new OnlineCourseMaterialRefused('onlineCourseMaterialNoFileMessage');
            $key = $prefix.$name;
            $this->fileUploads->copy($source, $key);
        } else {
            $key = $this->intake->store($file, $prefix, $name);
        }

        $previous = $course->getImageKey();
        $course->setImageKey($key);
        $course->touch();

        if (null !== $previous) {
            $this->fileUploads->delete($previous);
        }
    }

    public function remove(OnlineCourse $course): void
    {
        $previous = $course->getImageKey();
        if (null === $previous) {
            return;
        }

        $course->setImageKey(null);
        $course->touch();
        $this->fileUploads->delete($previous);
    }
}
