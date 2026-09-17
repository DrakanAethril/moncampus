<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FileLibraryNode;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * A library folder handed over as a single `.zip` - « Télécharger » on a row that names a folder.
 *
 * A file has an address of its own and is redirected to (App\Service\FileUploadService), so nothing
 * transits through PHP. A folder has none: an archive has to be built, and this is the one place
 * that builds it, so that every screen offering the gesture produces the same thing.
 *
 * The tree is kept: App\Service\FileLibrarySubtree already flattens it depth-first with each row's
 * depth, which is exactly what rebuilding a path out of it needs - a folder at depth d fixes the
 * d-th segment for everything that follows it until the depth drops back. An empty folder is written
 * as a directory entry rather than dropped: what was shared is a shape, and a folder that holds
 * nothing says something.
 *
 * Built on disk and served with `deleteFileAfterSend()`, like App\Service\Dossier\DossierArchiver:
 * holding the whole archive in memory is how a 256M worker dies without saying why.
 */
class FileLibraryArchiver
{
    public function __construct(
        private readonly FileLibrarySubtree $subtree,
        private readonly FileUploadService $files,
    ) {
    }

    public function respond(FileLibraryNode $folder): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'library-');

        if (false === $path) {
            throw new FileException('Cannot create the archive file.');
        }

        $zip = new \ZipArchive();

        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new FileException('Cannot open the archive file.');
        }

        /** @var list<string> $segments the folders currently open, one per depth */
        $segments = [];

        foreach ($this->subtree->rows($folder) as $row) {
            $node = $row['node'];

            // Back out of whatever is deeper than this row before naming it: the listing is
            // depth-first, so a shallower row means the folders under it are closed.
            $segments = \array_slice($segments, 0, $row['depth']);
            $prefix = [] === $segments ? '' : implode('/', $segments).'/';
            $name = $this->sanitise($node->getName());

            if ($node->isFolder()) {
                $segments[] = $name;
                $zip->addEmptyDir($prefix.$name);

                continue;
            }

            $key = $node->getStorageKey();

            if (null === $key) {
                continue;
            }

            $zip->addFromString($prefix.$name, $this->files->read($key));
        }

        $zip->close();

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->sanitise($folder->getName()).'.zip');
        $response->deleteFileAfterSend();

        return $response;
    }

    /** A path segment safe on every operating system that will open the archive. */
    private function sanitise(string $name): string
    {
        $clean = preg_replace('#[/\\\\:*?"<>|]+#', '-', $name) ?? $name;

        return trim($clean) ?: 'sans-nom';
    }
}
