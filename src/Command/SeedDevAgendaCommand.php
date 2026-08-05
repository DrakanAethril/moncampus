<?php

namespace App\Command;

use App\Entity\AgendaEvent;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Repository\AgendaEventRepository;
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

/**
 * OUTIL DE DÉVELOPPEMENT - transforme les annotations du calendrier annuel
 * (design/sources/EDT/2026_06_23_-_Calendrier_2026-2027.xls, extraites vers agenda-notes.json) en
 * événements d'agenda.
 *
 * Le classeur écrit un texte libre par jour : « BTS Blanc MCO / DCG Blanc », « 17h15 - CC SIO1 »,
 * « JPO (9 - 15h) ». Trois traitements en découlent, tous faits ici plutôt qu'à l'extraction, pour
 * que les règles restent lisibles et modifiables au même endroit :
 *
 * 1. une cellule peut porter plusieurs événements, séparés par « / » ;
 * 2. l'horaire est dans le libellé quand il existe, sinon l'événement occupe la journée ;
 * 3. le public se déduit du libellé - « Conseil de classe SIO2 » ne concerne que SIO2 et ses
 *    enseignants, « JPO » concerne tout le monde.
 *
 * Les jours consécutifs portant le même titre sont fusionnés en un seul événement (les épreuves
 * orales du 25/05 au 04/06 font un événement, pas neuf).
 */
#[AsCommand(
    name: 'app:seed-dev-agenda',
    description: "[dev] Crée les événements d'agenda à partir des annotations du calendrier annuel.",
)]
class SeedDevAgendaCommand extends Command
{
    private const string NOTES_FILE = 'design/sources/EDT/agenda-notes.json';

    /**
     * Annotations qui ne sont pas des événements : elles disent qui est présent (déjà porté par
     * l'emploi du temps) ou quand commence un stage (déjà porté par les périodes).
     */
    private const array SKIPPED = ['Cours MCO', 'Stages', 'Stage des', 'Stage MCO'];

    /**
     * Ciblage par mot-clé, dans l'ordre : la première entrée trouvée dans le libellé gagne.
     * Chaque règle dit les formations visées et si l'événement s'adresse aux étudiants, aux
     * enseignants, ou aux deux.
     *
     * @var list<array{0: string, 1: list<string>, 2: bool, 3: bool}> motif, formations, étudiants, enseignants
     */
    private const array TARGETS = [
        // Conseils et pré-conseils : affaire d'enseignants, pas d'étudiants.
        ['Pré-conseils BTS CG', ['CG1', 'CG2'], false, true],
        ['Pré-conseils BTS SIO', ['SIO1', 'SIO2'], false, true],
        ['Pré-conseil BTS MCO', ['MCO1', 'MCO2'], false, true],
        ['Pré-conseil MCO1', ['MCO1'], false, true],
        ['Conseil de classe SIO2', ['SIO2'], false, true],
        ['Conseil de classe CG1', ['CG1'], false, true],
        ['Conseil de classe CG2', ['CG2'], false, true],
        ['Conseil de classe DCG', ['DCG'], false, true],
        ['Conseil de classe MCO', ['MCO1', 'MCO2'], false, true],
        ['CC BTS CG1', ['CG1'], false, true],
        ['CC BTS CG2', ['CG2'], false, true],
        ['CC BTS SIO1', ['SIO1'], false, true],
        ['CC BTS SIO2', ['SIO2'], false, true],
        ['CC SIO1', ['SIO1'], false, true],
        ['CC MCO1', ['MCO1'], false, true],
        ['CC DCG', ['DCG'], false, true],

        // Évaluations blanches et examens : les étudiants concernés et leurs enseignants.
        ['DCG Blanc', ['DCG'], true, true],
        ['DCG blanc', ['DCG'], true, true],
        ['BTS Blanc MCO', ['MCO1', 'MCO2'], true, true],
        ['BTS blanc CG/SIO', ['CG1', 'CG2', 'SIO1', 'SIO2'], true, true],
        ['BTS Blanc CG/SIO', ['CG1', 'CG2', 'SIO1', 'SIO2'], true, true],
        ['Examens Bachelor Info', ['Bac+3 Info'], true, true],
        ['Oraux de stage CG1/SIO1', ['CG1', 'SIO1'], true, true],
        ['Epreuves écrites', ['CG2', 'SIO2', 'MCO2'], true, true],
        ['Epreuves orales', ['CG2', 'SIO2', 'MCO2'], true, true],

        // Temps forts d'une seule promotion.
        ['Séminaire rentrée MCO', ['MCO1', 'MCO2'], true, true],
        ['Rentrée Bachelor info', ['Bac+3 Info'], true, true],
        ['Business Game', ['MCO1', 'MCO2'], true, true],
    ];


