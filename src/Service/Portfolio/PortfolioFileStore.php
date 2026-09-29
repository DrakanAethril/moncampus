<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Service\FileUploadService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Writes the files the portfolio generates - a deposit's .xlsx and PDF, an official template - to
 * the uploads storage, under `portfolio/`, through the one upload service (never the filesystem).
 */
class PortfolioFileStore
{
    public function __construct(
        private readonly FileUploadService $uploads,
    ) {
    }

    /**
     * @param non-empty-string $filename the name under `portfolio/<folder>/`
     *
     * @return non-empty-string the storage key
     */
    public function put(string $bytes, string $folder, string $filename): string
    {
        $path = tempnam(sys_get_temp_dir(), 'portfolio-');
        if (false === $path) {
            throw new \RuntimeException('Could not create a temporary file.');
        }

        try {
            file_put_contents($path, $bytes);

            return $this->uploads->upload(PortfolioEvidenceIntake::PREFIX.trim($folder, '/').'/', $filename, new UploadedFile($path, $filename, null, null, true));
        } finally {
            @unlink($path);
        }
    }

    public function read(string $key): string
    {
        return $this->uploads->read($key);
    }

    /** A local copy of a stored file, for the readers that need a path (the .xlsx template). */
    public function localCopy(string $key): string
    {
        $path = tempnam(sys_get_temp_dir(), 'portfolio-');
        if (false === $path) {
            throw new \RuntimeException('Could not create a temporary file.');
        }
        file_put_contents($path, $this->uploads->read($key));

        return $path;
    }
}
