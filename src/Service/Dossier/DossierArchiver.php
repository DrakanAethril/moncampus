<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier;
use App\Service\FileUploadService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * « Télécharger le dossier (.zip) » — every dépôt of a dossier, filed by cible.
 *
 * One folder per cible, one file per document, named `<document> - <fichier>` so that a folder read
 * outside the application still says which piece is which. The URL dépôts have no bytes to put in an
 * archive, so each cible's folder carries a single `liens.txt` listing them - dropping them silently
 * would make the archive read as if the cible had handed in nothing.
 *
 * Only the **latest version** of each dépôt is archived. The earlier ones are the history of a
 * correction, which is read on the screen where the comment that asked for it also lives; an archive
 * holding three versions of the same rapport is an archive nobody can hand to a jury.
 *
 * The file is built on disk and served with `deleteFileAfterSend()`: a dossier of thirty cibles is
 * tens of megabytes, and building it in memory is how a 256M worker dies without saying why.
 */
class DossierArchiver
{
    public function __construct(
        private readonly FileUploadService $files,
    ) {
    }

    public function respond(Dossier $dossier, DossierBoard $board): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'dossier-');

        if (false === $path) {
            throw new FileException('Cannot create the archive file.');
        }

        $zip = new \ZipArchive();

        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new FileException('Cannot open the archive file.');
        }

        foreach ($board->rows as $row) {
            $folder = $this->sanitise($row->student->getDisplayName() ?? $row->student->getUsername());
            $links = [];

            foreach ($row->cells as $cell) {
                $submission = $cell->latest;

                if (null === $submission) {
                    continue;
                }

                $document = $this->sanitise($cell->document->getName());

                if (null !== $submission->getUrl()) {
                    $links[] = $document.' : '.$submission->getUrl();
                    continue;
                }

                $key = $submission->getStorageKey();

                if (null === $key) {
                    continue;
                }

                $zip->addFromString(
                    $folder.'/'.$document.' - '.$this->sanitise($submission->getOriginalFilename() ?? 'fichier'),
                    $this->files->read($key),
                );
            }

            if ([] !== $links) {
                $zip->addFromString($folder.'/liens.txt', implode("\n", $links)."\n");
            }

            // A cible who deposited nothing gets no folder at all: an empty ZIP directory entry is
            // not what « rien déposé » should look like, and the Suivi screen says it better.
        }

        $zip->close();

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->sanitise($dossier->getTitle()).'.zip');
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
