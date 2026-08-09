<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AudioRecordingFile;
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
}
