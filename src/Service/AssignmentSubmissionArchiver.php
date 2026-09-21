<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * « Télécharger tous les dépôts (.zip) » - every file handed in for one travail, filed by student.
 *
 * One folder per student, named as the follow-up table names them, so a folder read outside the
 * application still says whose work it holds. A travail spelling out several expected productions
 * prefixes each file with the production it answers: two students handing in `rapport.pdf` and
 * `annexe.pdf` for two different productions is the ordinary case, and a flat folder would leave
 * the teacher to guess which is which.
 *
 * A student who deposited nothing gets no folder at all, like App\Service\Dossier\DossierArchiver:
 * an empty ZIP directory entry is not what « non rendu » should look like, and the table above says
 * it better.
 *
 * Built on disk and served with `deleteFileAfterSend()`, for the same reason as the two archivers
 * that came before it: a class handing in videos is tens of megabytes, and holding that in memory
 * is how a 256M worker dies without saying why.
 */
class AssignmentSubmissionArchiver
{
    public function __construct(
        private readonly FileUploadService $files,
    ) {
    }

    /** @param list<AssignmentFollowUpRow> $rows */
    public function respond(Assignment $assignment, array $rows): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'assignment-');

        if (false === $path) {
            throw new FileException('Cannot create the archive file.');
        }

        $zip = new \ZipArchive();

        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new FileException('Cannot open the archive file.');
        }

        // Named once for the whole archive rather than per student: whether a file carries its
        // production's name is a property of the travail, not of who handed it in, so two students'
        // folders must not be shaped differently.
        $namesProductions = $assignment->getExpectedProductions()->count() > 1;

        foreach ($rows as $row) {
            $folder = $this->sanitise($row->student->getDisplayName() ?? $row->student->getUsername());

            foreach ($row->submissions as $submission) {
                $production = $submission->getExpectedProduction();

                foreach ($submission->getFiles() as $file) {
                    $key = $file->getStorageKey();

                    if (null === $key) {
                        continue;
                    }

                    $name = $this->sanitise($file->getOriginalFilename() ?? 'fichier');

                    if ($namesProductions && null !== $production) {
                        $name = $this->sanitise($production->getName()).' - '.$name;
                    }

                    $zip->addFromString($folder.'/'.$name, $this->files->read($key));
                }
            }
        }

        $zip->close();

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->sanitise($assignment->getTitle() ?? 'travail').'.zip');
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