    /**
     * Cellules qui portent plusieurs événements sans séparateur, ou qui mêlent un événement et une
     * annotation de stage : le classeur les a écrites sur plusieurs lignes d'une même case, et
     * l'extraction les a recollées. Réécrites ici avec le « / » qui manque.
     */
    private const array REWRITES = [
        '9h - DCG 13h30 - BTS 1ère année 14h30 - BTS 2ème année' => '9h - Rentrée DCG / 13h30 - Rentrée BTS 1ère année / 14h30 - Rentrée BTS 2ème année',
        '9h - Rentrée Bachelor info 18h : Réunion parents 1ère année (visio)' => '9h - Rentrée Bachelor info / 18h - Réunion parents 1ère année (visio)',
        'Stages CG2 (4 sem) / SIO2 ( 7 sem) DCG Blanc' => 'DCG Blanc',
        'Epreuves écritesBTS ? Stage des CG1 (6 semaines)' => 'Epreuves écrites BTS',
        'Stages des SIO1 (5 semaines) CC BTS CG2' => 'CC BTS CG2',
        'Stages MCO (4 - 5 sem)' => '',
        'Stage MCO 1 (4 semaines)' => '',
        'Conseil de classe CG1 DCG Blanc' => 'Conseil de classe CG1 / DCG Blanc',
        'Epreuves orales CC BTS SIO2' => 'Epreuves orales / CC BTS SIO2',
        "Apéro fin d'année ? CC DCG" => "Apéro fin d'année / CC DCG",
        '17h : marché de Noël' => '17h - Marché de Noël',
        '14: Visio tuteurs Campus' => '14h - Visio tuteurs Campus',
    ];

