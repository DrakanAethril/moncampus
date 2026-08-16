<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Period;
use App\Entity\PeriodGroup;
use App\Entity\PeriodType;
use App\Entity\Program;
use App\Entity\ProgramPeriodGroup;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Repository\PeriodGroupRepository;
use App\Repository\PeriodTypeRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * DEVELOPMENT TOOL - populates a dev database with the 2026-2027 period calendar read from
 * design/sources/EDT/2026_06_23_-_Calendrier_2026-2027.xls. Not meant to run in production: the
 * dates are frozen in the file below rather than read back from the workbook, whose format (one
 * cell color per program and per day) cannot be used directly.
 *
 * Where the dates come from: each program's presence color, day by day, cross-checked with the
 * workbook annotations naming the internships (« Stages CG2 (4 sem) / SIO2 (7 sem) », « Stage
 * des CG1 (6 semaines) », « Stages des SIO1 (5 semaines) », « Stages MCO (4 - 5 sem) »).
 *
 * The workbook only knows « present / absent »: the end of year for CG2, DCG and SIO2 (BTS and DCG
 * examinations) are absences that are NOT internships, so they stay as school time.
 */
#[AsCommand(
    name: 'app:import-edt-periods',
    description: '[dev] Crée les PeriodGroup/Period du calendrier 2026-2027 et les rattache aux formations.',
)]
class ImportEdtPeriodsCommand extends Command
{
    /** The workbook covers September → June; we extend to the end of the school year. */
    private const string SPAN_START = '2026-09-01';
    private const string SPAN_END = '2027-07-12';

    /**
     * Periods away from the school, by program short name. Anything not listed here is school time:
     * the periods produced form a continuous partition of the year, like the « Calendrier Bac+3
     * Info » group entered by hand, since that is what the date → period resolution expects
     * (App\Service\InternshipCalendarBuilder::findPeriodForDate()).
     *
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     */
    private const array ENTERPRISE_BLOCKS = [
        'SIO1' => [['2027-05-24', '2027-06-25', 'Stage (5 semaines)']],
        'SIO2' => [['2027-01-04', '2027-02-16', 'Stage (7 semaines)']],
        'CG1' => [['2027-05-18', '2027-06-25', 'Stage (6 semaines)']],
        'CG2' => [['2027-01-04', '2027-01-29', 'Stage (4 semaines)']],
        // The workbook announces 4 to 5 weeks for a block covering only 2.5 working weeks: the
        // internship most likely runs over the Christmas holidays, hence the bound at 1 January.
        'MCO1' => [['2026-12-02', '2027-01-01', 'Stage (4 à 5 semaines)']],
        'MCO2' => [['2026-12-02', '2027-01-01', 'Stage (4 à 5 semaines)']],
        // Block-based alternation, with no named internship: the colors give three teaching blocks
        // (07/09 → 09/10, 04/01 → 12/02, 01/06 → 25/06), the rest is spent in the company.
        'Bac+3 Info' => [
            ['2026-10-12', '2027-01-03', 'Entreprise - automne'],
            ['2027-02-15', '2027-05-31', 'Entreprise - printemps'],
        ],
        'DCG' => [],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgramRepository $programRepository,
        private readonly PeriodGroupRepository $periodGroupRepository,
        private readonly PeriodTypeRepository $periodTypeRepository,
        private readonly SchoolYearRepository $schoolYearRepository,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('author', null, InputOption::VALUE_REQUIRED, "Identifiant de l'auteur porté par les lignes créées", 'stharaud')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Recrée les groupes déjà importés au lieu de les ignorer')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les périodes sans rien enregistrer')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $author = $this->userRepository->findOneBy(['username' => (string) $input->getOption('author')]);
        if (!$author instanceof User) {
            $io->error(\sprintf('Aucun utilisateur « %s ».', $input->getOption('author')));

            return Command::FAILURE;
        }

        $schoolYear = $this->schoolYearRepository->findOneBy(['inactiveDate' => null], ['startDate' => 'DESC']);
        if (!$schoolYear instanceof SchoolYear) {
            $io->error("Aucune année scolaire active : créez-la avant d'importer le calendrier.");

            return Command::FAILURE;
        }

