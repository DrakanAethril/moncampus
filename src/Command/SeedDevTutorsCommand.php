<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enterprise;
use App\Entity\User;
use App\Repository\EnterpriseRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Ldap\Adapter\ExtLdap\EntryManager as ExtLdapEntryManager;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ExceptionInterface as LdapException;
use Symfony\Component\Ldap\LdapInterface;

/**
 * DEVELOPMENT TOOL - creates five fictitious apprenticeship tutors per program, each with their own
 * company, on the same model as App\Command\SeedDevStudentsCommand.
 *
 * No alternation is created: App\Entity\InternshipTutorLink is precisely what ties a tutor, their
 * company, a student and a program together. As long as it does not exist, « five tutors per
 * program » only lives in their naming - that is deliberate, the pairing will come later.
 *
 * As for students, the account is created on both sides (dev directory for the password, database
 * for the application) and nothing is written to ldap_manage_*.
 */
#[AsCommand(
    name: 'app:seed-dev-tutors',
    description: '[dev] Crée cinq tuteurs fictifs et leurs entreprises par formation.',
)]
class SeedDevTutorsCommand extends Command
{
    private const int PER_PROGRAM = 5;
    private const string PASSWORD = 'P@ssword123!';

    /** Same code table as the students, so the usernames read alike. */
    private const array CODES = [
        'SIO1' => 'SIO1', 'SIO2' => 'SIO2', 'CG1' => 'CG1', 'CG2' => 'CG2',
        'MCO1' => 'MCO1', 'MCO2' => 'MCO2', 'DCG' => 'DCG', 'Bac+3 Info' => 'INFO3',
    ];

    /**
     * A tutor belongs neither to the campus nor to a track: they are an outside participant, whose
     * access is limited to the tutor portal. The two tutors already in the database carry, likewise,
     * only that single role.
     */
    private const array GROUPS = ['tutor'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgramRepository $programRepository,
        private readonly UserRepository $userRepository,
        private readonly EnterpriseRepository $enterpriseRepository,
        private readonly LdapInterface $ldap,
        #[Autowire(env: 'LDAP_BASE_DN')] private readonly string $ldapBaseDn,
        #[Autowire(env: 'LDAP_ADMIN_PASSWORD')] private readonly string $ldapAdminPassword,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('per-program', null, InputOption::VALUE_REQUIRED, 'Nombre de tuteurs par formation', self::PER_PROGRAM)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait créé sans rien enregistrer')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $perProgram = max(1, (int) $input->getOption('per-program'));
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $this->ldap->bind('cn=admin,'.$this->ldapBaseDn, $this->ldapAdminPassword);
        } catch (LdapException $exception) {
            $io->error(\sprintf("Connexion à l'annuaire de dev impossible : %s", $exception->getMessage()));

            return Command::FAILURE;
        }

        // Enterprise carries AuditableTrait: its created_by_id column does not accept an empty value.
        $author = $this->userRepository->findOneBy(['username' => 'stharaud']);
        if (!$author instanceof User) {
            $io->error('Auteur « stharaud » introuvable.');

            return Command::FAILURE;
        }

        $rows = [];
        $tutors = 0;
        $enterprises = 0;

        foreach ($this->programRepository->findBy(['inactiveDate' => null], ['id' => 'ASC']) as $program) {
            $code = self::CODES[$program->getShortName()] ?? null;
            if (null === $code) {
                continue;
            }

            $created = 0;
            for ($index = 1; $index <= $perProgram; ++$index) {
                $number = \sprintf('%03d', $index);
                $lastname = $code.'-TUT-'.$number;
                $username = strtolower($lastname);

                if (null !== $this->userRepository->findOneBy(['username' => $username])) {
                    continue;
                }
                ++$created;
                if ($dryRun) {
                    continue;
                }

                $this->createLdapEntry($username, 'TUT'.$number, $lastname);

                $tutor = new User($username);
                $tutor->setFirstname('TUT'.$number);
                $tutor->setLastname($lastname);
                $tutor->setEmail($username.'@beaupeyrat.lan');
                $tutor->setRoles(['ROLE_TUTOR']);
                $this->entityManager->persist($tutor);

                $enterpriseName = \sprintf('Entreprise %s %s', $code, $number);
                if (null === $this->enterpriseRepository->findOneBy(['name' => $enterpriseName])) {
                    $enterprise = new Enterprise($enterpriseName, \sprintf('%d rue de la Formation, 87000 Limoges', $index));
                    $enterprise->setSiret(\sprintf('%014d', 10000000000000 + crc32($enterpriseName) % 89999999999999));
                    $enterprise->setPhone(\sprintf('05 55 00 %02d %02d', $index, $index));
                    $enterprise->setCreatedBy($author);
                    $this->entityManager->persist($enterprise);
                    ++$enterprises;
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }

            $tutors += $created;
            $rows[] = [
                $program->getShortName(),
                (string) $created,
                \sprintf('%s-tut-001 … %s-tut-%03d', strtolower($code), strtolower($code), $perProgram),
                \sprintf('Entreprise %s 001 … %03d', $code, $perProgram),
            ];
        }

        $io->table(['Formation', 'Tuteurs créés', 'Identifiants', 'Entreprises'], $rows);

        $message = \sprintf('%d tuteur(s) et %d entreprise(s), mot de passe « %s ».', $tutors, $enterprises, self::PASSWORD);
        $dryRun ? $io->note($message." (essai à blanc : rien n'a été enregistré)") : $io->success($message);

        return Command::SUCCESS;
    }

    private function createLdapEntry(string $username, string $firstname, string $lastname): void
    {
        $dn = \sprintf('uid=%s,ou=users,%s', $username, $this->ldapBaseDn);
        $manager = $this->ldap->getEntryManager();
        // addAttributeValues() is declared on the ext-ldap adapter's own EntryManager, not on
        // EntryManagerInterface - and ext-ldap is the only adapter this app ever binds through.
        \assert($manager instanceof ExtLdapEntryManager);

        try {
            $manager->add(new Entry($dn, [
                'objectClass' => ['inetOrgPerson'],
                'cn' => [$firstname.' '.$lastname],
                'sn' => [$lastname],
                'givenName' => [$firstname],
                'uid' => [$username],
                'mail' => [$username.'@beaupeyrat.lan'],
                'userPassword' => [self::PASSWORD],
            ]));
        } catch (LdapException) {
            // Already present: the command is replayable.
        }

        foreach (self::GROUPS as $group) {
            try {
                $manager->addAttributeValues(
                    new Entry(\sprintf('cn=%s,ou=groups,%s', $group, $this->ldapBaseDn)),
                    'member',
                    [$dn],
                );
            } catch (LdapException) {
                // Already a member.
            }
        }
    }
}
