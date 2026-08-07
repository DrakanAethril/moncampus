<?php

namespace App\Service;

use App\Entity\AudioListenProgress;
use App\Entity\AudioRecording;
use App\Entity\AudioRecordingFile;
use App\Entity\User;
use App\Repository\AudioListenProgressRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single place listening is reported to, whichever player is used: the web screen
 * (App\Controller\StudentWorkController) and the mobile app
 * (App\Controller\Api\AudioRecordingController) both call in here, exactly as the two quiz screens
 * lean on the same StudentWorkBoard. The handoff requires it: "mêmes événements de progression,
 * mêmes règles de complétion, quel que soit le lecteur utilisé par l'étudiant".
 */
class AudioListenTracker
{
    public function __construct(
        private readonly AudioListenProgressRepository $progressRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return int the percentage kept once the ratchet has been applied */
    public function register(AudioRecordingFile $file, User $student, int $percent): int
    {
        $progress = $this->progressRepository->findOneFor($file, $student);

        if (null === $progress) {
            $progress = new AudioListenProgress($file, $student);
            $this->entityManager->persist($progress);
        }

        $progress->registerProgress($percent);
        $this->entityManager->flush();

        return $progress->getMaxListenedPercent();
    }

    /**
     * What the student has listened to of each of their files, keyed by file id - enough to paint
     * their progress bars, and enough to tell the player where to resume crediting from one session
     * to the next.
     *
     * @return array<int, int>
     */
    public function progressPercents(AudioRecording $recording, User $student): array
    {
        $byFileId = $this->progressRepository->findByFileIdForStudent($recording, $student);
        $percents = [];

        foreach ($recording->getFilesFor($student) as $file) {
            // `?? null` before the nullsafe call, not `?->` alone: the nullsafe operator does not
            // suppress the "undefined array key" warning for a file never listened to, and this app
            // promotes warnings to exceptions.
            $percents[(int) $file->getId()] = ($byFileId[(int) $file->getId()] ?? null)?->getMaxListenedPercent() ?? 0;
        }

        return $percents;
    }

    /**
     * When the listening was completed: the timestamp of the last file to fall into place, that is
     * the moment the final one reached 100%. Null while any is still missing.
     */
    public function completedAt(AudioRecording $recording, User $student): ?\DateTimeImmutable
    {
        $files = $recording->getFilesFor($student);

        if ([] === $files) {
            return null;
        }

        $byFileId = $this->progressRepository->findByFileIdForStudent($recording, $student);
        $dates = [];

        foreach ($files as $file) {
            $progress = $byFileId[(int) $file->getId()] ?? null;
            if (null === $progress || !$progress->isComplete()) {
                return null;
            }

            $dates[] = $progress->getLastListenedAt();
        }

        $dates = array_values(array_filter($dates));

        return [] === $dates ? null : max($dates);
    }
}
