<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramStudentOption;
use App\Entity\User;
use App\Repository\OptionRepository;
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
 * OUTIL DE DÉVELOPPEMENT - complète chaque formation d'une base de dev jusqu'à dix étudiants
 * fictifs (ETU001 / SIO1-001, numérotation repartant de 001 à chaque formation).
 *
 * Les comptes sont créés des deux côtés, sans quoi ils seraient inutilisables : une entrée dans
 * l'annuaire de dev (c'est lui qui porte le mot de passe, l'application n'en stocke aucun - voir
 * App\Security\LdapAuthenticator) et la ligne App\Entity\User correspondante, avec les mêmes rôles
 * que ceux qu'une vraie connexion déduirait des groupes LDAP.
 *
 * Rien n'est écrit dans ldap_manage_* : ces comptes ne doivent pas remonter vers l'annuaire réel.
 */
#[AsCommand(
    name: 'app:seed-dev-students',
    description: '[dev] Complète les formations à dix étudiants fictifs (annuaire de dev + base).',
)]
class SeedDevStudentsCommand extends Command
{
    private const int TARGET = 10;
    private const string PASSWORD = 'P@ssword123!';

    /**
     * Code court utilisé dans le nom de famille et l'identifiant. Il colle au nom court de la
     * formation, sauf pour le Bac+3 Info dont le nom court porte espace et « + ».
     *
     * @var array<string, string>
     */
    private const array CODES = [
        'SIO1' => 'SIO1', 'SIO2' => 'SIO2', 'CG1' => 'CG1', 'CG2' => 'CG2',
        'MCO1' => 'MCO1', 'MCO2' => 'MCO2', 'DCG' => 'DCG', 'Bac+3 Info' => 'INFO3',
    ];

    /** Filière et classe, en rôles applicatifs et en groupes de l'annuaire. */
    private const array PROGRAM_GROUPS = [
        'SIO1' => ['sio', 'sio-1'],
        'SIO2' => ['sio', 'sio-2'],
        'CG1' => ['cg', 'cg-1'],
        'CG2' => ['cg', 'cg-2'],
        'MCO1' => ['mco', 'mco-1'],
        'MCO2' => ['mco', 'mco-2'],
        'DCG' => ['dcg'],
        'Bac+3 Info' => ['info-3'],
    ];

    /**
     * Options tirées au sort pour chaque étudiant, par formation.
     *
     * - « required » : une et une seule des options citées (les demi-groupes du BTS SIO 1re année,
     *   la spécialité du BTS SIO 2e année).
     * - « optional » : au plus une, un étudiant sur deux n'en a aucune.
     *
     * Le nom court est celui des Option en base. Pour SIO1 ce sont « Groupe 1 » / « Groupe 2 » et
     * non A / B : ce sont les demi-groupes que porte son emploi du temps, les groupes A et B
     * appartenant au BTS CG 1re année et au DCG.
     *
     * @var array<string, array{required?: list<string>, optional?: list<string>}>
     */
    private const array STUDENT_OPTIONS = [
        'SIO1' => ['required' => ['GR1', 'GR2'], 'optional' => ['CERTIF', 'BILING']],
        'SIO2' => ['required' => ['SLAM', 'SISR']],
    ];

    /** Option de parcours absente de la base : elle n'existait qu'en tant que matière. */
    private const array NEW_OPTIONS = [
        'CERTIF' => ['Parcours de certification', '#f6ad55', ['SIO1', 'SIO2']],
    ];

    /** Groupes de l'annuaire correspondant à une option, quand il en existe un. */
    private const array OPTION_GROUPS = ['SISR' => 'sio-sisr', 'SLAM' => 'sio-slam'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgramRepository $programRepository,
        private readonly UserRepository $userRepository,
        private readonly OptionRepository $optionRepository,
        private readonly LdapInterface $ldap,
        #[Autowire(env: 'LDAP_BASE_DN')] private readonly string $ldapBaseDn,
        #[Autowire(env: 'LDAP_ADMIN_PASSWORD')] private readonly string $ldapAdminPassword,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('target', null, InputOption::VALUE_REQUIRED, "Nombre d'étudiants visé par formation", self::TARGET)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait créé sans rien enregistrer')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = max(1, (int) $input->getOption('target'));
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $this->ldap->bind('cn=admin,'.$this->ldapBaseDn, $this->ldapAdminPassword);
        } catch (LdapException $exception) {
            $io->error(\sprintf("Connexion à l'annuaire de dev impossible : %s", $exception->getMessage()));

            return Command::FAILURE;
        }

        $options = $this->resolveOptions($dryRun);
        $rows = [];
        $created = 0;

