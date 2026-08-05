<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\EnterpriseRecipientResolver;
use App\Service\SchoolMailSender;
use App\Service\StudentMailboxResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * DEVELOPMENT TOOL - sends a school mail on a student's behalf to an arbitrary address, bypassing
 * the compose screen's rules.
 *
 * Exists for one experiment the UI cannot run: sending to an address on our own catch-all domain,
 * which the screens forbid (principle #8, the "To" only accepts outside addresses). The mail then
 * comes back through SES reception, and reading what actually arrived is the only way to know which
 * headers SES kept.
 *
 * Not versioned, like the other dev tools.
 */
#[AsCommand(name: 'app:dev:send-school-mail', description: '[dev] Envoie un mail Courrier école à une adresse arbitraire.')]
class DevSendSchoolMailCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly SchoolMailSender $sender,
        private readonly StudentMailboxResolver $mailboxResolver,
        private readonly EnterpriseRecipientResolver $resolver,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('student', null, InputOption::VALUE_REQUIRED, "Login de l'élève expéditeur", 'info3-001')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Adresse destinataire')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Objet', 'Test de boucle Courrier école');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('dev' !== $this->environment) {
            $io->error('Commande réservée à l\'environnement de développement.');

            return Command::FAILURE;
        }

        $student = $this->userRepository->findOneBy(['username' => (string) $input->getOption('student')]);
        $to = (string) $input->getOption('to');

        if (null === $student || '' === $to) {
            $io->error('Élève introuvable ou destinataire manquant.');

            return Command::FAILURE;
        }

        $resolution = $this->resolver->resolve($to, $student);
        $enterprise = $resolution['enterprise'] ?? (new \App\Entity\Enterprise('Boucle de test'))->setCreatedBy($student);

        $message = $this->sender->send(
            $student,
            $this->resolver->applicationFor($student, $enterprise),
            (string) $this->mailboxResolver->addressFor($student),
            $to,
            (string) $input->getOption('subject'),
            "Bonjour,\n\nCeci est un test de boucle : ce mail doit revenir par la réception SES.\n",
        );

        $io->success(sprintf('Envoyé. Message-ID posé par l\'application : %s', (string) $message->getMessageId()));

        return Command::SUCCESS;
    }
}
