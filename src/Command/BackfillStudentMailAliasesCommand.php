<?php

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
 * Attribue leur adresse Courrier école aux élèves déjà présents en base : les nouveaux la
 * recevront à la création, mais l'existant doit être repris une fois.
 *
 * Idempotente : un élève déjà pourvu est ignoré, donc la commande peut être relancée sans risque
 * après un ajout de promotion. `--dry-run` montre ce qui serait créé sans rien écrire, ce qui est
 * la façon raisonnable d'inspecter les cas tordus (noms composés, particules, homonymes) avant de
 * figer des adresses qui finiront imprimées sur des CV.
 */
#[AsCommand(
    name: 'app:mail:backfill-student-aliases',
    description: 'Attribue leur adresse Courrier école aux élèves qui n\'en ont pas encore.',
)]
class BackfillStudentMailAliasesCommand extends Command
{
    /** Écriture par lots, pour ne pas garder toute la promotion dans l'unit of work. */
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
                // Un élève sans nom exploitable ne doit pas interrompre la reprise des autres.
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

            // En dry-run on vide l'unit of work au lieu de la vider en base : le générateur
            // interroge le dépôt, donc les alias en attente doivent tout de même compter pour la
            // détection d'homonymes à l'intérieur d'un même passage.
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