    /** Horaires connus qui ne se lisent pas dans le libellé, pour éviter des journées entières. */
    private const array KNOWN_HOURS = [
        'JPO (9 - 15h)' => ['09:00', '15:00', 'Journée portes ouvertes'],
        'JPO 9h-13h' => ['09:00', '13:00', 'Journée portes ouvertes'],
        'Soirée JPO (17-19h)' => ['17:00', '19:00', 'Soirée portes ouvertes'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgramRepository $programRepository,
        private readonly UserRepository $userRepository,
        private readonly AgendaEventRepository $agendaEventRepository,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('author', null, InputOption::VALUE_REQUIRED, 'Auteur des événements créés', 'stharaud')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Supprime les événements déjà importés avant de recréer')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les événements sans les enregistrer')
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

        $file = $this->projectDir.'/'.self::NOTES_FILE;
        if (!is_readable($file)) {
            $io->error(\sprintf('Annotations introuvables : %s', $file));

            return Command::FAILURE;
        }

        $programs = [];
        foreach ($this->programRepository->findBy(['inactiveDate' => null]) as $program) {
            $programs[$program->getShortName()] = $program;
        }

        $notes = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        $entries = $this->splitEntries($notes);
        $events = $this->mergeConsecutiveDays($entries);

        $dryRun = (bool) $input->getOption('dry-run');
        if ($input->getOption('replace') && !$dryRun) {
            $deleted = 0;
            foreach ($this->agendaEventRepository->findAll() as $existing) {
                if (str_starts_with((string) $existing->getDescription(), self::DESCRIPTION)) {
                    $this->entityManager->remove($existing);
                    ++$deleted;
                }
            }
            $this->entityManager->flush();
            $io->note(\sprintf('%d événement(s) supprimé(s) avant regénération.', $deleted));
        }

        $rows = [];
        foreach ($events as $event) {
            [$targetPrograms, $students, $teachers] = $this->resolveTarget($event['titre'], $programs);

            $agendaEvent = new AgendaEvent();
            $agendaEvent->setTitle($event['titre']);
            $agendaEvent->setDescription(self::DESCRIPTION);
            $agendaEvent->setStartAt(new \DateTimeImmutable($event['debut']));
            $agendaEvent->setEndAt(new \DateTimeImmutable($event['fin']));
            $agendaEvent->setIncludeStudents($students);
            $agendaEvent->setIncludeTeachers($teachers);
            $agendaEvent->setCreatedBy($author);

            // Aucun public « tout le monde » n'existe : AllStaff ne vise que l'administration
            // (App\Service\AudienceResolver::STAFF_ROLES). Un événement de campus est donc ciblé
            // sur toutes les formations, étudiants et enseignants compris.
            $agendaEvent->setAudienceType(MessageAudienceType::Program);
            foreach ([] === $targetPrograms ? array_values($programs) : $targetPrograms as $program) {
                $agendaEvent->addProgram($program);
            }

            if (!$dryRun) {
                $this->entityManager->persist($agendaEvent);
            }

            $rows[] = [
                substr($event['debut'], 0, 10),
                substr($event['fin'], 0, 10) === substr($event['debut'], 0, 10) ? '' : substr($event['fin'], 0, 10),
                $event['titre'],
                [] === $targetPrograms ? 'campus' : implode(', ', array_map(static fn (Program $p): string => $p->getShortName(), $targetPrograms)),
                match (true) { $students && $teachers => 'étudiants + enseignants', $teachers => 'enseignants', default => 'étudiants' },
            ];
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->table(['Début', 'Fin', 'Événement', 'Formations', 'Public'], $rows);

        $message = \sprintf('%d événement(s) depuis %d annotation(s).', \count($events), \count($notes));
        $dryRun ? $io->note($message." (essai à blanc : rien n'a été enregistré)") : $io->success($message);

        return Command::SUCCESS;
    }

    /** Marque les événements issus de cet import, pour pouvoir les reprendre sans toucher au reste. */
    private const string DESCRIPTION = 'Repris du calendrier 2026-2027';

    /**
     * Une cellule → une ou plusieurs entrées datées : découpage sur « / », retrait des annotations
     * qui n'en sont pas, extraction de l'horaire.
     *
     * @param list<array{date: string, note: string}> $notes
     *
     * @return list<array{date: string, titre: string, debut: string, fin: string}>
     */
    private function splitEntries(array $notes): array
    {
        $entries = [];

        foreach ($notes as $note) {
            foreach ($this->splitTitles($note['note']) as $title) {
                $skip = false;
                foreach (self::SKIPPED as $marker) {
                    if (str_starts_with($title, $marker)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip || '' === $title) {
                    continue;
                }

                [$clean, $start, $end] = $this->extractHours($title);
                $entries[] = [
                    'date' => $note['date'],
                    'titre' => $clean,
                    'debut' => $note['date'].' '.$start,
                    'fin' => $note['date'].' '.$end,
                ];
            }
        }

        return $entries;
    }

    /** @return list<string> */
    private function splitTitles(string $note): array
    {
        // « Conseil de classe CG1 DCG Blanc » : deux événements qu'aucun séparateur ne sépare, le
        // classeur les ayant écrits sur deux lignes de la même cellule. Les cas connus sont listés.
        $note = strtr($note, self::REWRITES);

        // Les « / » qui ne séparent rien - une fraction, un intitulé - sont mis à l'abri le temps
        // du découpage.
        $note = str_replace(['CG/SIO', 'CG1/SIO1', '1/2'], ['CG~SIO', 'CG1~SIO1', '1~2'], $note);

        return array_values(array_filter(array_map(
            static fn (string $part): string => trim($part, " \t?"),
            explode('/', $note),
        )));
    }

    /**
     * « 18h - Remise des diplômes », « 12h Conseil de classe DCG », « JPO (9 - 15h) » : l'horaire
     * est dans le texte quand il y est. Sans horaire, l'événement couvre la journée de cours.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function extractHours(string $title): array
    {
        $title = str_replace(['CG~SIO', 'CG1~SIO1', '1~2'], ['CG/SIO', 'CG1/SIO1', '1/2'], $title);

        foreach (self::KNOWN_HOURS as $needle => [$start, $end, $clean]) {
            if (str_contains($title, $needle)) {
                return [$clean, $start, $end];
            }
        }

        if (preg_match('/^(\d{1,2})\s*[h:]\s*(\d{2})?\s*[:\-]?\s*(.+)$/u', $title, $m)) {
            $start = \sprintf('%02d:%02d', (int) $m[1], (int) ($m[2] ?? 0));

            return [trim($m[3]), $start, \sprintf('%02d:%02d', min(23, (int) $m[1] + 1), (int) ($m[2] ?? 0))];
        }

        return [$title, '08:00', '18:00'];
    }

    /**
     * Fusionne les jours consécutifs de même titre : « BTS Blanc MCO » du 4 au 6 novembre fait un
     * événement de trois jours, pas trois événements.
     *
     * @param list<array{date: string, titre: string, debut: string, fin: string}> $entries
     *
     * @return list<array{titre: string, debut: string, fin: string}>
     */
    private function mergeConsecutiveDays(array $entries): array
    {
        usort($entries, static fn (array $a, array $b): int => [$a['titre'], $a['date']] <=> [$b['titre'], $b['date']]);

        $events = [];
        foreach ($entries as $entry) {
            $previous = $events[array_key_last($events) ?? -1] ?? null;
            $gap = null !== $previous
                ? (new \DateTimeImmutable(substr($entry['debut'], 0, 10)))->diff(new \DateTimeImmutable(substr($previous['fin'], 0, 10)))->days
                : null;

            if (null !== $previous && $previous['titre'] === $entry['titre'] && null !== $gap && $gap <= 3) {
                $events[array_key_last($events)]['fin'] = $entry['fin'];
                continue;
            }

            $events[] = ['titre' => $entry['titre'], 'debut' => $entry['debut'], 'fin' => $entry['fin']];
        }

        usort($events, static fn (array $a, array $b): int => $a['debut'] <=> $b['debut']);

        return $events;
    }

    /**
     * @param array<string, Program> $programs
     *
     * @return array{0: list<Program>, 1: bool, 2: bool}
     */
    private function resolveTarget(string $title, array $programs): array
    {
        foreach (self::TARGETS as [$needle, $shortNames, $students, $teachers]) {
            if (str_contains($title, $needle)) {
                return [
                    array_values(array_filter(array_map(static fn (string $s): ?Program => $programs[$s] ?? null, $shortNames))),
                    $students,
                    $teachers,
                ];
            }
        }

        return [[], true, true];
    }
}
