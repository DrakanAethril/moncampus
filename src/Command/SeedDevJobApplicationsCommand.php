<?php

namespace App\Command;

use App\Entity\EmailAttachment;
use App\Entity\EmailMessage;
use App\Entity\Enterprise;
use App\Entity\JobApplication;
use App\Enum\EmailDeliveryStatus;
use App\Enum\EmailDirection;
use App\Enum\JobApplicationOrigin;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Outil de développement local : peuple les démarches de candidature d'un élève de dev avec les
 * données de la créa 2b, pour pouvoir regarder l'écran dans un navigateur.
 *
 * Non destiné à staging ni à la production.
 */
#[AsCommand(name: 'app:seed-dev-job-applications', description: 'Peuple des démarches de candidature de démonstration (dev local)')]
class SeedDevJobApplicationsCommand extends Command
{
    /** Les quatre démarches de la créa 2b, dans l'ordre où elle les affiche. */
    private const array APPLICATIONS = [
        [
            'enterprise' => 'Néopixel SAS',
            'domain' => 'neopixel.fr',
            'position' => 'Développeur web (alternance)',
            'contact' => 'Camille Berthier',
            'origin' => JobApplicationOrigin::Spontaneous,
            'sent' => ['-14 days', '-4 days'],
            'delivered' => true,
            'reply' => '-1 day',
            'replyAttachments' => ['Convention_stage.pdf'],
        ],
        [
            'enterprise' => 'Cegid — agence Limoges',
            'domain' => 'cegid.com',
            'position' => 'Alternance support / dev',
            'contact' => null,
            'origin' => JobApplicationOrigin::Offer,
            'sent' => ['-17 days'],
            'delivered' => true,
            'reply' => null,
            'replyAttachments' => [],
        ],
        [
            'enterprise' => 'Legrand — DSI',
            'domain' => 'legrand.fr',
            'position' => 'Stage administration système',
            'contact' => 'Hervé Naulet',
            'origin' => JobApplicationOrigin::Offer,
            'sent' => ['-30 days', '-20 days', '-7 days'],
            'delivered' => true,
            'reply' => null,
            'replyAttachments' => [],
        ],
        [
            'enterprise' => 'Orange — agence Sud',
            'domain' => null,
            'position' => 'Alternance réseaux',
            'contact' => null,
            'origin' => JobApplicationOrigin::Spontaneous,
            'sent' => ['-9 days'],
            'delivered' => true,
            'reply' => '-2 days',
            'replyAttachments' => [],
        ],
        [
            'enterprise' => 'Groupe Alpha Services',
            'domain' => 'alpha-services.fr',
            'position' => null,
            'contact' => null,
            'origin' => JobApplicationOrigin::Manual,
            'sent' => ['-3 days'],
            'delivered' => false,
            'reply' => null,
            'replyAttachments' => [],
        ],
        [
            'enterprise' => 'Bureau Vallée Limoges',
            'domain' => 'bureau-vallee.fr',
            'position' => 'Stage vente',
            'contact' => null,
            'origin' => JobApplicationOrigin::Spontaneous,
            'sent' => ['-25 days'],
            'delivered' => false,
            'failed' => true,
            'reply' => null,
            'replyAttachments' => [],
        ],
        [
            'enterprise' => 'Mairie de Limoges — DSI',
            'domain' => 'limoges.fr',
            'position' => 'Stage développement',
            'contact' => null,
            'origin' => JobApplicationOrigin::Manual,
            'sent' => [],
            'delivered' => false,
            'reply' => null,
            'replyAttachments' => [],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('username', null, InputOption::VALUE_REQUIRED, "Login de l'élève", 'info3-001')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Supprime les démarches existantes de cet élève avant de semer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getOption('username');
        $student = $this->userRepository->findOneBy(['username' => $username]);

        if (null === $student) {
            $io->error(sprintf('Élève « %s » introuvable.', $username));

            return Command::FAILURE;
        }

        if ($input->getOption('purge')) {
            $this->purge($student->getId());
        }

        $localPart = strtolower(str_replace(' ', '.', $username));
        $mailbox = $localPart.'@devetu.beaupeyrat.org';

        foreach (self::APPLICATIONS as $index => $spec) {
            $enterprise = $this->entityManager->getRepository(Enterprise::class)
                ->findOneBy(['name' => $spec['enterprise']]);

            if (null === $enterprise) {
                $enterprise = (new Enterprise($spec['enterprise']))
                    ->setCity('Limoges')
                    ->setCreatedBy($student);

                if (null !== $spec['domain']) {
                    $enterprise->setEmailDomain($spec['domain']);
                }

                $this->entityManager->persist($enterprise);
            }

            $application = (new JobApplication())
                ->setStudent($student)
                ->setEnterprise($enterprise)
                ->setPosition($spec['position'])
                ->setContactName($spec['contact'])
                ->setOrigin($spec['origin']);

            $this->entityManager->persist($application);

            $contactAddress = 'recrutement@'.($spec['domain'] ?? 'exemple.fr');
            $lastSentMessageId = null;

            foreach ($spec['sent'] as $offset => $when) {
                $date = new \DateTimeImmutable($when);
                $messageId = sprintf('<seed-%d-%d-%d@devetu.beaupeyrat.org>', $index, $offset, $student->getId());

                $message = (new EmailMessage())
                    ->setDirection(EmailDirection::Outbound)
                    ->setStudent($student)
                    ->setRecipientLocalPart($localPart)
                    ->setFromAddress($mailbox)
                    ->setToAddresses([$contactAddress])
                    ->setSubject(0 === $offset ? 'Candidature — '.($spec['position'] ?? 'stage') : 'Relance — candidature')
                    ->setTextBody('Bonjour, …')
                    ->setS3Key(sprintf('applications/%s/mails/seed-%d-%d.eml', $username, $index, $offset))
                    ->setMessageId($messageId)
                    ->setMessageDate($date)
                    ->setJobApplication($application);

                if (!empty($spec['failed'])) {
                    $message->setDeliveryStatus(EmailDeliveryStatus::Bounced);
                } elseif ($spec['delivered']) {
                    $message->setDeliveryStatus(EmailDeliveryStatus::Delivered);
                } else {
                    $message->setDeliveryStatus(EmailDeliveryStatus::Sent);
                }

                $this->entityManager->persist($message);
                $lastSentMessageId = $messageId;
            }

            if (null !== $spec['reply']) {
                $reply = (new EmailMessage())
                    ->setDirection(EmailDirection::Inbound)
                    ->setStudent($student)
                    ->setRecipientLocalPart($localPart)
                    ->setFromAddress($contactAddress)
                    ->setFromName($spec['contact'] ?? $spec['enterprise'])
                    ->setToAddresses([$mailbox])
                    ->setSubject('RE: Candidature')
                    ->setTextBody('Bonjour, …')
                    ->setS3Key(sprintf('applications/%s/mails/seed-%d-reply.eml', $username, $index))
                    ->setMessageId(sprintf('<seed-%d-reply@exemple.fr>', $index))
                    ->setInReplyTo($lastSentMessageId)
                    ->setMessageDate(new \DateTimeImmutable($spec['reply']))
                    ->setJobApplication($application);

                foreach ($spec['replyAttachments'] as $position => $filename) {
                    $attachment = (new EmailAttachment())
                        ->setFilename($filename)
                        ->setS3Key(sprintf('applications/%s/mails/seed-%d-att-%d', $username, $index, $position))
                        ->setContentHash(hash('sha256', $filename))
                        ->setSizeBytes(120_000)
                        ->setContentType('application/pdf');

                    $reply->addAttachment($attachment);
                }

                $this->entityManager->persist($reply);
            }
        }

        $this->entityManager->flush();
        $io->success(sprintf('%d démarches semées pour %s.', \count(self::APPLICATIONS), $username));

        return Command::SUCCESS;
    }

    private function purge(int $studentId): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'DELETE ea FROM email_attachment ea
             JOIN email_message em ON em.id = ea.email_message_id
             JOIN job_application ja ON ja.id = em.job_application_id
             WHERE ja.student_id = :student',
            ['student' => $studentId]
        );
        $connection->executeStatement(
            'DELETE em FROM email_message em
             JOIN job_application ja ON ja.id = em.job_application_id
             WHERE ja.student_id = :student',
            ['student' => $studentId]
        );
        $connection->executeStatement('DELETE FROM job_application WHERE student_id = :student', ['student' => $studentId]);
    }
}
