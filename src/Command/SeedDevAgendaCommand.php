<?php

declare(strict_types=1);

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
 * DEVELOPMENT TOOL - turns the annotations of the yearly calendar
 * (design/sources/EDT/2026_06_23_-_Calendrier_2026-2027.xls, extracted into agenda-notes.json) into
 * agenda events.
 *
 * The workbook writes free text per day: « BTS Blanc MCO / DCG Blanc », « 17h15 - CC SIO1 »,
 * « JPO (9 - 15h) ». Three treatments follow, all done here rather than at extraction time, so that
 * the rules stay legible and editable in the same place:
 *
 * 1. a cell may carry several events, separated by « / »;
 * 2. the time is in the label when there is one, otherwise the event takes the whole day;
 * 3. the audience is inferred from the label - « Conseil de classe SIO2 » only concerns SIO2 and
 *    its teachers, « JPO » concerns everyone.
 *
 * Consecutive days carrying the same title are merged into a single event (the oral examinations
 * from 25/05 to 04/06 make one event, not nine).
 */
#[AsCommand(
    name: 'app:seed-dev-agenda',
    description: "[dev] Crée les événements d'agenda à partir des annotations du calendrier annuel.",
)]
class SeedDevAgendaCommand extends Command
{
    private const string NOTES_FILE = 'design/sources/EDT/agenda-notes.json';

    /**
     * Annotations that are not events: they say who is present (already carried by the timetable) or
     * when an internship starts (already carried by the periods).
     */
    private const array SKIPPED = ['Cours MCO', 'Stages', 'Stage des', 'Stage MCO'];

    /**
     * Keyword targeting, in order: the first entry found in the label wins.
     * Each rule says which programs are targeted and whether the event addresses students, teachers,
     * or both.
     *
     * @var list<array{0: string, 1: list<string>, 2: bool, 3: bool}> pattern, programs, students, teachers
     */
    private const array TARGETS = [
        // Class councils and pre-councils: a teacher matter, not a student one.
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

        // Mock evaluations and examinations: the students concerned and their teachers.
        ['DCG Blanc', ['DCG'], true, true],
        ['DCG blanc', ['DCG'], true, true],
        ['BTS Blanc MCO', ['MCO1', 'MCO2'], true, true],
        ['BTS blanc CG/SIO', ['CG1', 'CG2', 'SIO1', 'SIO2'], true, true],
        ['BTS Blanc CG/SIO', ['CG1', 'CG2', 'SIO1', 'SIO2'], true, true],
        ['Examens Bachelor Info', ['Bac+3 Info'], true, true],
        ['Oraux de stage CG1/SIO1', ['CG1', 'SIO1'], true, true],
        ['Epreuves écrites', ['CG2', 'SIO2', 'MCO2'], true, true],
        ['Epreuves orales', ['CG2', 'SIO2', 'MCO2'], true, true],

        // Highlights of a single cohort.
        ['Séminaire rentrée MCO', ['MCO1', 'MCO2'], true, true],
        ['Rentrée Bachelor info', ['Bac+3 Info'], true, true],
        ['Business Game', ['MCO1', 'MCO2'], true, true],
    ];

    /**
     * Cells carrying several events with no separator, or mixing an event and an internship
     * annotation: the workbook wrote them on several lines of a single box, and the extraction glued
     * them back together. Rewritten here with the missing « / ».
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

    /** Known times that cannot be read from the label, to avoid whole-day events. */
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

            // No « everyone » audience exists: AllStaff only targets the administration
            // (App\Service\AudienceResolver::STAFF_ROLES). A campus-wide event is therefore targeted
            // at every program, students and teachers included.
            $agendaEvent->setAudienceTypes([MessageAudienceType::Program]);
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
                match (true) {
                    $students && $teachers => 'étudiants + enseignants',
                    $teachers => 'enseignants',
                    default => 'étudiants',
                },
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

    /** Marks the events coming from this import, so they can be redone without touching the rest. */
    private const string DESCRIPTION = 'Repris du calendrier 2026-2027';

    /**
     * One cell → one or more dated entries: split on « / », removal of the annotations that are not
     * events, extraction of the time.
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
        // « Conseil de classe CG1 DCG Blanc »: two events that no separator separates, the workbook
        // having written them on two lines of the same cell. The known cases are listed.
        $note = strtr($note, self::REWRITES);

        // The « / » that separate nothing - a fraction, a title - are put out of harm's way for the
        // duration of the split.
        $note = str_replace(['CG/SIO', 'CG1/SIO1', '1/2'], ['CG~SIO', 'CG1~SIO1', '1~2'], $note);

        return array_values(array_filter(array_map(
            static fn (string $part): string => trim($part, " \t?"),
            explode('/', $note),
        )));
    }

    /**
     * « 18h - Remise des diplômes », « 12h Conseil de classe DCG », « JPO (9 - 15h) »: the time is
     * in the text when it is there at all. With no time, the event covers the teaching day.
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
            $start = \sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);

            return [trim($m[3]), $start, \sprintf('%02d:%02d', min(23, (int) $m[1] + 1), (int) $m[2])];
        }

        return [$title, '08:00', '18:00'];
    }

    /**
     * Merges consecutive days with the same title: « BTS Blanc MCO » from 4 to 6 November makes one
     * three-day event, not three events.
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
