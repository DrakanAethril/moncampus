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

    /**
     * The submitted file as something with a **path on disk**, for the callers that read its
     * contents rather than store it - the two import assistants, which parse a spreadsheet before
     * anything is created.
     *
     * A staged upload has no local path: its bytes are already in the bucket. So this fetches them
     * back into a temp file and wraps it as an UploadedFile, which is what those readers take. The
     * round trip is real and it is the honest price of the field carrying no bytes: an import file
     * is a few dozen kilobytes, and what it buys is that the type and the virus were checked before
     * the teacher ever reached the analysis screen.
     *
     * The temp file is left to the system, exactly as PHP's own upload temp files are.
     */
    public function asLocalFile(UploadedFile|StagedUpload $file): UploadedFile
    {
        if ($file instanceof UploadedFile) {
            return $file;
        }

        $path = tempnam(sys_get_temp_dir(), 'staged-read-');

        if (false === $path || false === file_put_contents($path, $this->fileUploads->read($file->key))) {
            throw new \RuntimeException(\sprintf('Could not fetch the staged upload "%s" back for reading.', $file->key));
        }

        // test: true - the file did not arrive through PHP's upload handling, and refusing it on
        // that ground is exactly the check that does not apply here.
        return new UploadedFile($path, $file->originalName, '' === $file->mimeType ? null : $file->mimeType, null, true);
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
