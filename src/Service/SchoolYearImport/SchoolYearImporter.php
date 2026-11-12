<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

use App\Entity\SchoolYear;
use App\Entity\SchoolYearImport;
use App\Entity\User;
use App\Service\FileUploadService;
use App\Service\SchoolYearImport\Copier\SyllabusCopier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The only door through which the school-year import writes (design/validated/school-year-import.md).
 *
 * Under a lock per target year (R3), in one transaction: the plan is computed again - never taken
 * from screen 3, so two tabs importing the same thing create nothing twice - and walked for real by
 * the same copiers, then the journal is written (R11). Two flushes inside the transaction, the
 * first before the CRM, whose settings are read by formation id; an error anywhere rolls the whole
 * import back, journal included, and hands the PDFs already copied to the deferred purge.
 */
final readonly class SchoolYearImporter
{
    private const int LOCK_TTL = 300;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SchoolYearImportPlanner $planner,
        private SyllabusCopier $syllabus,
        private FileUploadService $files,
        private LockFactory $lockFactory,
    ) {
    }

    /** @throws SchoolYearImportRefusal when R1 does not hold, or a formation would be nameless */
    public function import(SchoolYear $source, SchoolYear $target, SchoolYearImportChoices $choices, User $actor): SchoolYearImport
    {
        $lock = $this->lockFactory->createLock('school-year-import-'.$target->getId(), self::LOCK_TTL);
        $lock->acquire(true);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $this->syllabus->takeWritten();

        try {
            $run = $this->planner->begin($source, $target, $choices, false, $actor);
            $this->planner->walk($run, function (array $pairs) use ($run): void {
                $this->entityManager->flush();
                foreach ($pairs as $pair) {
                    $run->report->settle($pair);
                }
            });

            $journal = new SchoolYearImport($source, $target, $choices->toArray(), $run->report->toArray(), $actor, $run->now);
            $this->entityManager->persist($journal);
            $this->entityManager->flush();
            $connection->commit();
            $this->syllabus->takeWritten();

            return $journal;
        } catch (\Throwable $error) {
            $connection->rollBack();
            $this->entityManager->clear();
            foreach ($this->syllabus->takeWritten() as $key) {
                if ('' !== $key) {
                    $this->files->delete($key);
                }
            }

            throw $error;
        } finally {
            $lock->release();
        }
    }
}