        foreach ($this->programRepository->findBy(['inactiveDate' => null], ['id' => 'ASC']) as $program) {
            $code = self::CODES[$program->getShortName()] ?? null;
            if (null === $code) {
                continue;
            }

            $existing = $program->getStudents()->count();
            $missing = max(0, $target - $existing);
            $rows[] = [$program->getShortName(), (string) $existing, (string) $missing, $code.'-001…'.\sprintf('%03d', $missing)];
            if (0 === $missing) {
                continue;
            }

            for ($index = 1; $index <= $missing; ++$index) {
                $student = $this->createStudent($program, $code, $index, $options, $dryRun);
                if (null !== $student) {
                    ++$created;
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }
        }

        $io->table(['Formation', 'Étudiants', 'À créer', 'Numérotation'], $rows);

        $message = \sprintf('%d étudiant(s) créé(s), mot de passe « %s ».', $created, self::PASSWORD);
        $dryRun ? $io->note($message." (essai à blanc : rien n'a été enregistré)") : $io->success($message);

        return Command::SUCCESS;
    }

    /** @param array<string, Option> $options */
    private function createStudent(Program $program, string $code, int $index, array $options, bool $dryRun): ?User
    {
        $number = \sprintf('%03d', $index);
        $firstname = 'ETU'.$number;
        $lastname = $code.'-'.$number;
        $username = strtolower($lastname);

        if (null !== $this->userRepository->findOneBy(['username' => $username])) {
            return null;
        }

        $picked = $this->pickOptions($program->getShortName(), $index, $options);
        $groups = ['student', 'campus', ...self::PROGRAM_GROUPS[$program->getShortName()] ?? []];
        foreach ($picked as $option) {
            if (isset(self::OPTION_GROUPS[$option->getShortName()])) {
                $groups[] = self::OPTION_GROUPS[$option->getShortName()];
            }
        }

        if ($dryRun) {
            return new User($username);
        }

        $this->createLdapEntry($username, $firstname, $lastname, $groups);

        $student = new User($username);
        $student->setFirstname($firstname);
        $student->setLastname($lastname);
        $student->setEmail($username.'@beaupeyrat.lan');
        // Mêmes rôles que ceux qu'App\Security\LdapAuthenticator déduirait des groupes ci-dessus.
        $student->setRoles(array_map(
            static fn (string $group): string => 'ROLE_'.strtoupper($group),
            $groups,
        ));
        $this->entityManager->persist($student);

        $program->addStudent($student);
        foreach ($picked as $option) {
            $this->entityManager->persist(new ProgramStudentOption($program, $student, $option));
        }

        return $student;
    }

    /**
     * Un étudiant sur deux porte une option facultative, en alternant les valeurs : de quoi avoir
     * les trois cas (aucune, l'une, l'autre) dans chaque promotion sans tirage aléatoire, qui
     * rendrait la commande non reproductible.
     *
     * @param array<string, Option> $options
     *
     * @return list<Option>
     */
    private function pickOptions(string $shortName, int $index, array $options): array
    {
        $rules = self::STUDENT_OPTIONS[$shortName] ?? [];
        $picked = [];

        $required = $rules['required'] ?? [];
        if ([] !== $required) {
            $picked[] = $options[$required[($index - 1) % \count($required)]] ?? null;
        }

        $optional = $rules['optional'] ?? [];
        if ([] !== $optional && 0 === $index % 2) {
            $picked[] = $options[$optional[intdiv($index, 2) % \count($optional)]] ?? null;
        }

        return array_values(array_filter($picked));
    }

    /** @param list<string> $groups */
    private function createLdapEntry(string $username, string $firstname, string $lastname, array $groups): void
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
            // Déjà présente : la commande est rejouable, on remet seulement les appartenances.
        }

        foreach (array_unique($groups) as $group) {
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

    /** @return array<string, Option> */
    private function resolveOptions(bool $dryRun): array
    {
        $options = [];
        foreach ($this->optionRepository->findAll() as $option) {
            $options[$option->getShortName()] = $option;
        }

        foreach (self::NEW_OPTIONS as $shortName => [$name, $color, $programs]) {
            if (isset($options[$shortName])) {
                continue;
            }
            $option = new Option($name, $shortName, $color);
            $option->setCreatedBy($this->userRepository->findOneBy(['username' => 'stharaud']));
            foreach ($programs as $programShortName) {
                $program = $this->programRepository->findOneBy(['shortName' => $programShortName]);
                if ($program instanceof Program) {
                    $option->addProgram($program);
                }
            }
            $this->entityManager->persist($option);
            $options[$shortName] = $option;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $options;
    }
}
