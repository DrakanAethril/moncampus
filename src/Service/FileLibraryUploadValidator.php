<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The per-file ceiling inside the library - which is the **platform's**, not the library's own
 * (design/validated/file-library.md, "Quota").
 *
 * 200 Mo for the three video extensions, 20 Mo for everything else, and both numbers keep coming
 * from the constants that already hold them: `UploadPolicy::PLATFORM_MAX_SIZE` and
 * `FileUploadDefaults::MAX_SIZE`. **The library invents no limit of its own** - it is the one place
 * that accepts everything the platform accepts, exactly as the wiki does, so the policy it applies
 * is `UploadPolicy::platform()` unnarrowed with the right ceiling picked per file.
 *
 * Picking by *extension* rather than by sniffed type is deliberate and matches the rest of the
 * upload policy: the name has already been cross-checked against the content by
 * App\Validator\AllowedUpload, and it is the half a human recognises.
 */
class FileLibraryUploadValidator
{
    /** @var list<string> the extensions a 200 Mo ceiling exists for */
    private const array VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov'];

    /** The policy this file is checked against - the platform rule, with its own ceiling. */
    public function policyFor(string $filename): UploadPolicy
    {
        return UploadPolicy::platform()->withMaxSize($this->maxSizeFor($filename));
    }

    public function maxSizeFor(string $filename): string
    {
        return $this->isVideo($filename)
            ? UploadPolicy::PLATFORM_MAX_SIZE
            : \App\Form\FileUploadDefaults::MAX_SIZE;
    }

    public function maxBytesFor(string $filename): int
    {
        return $this->policyFor($filename)->maxSizeInBytes();
    }

    /**
     * The ceiling the browser is told about before anything is chosen, which cannot depend on a file
     * nobody has picked yet: the largest of the two, so the picker's courtesy check never refuses a
     * video the server would have accepted. The server then applies the per-file rule.
     */
    public function announcedMaxBytes(): int
    {
        return UploadPolicy::platform()->withMaxSize(UploadPolicy::PLATFORM_MAX_SIZE)->maxSizeInBytes();
    }

    public function isVideo(string $filename): bool
    {
        return \in_array(mb_strtolower(pathinfo($filename, \PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true);
    }
}
