<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DeletedObjectRepository;
use App\Service\ObjectStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The nightly cleanup of the uploads bucket (design/validated/object-deletion.md, "The purge").
 *
 * One command and one cron line, because there is one thing to say about this platform's bytes:
 * this is what removes them. It walks App\Entity\DeletedObject and removes every object whose
 * retention window has run out - thirty days for a teacher's deleted course material, one day for
 * the two origins nobody asked to keep:
 *
 * | Origin  | Window | What it is                                                            |
 * |---------|--------|-----------------------------------------------------------------------|
 * | staged  | 1 day  | an upload a teacher's browser sent and no form ever claimed            |
 * | import  | 1 day  | images extracted from a document whose assistant was abandoned         |
 * | (other) | 30 days| a file somebody deleted, and may still want back from a corbeille      |
 *
 * The staged case is the honest cost of uploading before the user commits to a form, and paying it
 * nightly is what keeps `staged/` from growing without bound. design/validated/object-deletion.md
 * gave it a pass of its own, listing the prefix by age; it is folded in here instead, because
 * App\Service\StagedUploadStore now schedules each staged object at the moment it writes it - see
 * that class for why (the IAM user has no `s3:ListBucket`, so there is nothing to list with).
 *
 * An S3 lifecycle rule on `staged/` is the belt to these suspenders, not a replacement: the rule
 * cannot be read from the code, and this can.
 *
 * Three properties it must keep, each for a reason already met in this repository:
 *
 * - **idempotent and resumable** - `--limit` bounds a run, and a key already gone is a success, so
 *   a run killed halfway costs nothing;
 * - **one failure does not stop the batch** - a key that refuses to go is counted and reported, and
 *   the run carries on to the next;
 * - **it fails loudly.** A non-zero exit when anything could not be removed, because a purge that
 *   reports success while the bytes remain turns a permission problem into a permanent, invisible
 *   leak - which is the failure the whole design exists to avoid.
 *
 * Cron context note: `symfony/ux-turbo` pings Mercure on every flush, CLI included, so `MERCURE_URL`
 * must be set wherever this runs - the deleted pass flushes on every batch.
 */
#[AsCommand(
    name: 'app:uploads:purge',
    description: 'Supprime définitivement les objets dont le délai de conservation est écoulé.',
)]
class PurgeUploadsCommand extends Command
{
    private const int DEFAULT_LIMIT = 500;

    public function __construct(
        private readonly ObjectStore $objectStore,
        private readonly DeletedObjectRepository $deletedObjects,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum d’objets traités en une passe.', (string) self::DEFAULT_LIMIT)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste ce qui serait supprimé, sans rien supprimer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Purge des dépôts');

        return $this->purgeDeleted($io, $limit, $dryRun);
    }

    /**
     * The pass that ends the thirty-day window: every App\Entity\DeletedObject whose retention has
     * run out, its bytes removed and `purgedAt` stamped **after** the removal succeeded.
     *
     * Stamping after and not before is the whole of the honesty this design asks for: a row marked
     * purged while the object is still in the bucket would turn a permission problem into a
     * permanent, invisible leak. A failure is counted on the row (`attempts`, `lastError`) and
     * retried the next night, and the run still exits non-zero so the failure is seen.
     *
     * @return int a Command:: exit code
     */
    private function purgeDeleted(SymfonyStyle $io, int $limit, bool $dryRun): int
    {
        $now = new \DateTimeImmutable();
        $cutoffByOrigin = [];

        foreach (array_keys(ObjectStore::RETENTION_DAYS_BY_ORIGIN) as $origin) {
            $cutoffByOrigin[$origin] = $now->modify(\sprintf('-%d days', ObjectStore::retentionDaysFor($origin)));
        }

        $due = $this->deletedObjects->findDue($cutoffByOrigin, $now->modify(\sprintf('-%d days', ObjectStore::DEFAULT_RETENTION_DAYS)), $limit);

        if ([] === $due) {
            $io->writeln('Fichiers supprimés : rien à purger.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->writeln(\sprintf('Fichiers supprimés : %d objet(s) seraient purgés.', \count($due)));
            $io->listing(array_map(static fn ($row): string => \sprintf('%s (%s, supprimé le %s)', $row->getStorageKey(), $row->getOrigin(), $row->getDeletedAt()->format('d/m/Y')), \array_slice($due, 0, 20)));

            return Command::SUCCESS;
        }

        $purged = 0;
        $failures = [];

        foreach ($due as $row) {
            try {
                $this->objectStore->remove($row->getStorageKey());
                $row->setPurgedAt(new \DateTimeImmutable());
                ++$purged;
            } catch (\Throwable $failure) {
                $row->recordFailure($failure->getMessage());
                $failures[] = \sprintf('%s : %s', $row->getStorageKey(), $failure->getMessage());
            }
        }

        // One flush for the batch: a run killed halfway then re-does the objects it had already
        // removed, and removing an object that has gone is a success everywhere in this command.
        $this->entityManager->flush();

        $io->writeln(\sprintf('Fichiers supprimés : %d objet(s) purgés.', $purged));

        if ([] !== $failures) {
            $io->error(\sprintf('%d objet(s) n’ont pas pu être purgés ; ils seront retentés.', \count($failures)));
            $io->listing(\array_slice($failures, 0, 20));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
