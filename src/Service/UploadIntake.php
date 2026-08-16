<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The one call a controller makes to take a submitted file into its own prefix, whichever of the
 * two shapes it arrived in (design/validated/file-library.md, "The harmonised upload component").
 *
 * There are two shapes because the fifteen upload fields migrate to App\Form\FilePickerType **one at
 * a time**: a migrated field submits an App\Service\StagedUpload - bytes already in the bucket,
 * scanned, waiting to be claimed - and a field not yet migrated still submits an UploadedFile. This
 * class is what lets a controller stop caring which, so a screen can be migrated by changing its
 * form type and nothing else.
 *
 *     $key = $intake->store($file, 'lesson-logs/', sprintf('%d-%s.pdf', $id, $token));
 *
 * The reading helpers matter as much as store(): a controller that asked an UploadedFile for its
 * name, its size or its extension has to keep asking, and StagedUpload is not a `File`. They are
 * the reason migrating a screen touches its upload line and nothing around it.
 */
class UploadIntake
{
    public function __construct(
        private readonly FileUploadService $fileUploads,
        private readonly StagedUploadStore $stagedUploads,
    ) {
    }

    /**
     * @param non-empty-string $prefix   must end with '/' - the caller's feature namespace
     * @param non-empty-string $filename the caller's own naming scheme
     *
     * @return non-empty-string the full storage key
     */
    public function store(UploadedFile|StagedUpload $file, string $prefix, string $filename): string
    {
        return $file instanceof StagedUpload
            ? $this->stagedUploads->claim($file, $prefix, $filename)
            : $this->fileUploads->upload($prefix, $filename, $file);
    }

    public static function originalName(UploadedFile|StagedUpload $file): string
    {
        return $file instanceof StagedUpload ? $file->originalName : $file->getClientOriginalName();
    }

    public static function size(UploadedFile|StagedUpload $file): int
    {
        return $file instanceof StagedUpload ? $file->size : (int) $file->getSize();
    }

    /**
     * The sniffed type - for a staged upload, the one the server read at staging time and carried in
     * the signed token, never what the browser claimed.
     */
    public static function mimeType(UploadedFile|StagedUpload $file): string
    {
        if ($file instanceof StagedUpload) {
            return $file->mimeType;
        }

        try {
            return $file->getMimeType() ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The extension to build a storage key with, lowercased and taken from the **name**.
     *
     * Not `guessExtension()`, which reads the sniffed type and answers `bin` for the perfectly
     * ordinary files this platform accepts on that basis (a genuine `.csv` sniffs as text/plain, a
     * `.pcap` as application/octet-stream). The name has already been checked against the type by
     * App\Validator\AllowedUpload before anything gets here, so it is the trustworthy half.
     */
    public static function extension(UploadedFile|StagedUpload $file): string
    {
        return mb_strtolower(pathinfo(self::originalName($file), \PATHINFO_EXTENSION));
    }
}