        $school = $this->periodTypeRepository->findOneBy(['name' => 'École']);
        $company = $this->periodTypeRepository->findOneBy(['name' => 'Entreprise']);
        if (!$school instanceof PeriodType || !$company instanceof PeriodType) {
            $io->error('Types de période « École » et « Entreprise » introuvables.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $replace = (bool) $input->getOption('replace');
        $rows = [];

        foreach (self::ENTERPRISE_BLOCKS as $shortName => $blocks) {
            $program = $this->programRepository->findOneBy(['shortName' => $shortName]);
            if (!$program instanceof Program) {
                $io->warning(\sprintf('Formation « %s » absente de la base, ignorée.', $shortName));
                continue;
            }

            $name = \sprintf('EDT %s 2026-2027', $shortName);
            $existing = $this->periodGroupRepository->findOneBy(['name' => $name]);
            if (null !== $existing && !$replace) {
                $rows[] = [$shortName, $name, '—', 'déjà présent, ignoré'];
                continue;
            }
            if (null !== $existing && !$dryRun) {
                $this->removeGroup($existing);
            }

            $group = new PeriodGroup($name, $schoolYear);
            $group->setCreatedBy($author);
            $this->entityManager->persist($group);

            $periods = [];
            foreach ($this->buildPeriods($blocks) as [$label, $start, $end, $isCompany]) {
                $period = new Period(
                    $label,
                    new \DateTimeImmutable($start),
                    new \DateTimeImmutable($end),
                    $isCompany ? $company : $school,
                    $group,
                );
                $period->setCreatedBy($author);
                $this->entityManager->persist($period);
                $periods[] = \sprintf('%s (%s → %s)', $label, $start, $end);
            }

            // The Bac+3 already has its « Calendrier Bac+3 Info 2026-2027 » entered by hand, which
            // drives the livret d'alternance: the EDT calendar comes behind it in priority, so as to
            // change nothing to the existing date → period resolution.
            $priority = 1 + $program->getProgramPeriodGroups()->count();
            $this->entityManager->persist(new ProgramPeriodGroup($program, $group, $priority));

            $rows[] = [$shortName, $name, (string) \count($periods), \sprintf('priorité %d', $priority)];
            $io->writeln(\sprintf('  <info>%s</info> : %s', $shortName, implode(' | ', $periods)));
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->table(['Formation', 'Groupe', 'Périodes', 'Statut'], $rows);
        $message = \sprintf('%d groupe(s) de périodes traité(s).', \count($rows));
        $dryRun ? $io->note($message." (essai à blanc : rien n'a été enregistré)") : $io->success($message);

        return Command::SUCCESS;
    }

    /**
     * Completes the company blocks with the school blocks surrounding them, so as to cover the year
     * with neither gap nor overlap.
     *
     * @param list<array{0: string, 1: string, 2: string}> $blocks
     *
     * @return list<array{0: string, 1: string, 2: string, 3: bool}> label, start, end, in company?
     */
    private function buildPeriods(array $blocks): array
    {
        $periods = [];
        $cursor = self::SPAN_START;
        $index = 0;

        foreach ($blocks as [$start, $end, $label]) {
            if ($cursor < $start) {
                $periods[] = [\sprintf('Cours %d', ++$index), $cursor, $this->dayBefore($start), false];
            }
            $periods[] = [$label, $start, $end, true];
            $cursor = $this->dayAfter($end);
        }

        if ($cursor <= self::SPAN_END) {
            $periods[] = [\sprintf('Cours %d', ++$index), $cursor, self::SPAN_END, false];
        }

        return $periods;
    }

    private function dayBefore(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    }

    private function dayAfter(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }

    private function removeGroup(PeriodGroup $group): void
    {
        foreach ($group->getProgramPeriodGroups() as $link) {
            $this->entityManager->remove($link);
        }
        foreach ($group->getPeriods() as $period) {
            $this->entityManager->remove($period);
        }
        $this->entityManager->remove($group);
        $this->entityManager->flush();
    }
}
