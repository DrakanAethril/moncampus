<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A file that has already reached the bucket, but that no feature owns yet
 * (design/validated/file-library.md, "Staged uploads").
 *
 * It is what a form field carries between the moment the browser sent the bytes - on its own XHR,
 * which is the only way a progress bar can exist at all - and the moment the form is submitted and
 * a controller claims it into its own prefix.
 *
 * It deliberately looks like an UploadedFile from the outside for the two questions a validator
 * asks (what is it called, what does it weigh), because App\Validator\AllowedUploadValidator then
 * accepts either shape and **every field keeps its own narrowing** with no change to the field
 * itself.
 *
 * The `mimeType` is what the server sniffed at stage time, never what the browser claimed - see
 * App\Service\StagedUploadStore::stage(). Nothing here is client-controlled: the token is signed,
 * and everything else is read back out of it.
 */
final class StagedUpload
{
    public function __construct(
        public readonly string $token,
        public readonly string $key,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $size,
    ) {
    }
}
