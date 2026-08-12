<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AudioRecordingFile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AudioRecordingFile>
 */
class AudioRecordingFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AudioRecordingFile::class);
    }

    /**
     * How far into their files a student is on each of these recordings, taken as the lowest of
     * them - what an audio_listened access condition reads, in one query however many recordings
     * the conditions of a screen name.
     *
     * The lowest rather than a duration-weighted average, unlike the statistics screen: a condition
     * asking for 100 % means every file listened through, which is exactly how the audio tool
     * defines a completed listening, and an average would let a long file carry a skipped one. A
     * file never played has no progress row and counts as 0, which is what the LEFT JOIN produces.
     *
     * Individual files are filtered to this student's own, so a recording holds one percentage per
     * student rather than one per class.
     *
     * @param list<int> $recordingIds
     *
     * @return array<int, int> recording id => lowest percentage across the student's files
     */
    public function findLowestPercentByRecordingIdForStudent(array $recordingIds, User $student): array
    {
        if ([] === $recordingIds) {
            return [];
        }

        /** @var list<array{recordingId: int|string, percent: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.recording) AS recordingId', 'p.maxListenedPercent AS percent')
            ->leftJoin(
                'App\Entity\AudioListenProgress',
                'p',
                'WITH',
                'p.file = f AND p.student = :student',
            )
            ->where('f.recording IN (:recordings)')
            ->andWhere('f.student IS NULL OR f.student = :student')
            ->setParameter('recordings', $recordingIds)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        $lowest = [];
        foreach ($rows as $row) {
            $recordingId = (int) $row['recordingId'];
            $percent = (int) $row['percent'];
            $lowest[$recordingId] = min($lowest[$recordingId] ?? 100, $percent);
        }

        return $lowest;
    }
}
