<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FileLibraryNode;
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
    public function store(UploadedFile|StagedUpload|FileLibraryNode $file, string $prefix, string $filename): string
    {
        // **A link is a reference, not a copy** (design/validated/file-library.md): the row takes the
        // library node's own storage key, so the file exists once, weighs once, and correcting it in
        // the library corrects it everywhere. Nothing is written to the bucket here at all - which is
        // also why linking a 180 Mo video is instant.
        if ($file instanceof FileLibraryNode) {
            return $file->getStorageKey() ?? throw new \InvalidArgumentException(\sprintf('Library node %d carries no object to link.', (int) $file->getId()));
        }

        return $file instanceof StagedUpload
            ? $this->stagedUploads->claim($file, $prefix, $filename)
            : $this->fileUploads->upload($prefix, $filename, $file);
    }

    /**
     * The library file this submission came from, or null when it was an ordinary upload.
     *
     * What the caller does with it is set the row's `library_node_id`, which is the only thing that
     * tells "where is this file used" apart from a coincidence of storage keys.
     */
    public static function libraryNodeOf(UploadedFile|StagedUpload|FileLibraryNode $file): ?FileLibraryNode
    {
        return $file instanceof FileLibraryNode ? $file : null;
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
    public function asLocalFile(UploadedFile|StagedUpload|FileLibraryNode $file): UploadedFile
    {
        if ($file instanceof UploadedFile) {
            return $file;
        }

        $key = $file instanceof FileLibraryNode ? (string) $file->getStorageKey() : $file->key;
        $path = tempnam(sys_get_temp_dir(), 'staged-read-');

        if (false === $path || false === file_put_contents($path, $this->fileUploads->read($key))) {
            throw new \RuntimeException(\sprintf('Could not fetch the stored object "%s" back for reading.', $key));
        }

        // test: true - the file did not arrive through PHP's upload handling, and refusing it on
        // that ground is exactly the check that does not apply here.
        return new UploadedFile($path, self::originalName($file), '' === self::mimeType($file) ? null : self::mimeType($file), null, true);
    }

    public static function originalName(UploadedFile|StagedUpload|FileLibraryNode $file): string
    {
        return match (true) {
            $file instanceof StagedUpload => $file->originalName,
            // The library node's *display* name, which is what a reader recognises - it may have been
            // renamed since the upload, and the row is a label rather than a filename.
            $file instanceof FileLibraryNode => $file->getName(),
            default => $file->getClientOriginalName(),
        };
    }

    public static function size(UploadedFile|StagedUpload|FileLibraryNode $file): int
    {
        return match (true) {
            $file instanceof StagedUpload => $file->size,
            $file instanceof FileLibraryNode => $file->getSizeBytes() ?? 0,
            default => (int) $file->getSize(),
        };
    }

    /**
     * The sniffed type - for a staged upload, the one the server read at staging time and carried in
     * the signed token, never what the browser claimed.
     */
    public static function mimeType(UploadedFile|StagedUpload|FileLibraryNode $file): string
    {
        if ($file instanceof StagedUpload) {
            return $file->mimeType;
        }

        if ($file instanceof FileLibraryNode) {
            return $file->getMimeType() ?? '';
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
    public static function extension(UploadedFile|StagedUpload|FileLibraryNode $file): string
    {
        return mb_strtolower(pathinfo(self::originalName($file), \PATHINFO_EXTENSION));
    }
}
