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
 * DEVELOPMENT TOOL - tops every program of a dev database up to ten fictitious students
 * (ETU001 / SIO1-001, the numbering restarting at 001 for each program).
 *
 * The accounts are created on both sides, without which they would be unusable: an entry in the dev
 * directory (that is what carries the password, the application stores none - see
 * App\Security\LdapAuthenticator) and the matching App\Entity\User row, with the same roles a real
 * login would infer from the LDAP groups.
 *
 * Nothing is written to ldap_manage_*: these accounts must not go up to the real directory.
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
     * Short code used in the surname and the username. It sticks to the program's short name, except
     * for the Bac+3 Info, whose short name carries a space and a « + ».
     *
     * @var array<string, string>
     */
    private const array CODES = [
        'SIO1' => 'SIO1', 'SIO2' => 'SIO2', 'CG1' => 'CG1', 'CG2' => 'CG2',
        'MCO1' => 'MCO1', 'MCO2' => 'MCO2', 'DCG' => 'DCG', 'Bac+3 Info' => 'INFO3',
    ];

    /** Track and class, as application roles and as directory groups. */
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
     * Options drawn for each student, per program.
     *
     * - « required »: one and only one of the options listed (the half-groups of BTS SIO first year,
     *   the specialty of BTS SIO second year).
     * - « optional »: at most one, every other student having none.
     *
     * The short name is that of the Options in the database. For SIO1 these are « Groupe 1 » /
     * « Groupe 2 » and not A / B: those are the half-groups its timetable carries, groups A and B
     * belonging to BTS CG first year and to the DCG.
     *
     * @var array<string, array{required?: list<string>, optional?: list<string>}>
     */
    private const array STUDENT_OPTIONS = [
        'SIO1' => ['required' => ['GR1', 'GR2'], 'optional' => ['CERTIF', 'BILING']],
        'SIO2' => ['required' => ['SLAM', 'SISR']],
    ];

    /** Track option missing from the database: it only existed as a matière. */
    private const array NEW_OPTIONS = [
        'CERTIF' => ['Parcours de certification', '#f6ad55', ['SIO1', 'SIO2']],
    ];

    /** Directory groups matching an option, where one exists. */
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
        // The same roles App\Security\LdapAuthenticator would infer from the groups above.
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
     * Every other student carries an optional option, alternating the values: enough to have the
     * three cases (none, one, the other) in each cohort without a random draw, which would make the
     * command non-reproducible.
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
            // Already present: the command is replayable, only the memberships are set again.
        }

        foreach (array_unique($groups) as $group) {
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
