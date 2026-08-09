<?php

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
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ExceptionInterface as LdapException;
use Symfony\Component\Ldap\LdapInterface;

/**
 * OUTIL DE DÉVELOPPEMENT - crée cinq tuteurs d'alternance fictifs par formation, chacun avec son
 * entreprise, sur le même modèle que App\Command\SeedDevStudentsCommand.
 *
 * Aucune alternance n'est créée : App\Entity\InternshipTutorLink est justement ce qui relie un
 * tuteur, son entreprise, un étudiant et une formation. Tant qu'il n'existe pas, « cinq tuteurs
 * par formation » ne vit que dans leur nommage - c'est volontaire, l'appariement viendra ensuite.
 *
 * Comme pour les étudiants, le compte est créé des deux côtés (annuaire de dev pour le mot de
 * passe, base pour l'application) et rien n'est écrit dans ldap_manage_*.
 */
#[AsCommand(
    name: 'app:seed-dev-tutors',
    description: '[dev] Crée cinq tuteurs fictifs et leurs entreprises par formation.',
)]
class SeedDevTutorsCommand extends Command
{
    private const int PER_PROGRAM = 5;
    private const string PASSWORD = 'P@ssword123!';

    /** Même table de codes que les étudiants, pour que les identifiants se lisent pareil. */
    private const array CODES = [
        'SIO1' => 'SIO1', 'SIO2' => 'SIO2', 'CG1' => 'CG1', 'CG2' => 'CG2',
        'MCO1' => 'MCO1', 'MCO2' => 'MCO2', 'DCG' => 'DCG', 'Bac+3 Info' => 'INFO3',
    ];

    /**
     * Un tuteur n'appartient ni au campus ni à une filière : c'est un intervenant extérieur, dont
     * l'accès se limite au portail tuteur. Les deux tuteurs déjà en base ne portent, eux aussi,
     * que ce seul rôle.
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

        // Enterprise porte AuditableTrait : sa colonne created_by_id n'accepte pas le vide.
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
            // Déjà présente : la commande est rejouable.
        }

        foreach (self::GROUPS as $group) {
            try {
                $manager->addAttributeValues(
                    new Entry(\sprintf('cn=%s,ou=groups,%s', $group, $this->ldapBaseDn)),
                    'member',
                    [$dn],
                );
            } catch (LdapException) {
                // Déjà membre.
            }
        }
    }
}
