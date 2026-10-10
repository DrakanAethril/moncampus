<?php

declare(strict_types=1);

namespace App\Service\TeacherDossier;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * « Télécharger (.zip) » — every piece of one dossier intervenant, as it stands.
 *
 * One file per piece, named `<pièce> - <fichier>`, filed under its group, and the **latest version
 * only**: the earlier ones are the history of a correction, read on screen next to the comment that
 * asked for it. URL dépôts have no bytes, so they are listed in a `liens.txt` rather than dropped.
 *
 * Built on disk and deleted once sent, like App\Service\Dossier\DossierArchiver: building it in
 * memory is how a 256M worker dies without saying why.
 */
class TeacherDossierArchiver
{
    public function __construct(
        private readonly TeacherDossierFiles $files,
    ) {
    }

    public function respond(TeacherDossierSheet $sheet): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'teacher-dossier-');

        if (false === $path) {
            throw new FileException('Cannot create the archive file.');
        }

        $zip = new \ZipArchive();

        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new FileException('Cannot open the archive file.');
        }

        $links = [];
        $empty = true;

        foreach ($sheet->lines as $line) {
            $submission = $line->latest;

            if (null === $submission) {
                continue;
            }

            $piece = $this->sanitise($line->document->getName());

            if (null !== $submission->getUrl()) {
                $links[] = $piece.' : '.$submission->getUrl();
                continue;
            }

            if (null === $submission->getStorageKey()) {
                continue;
            }

            $group = $line->document->getGroup();
            $folder = null === $group ? '' : $this->sanitise($group->getTitle()).'/';

            $zip->addFromString(
                $folder.$piece.' - '.$this->sanitise($submission->getOriginalFilename() ?? 'fichier'),
                $this->files->read($submission),
            );
            $empty = false;
        }

        // A ZIP with no entry at all is not a valid archive for most readers: an empty dossier
        // still downloads something that opens, and that says why it is empty.
        if ([] !== $links || $empty) {
            $zip->addFromString('liens.txt', implode("\n", $links)."\n");
        }

        $zip->close();

        $teacher = $sheet->dossier->getTeacher();
        $name = trim(($teacher?->getDisplayName() ?? $teacher?->getUsername() ?? '').' - '.$sheet->dossier->getTitle(), ' -');

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->sanitise($name).'.zip', 'dossier.zip');
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
