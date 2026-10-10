<?php

declare(strict_types=1);

namespace App\Service\TeacherDossier;

use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierSubmission;
use App\Repository\TeacherDossierSubmissionRepository;
use App\Service\FileUploadService;
use App\Service\StagedUpload;
use App\Service\UploadIntake;

/**
 * The bytes of a dossier intervenant: where they are written, and the only two ways they are read.
 *
 * **Nothing here ever hands out the CDN address.** App\Service\FileUploadService::url() answers a
 * permanent, unauthenticated address, which is fine for a course support and not for somebody's
 * identity card: a piece is opened through a route that asks App\Security\Voter\TeacherDossierVoter
 * first and then redirects to an address signed for a few minutes. `file_url()` must never be
 * called on one of these keys - which is why the screens are given a route, not a key.
 *
 * The key carries sixteen random bytes: the dossier's id is a guess away, the rest is not.
 */
class TeacherDossierFiles
{
    /** The establishment excludes this prefix from its CDN (design/validated/dossiers-intervenants.md, §9). */
    public const string PREFIX = 'teacher-dossiers/';

    public function __construct(
        private readonly UploadIntake $uploads,
        private readonly FileUploadService $files,
        private readonly TeacherDossierSubmissionRepository $submissions,
    ) {
    }

    /**
     * @return non-empty-string the storage key
     */
    public function store(TeacherDossier $dossier, StagedUpload $file): string
    {
        return $this->uploads->store(
            $file,
            self::PREFIX.(int) $dossier->getId().'/',
            bin2hex(random_bytes(16)).'.'.UploadIntake::extension($file),
        );
    }

    /** A short-lived address that shows the piece (a PDF or a picture opens in the browser). */
    public function viewUrl(TeacherDossierSubmission $submission): ?string
    {
        $key = $submission->getStorageKey();

        return null === $key ? null : $this->files->downloadUrl($key, $submission->getOriginalFilename() ?? '');
    }

    /** A short-lived address that saves the piece under the name it was handed in with. */
    public function downloadUrl(TeacherDossierSubmission $submission): ?string
    {
        $key = $submission->getStorageKey();

        return null === $key ? null : $this->files->attachmentUrl($key, $submission->getOriginalFilename() ?? '');
    }

    /** @return non-empty-string */
    public function read(TeacherDossierSubmission $submission): string
    {
        return $this->files->read((string) $submission->getStorageKey());
    }

    /**
     * Hands every file of a dossier to the deferred purge - called before the dossier's rows go,
     * since the keys are only written on them.
     */
    public function forget(TeacherDossier $dossier): void
    {
        foreach ($this->submissions->storageKeysOf($dossier) as $key) {
            $this->files->delete($key);
        }
    }
}
