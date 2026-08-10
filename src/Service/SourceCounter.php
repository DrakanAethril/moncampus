<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Counts files and lines under a directory of the deployed application.
 *
 * Exists so the "Description technique" screen can state its volumetry without anyone typing a
 * number into a template: a figure written by hand is true the day it is written. Everything this
 * class counts lives inside the production image (src/, templates/, migrations/, assets/) - .git/
 * and tests/ do not, which is why those two figures come from config/tech_profile.yaml instead.
 *
 * A missing directory counts zero rather than raising: the screen must render on any installation.
 */
class SourceCounter
{
    public function files(string $directory, string $extension, string $nameSuffix = ''): int
    {
        $count = 0;
        foreach ($this->walk($directory, $extension, $nameSuffix) as $ignored) {
            ++$count;
        }

        return $count;
    }

    public function lines(string $directory, string $extension, string $nameSuffix = ''): int
    {
        $lines = 0;

        foreach ($this->walk($directory, $extension, $nameSuffix) as $path) {
            $content = @file_get_contents($path);

            if (false === $content || '' === $content) {
                continue;
            }

            // A file whose last line carries no newline still holds that line, hence the +1 unless
            // the content ends on one.
            $lines += substr_count($content, "\n") + (str_ends_with($content, "\n") ? 0 : 1);
        }

        return $lines;
    }

    /**
     * @return iterable<string> absolute paths of the matching files
     */
    private function walk(string $directory, string $extension, string $nameSuffix): iterable
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== $extension) {
                continue;
            }

            if ('' !== $nameSuffix && !str_ends_with($file->getBasename('.'.$extension), $nameSuffix)) {
                continue;
            }

            yield $file->getPathname();
        }
    }
}
