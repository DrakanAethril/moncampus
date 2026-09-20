<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EmailMessageRepository;
use App\Repository\UserRepository;
use App\Service\SchoolMailApplicationRecovery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-reads the Courrier pro mails that belong to a student but to no démarche, and files the ones
 * that name a send - App\Service\SchoolMailApplicationRecovery holds the whole rule.
 *
 * **A repair pass, not a cron.** It exists because the rule it applies was written after those rows
 * were stored: a delivery failure notice arriving before it existed was filed nowhere, and nothing
 * else will ever look at it again. Once the backlog is caught up, the inbound worker does this at
 * arrival and there is nothing left for a schedule to do.
 *
 * `--dry-run` names every mail it would file and the evidence it found, and writes nothing. That is
 * how this should be read first: the pass files mails under démarches a student never named, and
 * the only honest way to hand that over is to show the reason next to each one.
 */
#[AsCommand(
    name: 'app:mail:relink-applications',
    description: 'Rattache à leur démarche les mails du Courrier pro laissés sans démarche (rattrapage, pas de cron).',
)]
class RelinkSchoolMailCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly EmailMessageRepository $messageRepository,
        private readonly UserRepository $userRepository,
        private readonly SchoolMailApplicationRecovery $recovery,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('student', null, InputOption::VALUE_REQUIRED, 'Ne traiter que la boîte de cet identifiant.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nomme ce qui serait rattaché, sans rien écrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->comment('Une autre exécution est déjà en cours.');

            return Command::SUCCESS;
        }

        $login = $input->getOption('student');
        $student = null;

        if (\is_string($login) && '' !== $login) {
            $student = $this->userRepository->findOneBy(['username' => $login]);

            if (null === $student) {
                $io->error(sprintf('Aucun compte ne porte l\'identifiant « %s ».', $login));

                return Command::FAILURE;
            }
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $messages = $this->messageRepository->findInboundWithoutApplication($student);
        $linked = 0;

        foreach ($messages as $message) {
            $match = $this->recovery->recover($message);

            if (null === $match) {
                continue;
            }

            ++$linked;

            $io->writeln(sprintf(
                '  <info>%s</info> #%d « %s » (%s) → %s — %s',
                $dryRun ? '~' : '✓',
                (int) $message->getId(),
                $message->getSubject() ?? '(sans objet)',
                $message->getStudent()?->getUsername() ?? '?',
                $match->application->getName(),
                $match->describe(),
            ));

            if (!$dryRun) {
                $message->setJobApplication($match->application);
            }
        }

        if (!$dryRun && $linked > 0) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%d mail(s) sans démarche examiné(s), %d rattaché(s)%s.',
            \count($messages),
            $linked,
            $dryRun ? ' (simulation)' : '',
        ));

        return Command::SUCCESS;
    }
}
