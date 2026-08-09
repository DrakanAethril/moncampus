<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\StudentMailProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Hands their School mail address to the students already in the database: new ones will get theirs
 * at creation time, but the existing population has to be backfilled once.
 *
 * Idempotent: a student already provisioned is skipped, so the command can be re-run safely after a
 * new cohort is added. `--dry-run` shows what would be created without writing anything, which is
 * the sane way to inspect the awkward cases (compound names, particles, namesakes) before freezing
 * addresses that will end up printed on CVs.
 */
#[AsCommand(
    name: 'app:mail:backfill-student-aliases',
    description: 'Attribue leur adresse Courrier école aux élèves qui n\'en ont pas encore.',
)]
class BackfillStudentMailAliasesCommand extends Command
{
    /** Batched writes, so the whole cohort never sits in the unit of work at once. */
    private const int FLUSH_EVERY = 50;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly StudentMailProvisioner $provisioner,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les adresses qui seraient créées, sans rien écrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $students = $this->userRepository->findActiveMatchingRoles(['ROLE_STUDENT']);

        if ([] === $students) {
            $io->warning('Aucun élève actif trouvé.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Adresses Courrier école — %d élève(s) actif(s)', \count($students)));

        $rows = [];
        $created = 0;
        $skipped = 0;
        $failed = 0;
        $processed = 0;

        foreach ($students as $student) {
            try {
                $aliases = $this->provisioner->provisionFor($student);
            } catch (\RuntimeException $exception) {
                // A student with no usable name must not interrupt the backfill of the others.
                $io->warning($exception->getMessage());
                ++$failed;

                continue;
            }

            if ([] === $aliases) {
                ++$skipped;

                continue;
            }

            ++$created;
            $primary = $student->getPrimaryAlias();
            $rows[] = [
                $student->getUsername(),
                trim(($student->getFirstname() ?? '').' '.($student->getLastname() ?? '')),
                implode(', ', array_map(
                    static fn ($alias): string => $alias->getLocalPart().($alias === $primary ? ' (primaire)' : ''),
                    $aliases,
                )),
            ];

            // In dry-run we clear the unit of work instead of flushing it: the generator queries
            // the repository, so pending aliases must still count towards namesake detection within
            // a single pass.
            if (!$dryRun && 0 === ++$processed % self::FLUSH_EVERY) {
                $this->entityManager->flush();
            }
        }

        if ($dryRun) {
            $this->entityManager->clear();
        } else {
            $this->entityManager->flush();
        }

        if ([] !== $rows) {
            $io->table(['Login', 'Nom', 'Adresses'], $rows);
        }

        $io->success(sprintf(
            '%s : %d élève(s) pourvu(s), %d déjà pourvu(s), %d en échec.',
            $dryRun ? 'Simulation' : 'Reprise terminée',
            $created,
            $skipped,
            $failed,
        ));

        return Command::SUCCESS;
    }
}
