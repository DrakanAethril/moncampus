<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown when clamd names a signature in an uploaded file.
 *
 * Distinct from App\Service\ClamAvUnavailableException on purpose: the two refuse the same upload
 * but mean opposite things - "we looked and this is hostile" against "we could not look". The user
 * message differs, and so does what an administrator should do about it.
 */
class InfectedUploadException extends \RuntimeException
{
    public function __construct(
        public readonly string $signature,
        public readonly string $filename,
    ) {
        parent::__construct(\sprintf('Upload "%s" was refused: %s.', $filename, $signature));
    }
}
