<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AudioListenProgress;
use App\Entity\AudioRecording;
use App\Entity\AudioRecordingFile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AudioListenProgress>
 */
class AudioListenProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AudioListenProgress::class);
    }

    public function findOneFor(AudioRecordingFile $file, User $student): ?AudioListenProgress
    {
        return $this->findOneBy(['file' => $file, 'student' => $student]);
    }

    /**
     * One student's progress on every file of a recording, keyed by file id - one query for the lot,
     * the student screen showing several at once.
     *
     * @return array<int, AudioListenProgress>
     */
    public function findByFileIdForStudent(AudioRecording $recording, User $student): array
    {
        $rows = $this->createQueryBuilder('p')
            ->innerJoin('p.file', 'f')
            ->where('f.recording = :recording')
            ->andWhere('p.student = :student')
            ->setParameter('recording', $recording)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        $byFileId = [];
        foreach ($rows as $row) {
            $byFileId[(int) $row->getFile()->getId()] = $row;
        }

        return $byFileId;
    }

    /**
     * All of a recording's progress, for the statistics screen: keyed by student then by file, in
     * one query rather than one per cell of the table.
     *
     * @return array<int, array<int, AudioListenProgress>>
     */
    public function findByStudentAndFileForRecording(AudioRecording $recording): array
    {
        $rows = $this->createQueryBuilder('p')
            ->addSelect('f')
            ->innerJoin('p.file', 'f')
            ->where('f.recording = :recording')
            ->setParameter('recording', $recording)
            ->getQuery()
            ->getResult();

        $byStudentId = [];
        foreach ($rows as $row) {
            $byStudentId[(int) $row->getStudent()->getId()][(int) $row->getFile()->getId()] = $row;
        }

        return $byStudentId;
    }
}
