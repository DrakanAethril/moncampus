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
 * OUTIL DE DÉVELOPPEMENT - peuple une base de dev avec le calendrier des périodes 2026-2027 lu
 * dans design/sources/EDT/2026_06_23_-_Calendrier_2026-2027.xls. N'a pas vocation à tourner en
 * production : les dates sont figées dans le fichier ci-dessous plutôt que relues du classeur,
 * dont le format (une couleur de cellule par formation et par jour) n'est pas exploitable
 * directement.
 *
 * Origine des dates : la couleur de présence de chaque formation, jour par jour, recoupée avec les
 * annotations du classeur qui nomment les stages (« Stages CG2 (4 sem) / SIO2 (7 sem) », « Stage
 * des CG1 (6 semaines) », « Stages des SIO1 (5 semaines) », « Stages MCO (4 - 5 sem) »).
 *
 * Le classeur ne connaît que « présent / absent » : les fins d'année de CG2, DCG et SIO2 (épreuves
 * du BTS et du DCG) sont des absences qui ne sont PAS des stages, elles restent donc en École.
 */
#[AsCommand(
    name: 'app:import-edt-periods',
    description: '[dev] Crée les PeriodGroup/Period du calendrier 2026-2027 et les rattache aux formations.',
)]
class ImportEdtPeriodsCommand extends Command
{
    /** Le classeur couvre septembre → juin ; on prolonge jusqu'à la fin de l'année scolaire. */
    private const string SPAN_START = '2026-09-01';
    private const string SPAN_END = '2027-07-12';

    /**
     * Périodes hors établissement, par nom court de formation. Tout ce qui n'est pas listé ici est
     * du temps école : les périodes produites forment une partition continue de l'année, comme le
     * groupe « Calendrier Bac+3 Info » saisi à la main, puisque c'est ce qu'attend la résolution
     * date → période (App\Service\InternshipCalendarBuilder::findPeriodForDate()).
     *
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     */
    private const array ENTERPRISE_BLOCKS = [
        'SIO1' => [['2027-05-24', '2027-06-25', 'Stage (5 semaines)']],
        'SIO2' => [['2027-01-04', '2027-02-16', 'Stage (7 semaines)']],
        'CG1' => [['2027-05-18', '2027-06-25', 'Stage (6 semaines)']],
        'CG2' => [['2027-01-04', '2027-01-29', 'Stage (4 semaines)']],
        // Le classeur annonce 4 à 5 semaines pour un bloc qui ne couvre que 2,5 semaines ouvrées :
        // le stage court vraisemblablement sur les vacances de Noël, d'où la borne au 1er janvier.
        'MCO1' => [['2026-12-02', '2027-01-01', 'Stage (4 à 5 semaines)']],
        'MCO2' => [['2026-12-02', '2027-01-01', 'Stage (4 à 5 semaines)']],
        // Alternance par blocs, sans stage nommé : les couleurs donnent trois blocs de cours
        // (07/09 → 09/10, 04/01 → 12/02, 01/06 → 25/06), le reste est en entreprise.
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

            // Le Bac+3 a déjà son « Calendrier Bac+3 Info 2026-2027 » saisi à la main, qui pilote
            // le livret d'alternance : le calendrier EDT passe derrière lui en priorité, pour ne
            // rien changer à la résolution date → période existante.
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
     * Complète les blocs entreprise par les blocs école qui les entourent, de façon à couvrir
     * l'année sans trou ni chevauchement.
     *
     * @param list<array{0: string, 1: string, 2: string}> $blocks
     *
     * @return list<array{0: string, 1: string, 2: string, 3: bool}> libellé, début, fin, entreprise ?
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
