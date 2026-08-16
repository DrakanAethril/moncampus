<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\FileUploadService;
use App\Service\StagedUploadStore;
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
 * this is what removes them. It has two passes, and this lot ships the first:
 *
 * | Pass    | Rule                                                                |
 * |---------|---------------------------------------------------------------------|
 * | staged  | unclaimed staged uploads older than 24 h                             |
 * | deleted | (lot 1 bis) DeletedObject rows past their origin's retention window  |
 *
 * A staged object is one a teacher's browser sent and no form ever claimed - a screen abandoned
 * with files in the picker. It is the honest cost of uploading before the user commits to the form,
 * and paying it nightly is what keeps `staged/` from growing without bound. An S3 lifecycle rule on
 * that prefix is the belt to these suspenders, not a replacement: the rule cannot be read from the
 * code, and this can.
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
 * must be set wherever this runs. The staged pass alone touches no row, but the deleted pass will.
 */
#[AsCommand(
    name: 'app:uploads:purge',
    description: 'Supprime définitivement les objets en attente : dépôts anticipés jamais utilisés, puis fichiers supprimés hors délai.',
)]
class PurgeUploadsCommand extends Command
{
    private const int DEFAULT_LIMIT = 500;

    public function __construct(
        private readonly StagedUploadStore $stagedUploads,
        private readonly FileUploadService $fileUploads,
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

        return $this->purgeStaged($io, $limit, $dryRun);
    }

    /** @return int a Command:: exit code */
    private function purgeStaged(SymfonyStyle $io, int $limit, bool $dryRun): int
    {
        $keys = $this->stagedUploads->stale(new \DateTimeImmutable(), $limit);

        if ([] === $keys) {
            $io->writeln('Dépôts anticipés : rien à supprimer.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->writeln(\sprintf('Dépôts anticipés : %d objet(s) seraient supprimés.', \count($keys)));
            $io->listing(\array_slice($keys, 0, 20));

            return Command::SUCCESS;
        }

        $purged = 0;
        $failures = [];

        foreach ($keys as $key) {
            try {
                // Immediate and not deferred, unlike a user deletion: nobody asked for this object
                // to exist, and routing it through the thirty-day window would mean keeping bytes
                // for a file that was never anything but an accident.
                $this->fileUploads->delete($key);
                ++$purged;
            } catch (\Throwable $failure) {
                $failures[] = \sprintf('%s : %s', $key, $failure->getMessage());
            }
        }

        $io->writeln(\sprintf('Dépôts anticipés : %d objet(s) supprimés.', $purged));

        if ([] !== $failures) {
            $io->error(\sprintf('%d objet(s) n’ont pas pu être supprimés.', \count($failures)));
            $io->listing(\array_slice($failures, 0, 20));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
