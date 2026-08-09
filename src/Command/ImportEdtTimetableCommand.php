<?php

namespace App\Command;

use App\Entity\LessonSession;
use App\Entity\LessonType;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\Room;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use App\Repository\LessonTypeRepository;
use App\Repository\OptionRepository;
use App\Repository\ProgramRepository;
use App\Repository\RoomRepository;
use App\Repository\TopicGroupRepository;
use App\Repository\TopicRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * OUTIL DE DÉVELOPPEMENT - peuple une base de dev avec l'emploi du temps du PDF
 * design/sources/EDT/EDT CLASSES CAMPUS 1ER SEMESTRE.pdf : matières, enseignants, salles, options
 * de groupe, puis un créneau par cours et par jour de présence.
 *
 * Le PDF n'est pas relu ici : il ne contient qu'une semaine type (semaine 45, 02→08/11/2026) dessinée
 * en rectangles, extraite en amont vers design/sources/EDT/edt-semaine-type.json. Les jours où poser
 * cette semaine viennent de presence-2026-2027.json, lui-même tiré des couleurs de présence du
 * classeur du calendrier - ce qui écarte d'office vacances, stages et périodes d'épreuves.
 *
 * Ce que le PDF ne dit pas et qui est donc décidé ici : les salles (absentes du document, réparties
 * par la table ROOMS ci-dessous) et le type de cours (un type unique « Cours »).
 */
#[AsCommand(
    name: 'app:import-edt-timetable',
    description: "[dev] Crée matières, enseignants, salles et créneaux à partir de l'emploi du temps.",
)]
class ImportEdtTimetableCommand extends Command
{
    /**
     * Grille de base : la semaine type du PDF du 1er semestre, valable toute l'année pour six des
     * sept formations.
     */
    private const string BASE_GRID = 'edt-semaine-type.json';

    /**
     * Formations dont la grille change en cours d'année, avec le fichier de remplacement et le
     * premier jour où il s'applique. Le BTS SIO 1ère année bascule sur sa grille de 2e semestre à
     * la semaine 3 de 2027, celle qu'imprime « EDT CLASSES CAMPUS 2E SEMESTRE-SIO1 » : c'est là
     * qu'apparaissent les cours B2 dédoublés par option SISR / SLAM.
     *
     * @var array<string, array{file: string, from: string}>
     */
    private const array GRID_OVERRIDES = [
        'SIO1' => ['file' => 'edt-semaine-type-sio1-s2.json', 'from' => '2027-01-18'],
    ];

    /** @var array<string, string> nom court de la formation dans le JSON → shortName du Program */
    private const array PROGRAMS = [
        'CG1' => 'CG1', 'CG2' => 'CG2', 'DCG' => 'DCG',
        'MCO1' => 'MCO1', 'MCO2' => 'MCO2', 'SIO1' => 'SIO1', 'SIO2' => 'SIO2',
    ];

    /**
     * Salles par formation, dans l'ordre de préférence. Trois salles n'y figurent pas : Saint
     * Georges et Saint Gabriel, réservées au Bac+3 Info (absent de ces PDF), et Saint Régis, mise
     * hors jeu à la demande. Saint Benoit sert de salle de débordement commune aux cours dédoublés
     * (demi-groupes, parcours) - l'attribution effective est faite créneau par créneau, en évitant
     * qu'une salle soit occupée deux fois à la même heure.
     *
     * @var array<string, list<string>>
     */
    private const array ROOMS = [
        'SIO1' => ['Saint Eloi', 'Saint Antoine'],
        'SIO2' => ['Saint Jacques', 'Saint Patrick'],
        'CG1' => ['Sainte Anne'],
        'CG2' => ['Saint Augustin'],
        'DCG' => ['Sainte Claire'],
        'MCO1' => ['Saint Etienne'],
        'MCO2' => ['Saint Loup'],
    ];

    private const array SPARE_ROOMS = ['Saint Benoit'];

    /**
     * Salles à créer si elles n'existent pas déjà. Sainte Anne remplace Saint Régis pour le BTS CG
     * 1ère année : toutes les autres salles disponibles étaient déjà prises par une autre classe
     * aux mêmes heures, il en fallait donc une de plus.
     */
    private const array NEW_ROOMS = ['Sainte Claire', 'Saint Etienne', 'Saint Loup', 'Saint Benoit', 'Sainte Anne'];

    /** Les cours SISR de SIO2 se tiennent dans la salle réseau, les autres en salle de classe. */
    private const string SISR_MARKER = 'SISR';

    /**
     * Enseignants du PDF déjà présents en base (le PDF ne donne que « NOM I. »).
     *
     * @var array<string, string>
     */
    private const array KNOWN_TEACHERS = [
        'THARAUD S.' => 'stharaud',
        'SAUTOUR F.' => 'fsautour',
        'CHATELAIS L.' => 'lchatelais',
        'LIAGRE J.' => 'jliagre',
        'BOUBY C.' => 'cbouby',
        'GOUBAULT DE BRUGIERE C.' => 'cgoubaultdebrugiere',
        'MALIGE V.' => 'vmalige',
    ];

    /** Filière et classe portées en rôle, par formation - même vocabulaire que les groupes LDAP. */
    private const array PROGRAM_ROLES = [
        'CG1' => ['ROLE_CG', 'ROLE_CG-1'],
        'CG2' => ['ROLE_CG', 'ROLE_CG-2'],
        'DCG' => ['ROLE_DCG'],
        'MCO1' => ['ROLE_MCO', 'ROLE_MCO-1'],
        'MCO2' => ['ROLE_MCO', 'ROLE_MCO-2'],
        'SIO1' => ['ROLE_SIO', 'ROLE_SIO-1'],
        'SIO2' => ['ROLE_SIO', 'ROLE_SIO-2'],
    ];

    /**
     * Codes de groupe du PDF → option à créer, et formations auxquelles la rattacher. Demandé
     * explicitement : un cours dédoublé porte son groupe en Option, pas dans son titre.
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>}> code → [nom, nom court, formations]
     */
    private const array GROUP_OPTIONS = [
        'BTSI1_GR1' => ['Groupe 1', 'GR1', ['SIO1']],
        'BTSI1_GR2' => ['Groupe 2', 'GR2', ['SIO1']],
        '(A)' => ['Groupe A', 'GRA', ['CG1', 'DCG']],
        '(B)' => ['Groupe B', 'GRB', ['CG1', 'DCG']],
        'BTSC1_PARCI' => ['Parcours Bilingue', 'BILING', ['CG1', 'CG2', 'SIO1', 'SIO2', 'MCO1', 'MCO2']],
        'BTSC1_MINIE' => ['Mini-entreprise', 'MINIE', ['CG1', 'CG2']],
    ];

    /** Options déjà en base repérées par le libellé de la matière. */
    private const array SUBJECT_OPTIONS = ['SISR' => 'SISR', 'SLAM' => 'SLAM'];

    /**
     * Codes de groupe qui désignent une option déjà existante plutôt qu'un demi-groupe : au 2e
     * semestre, le BTS SIO 1ère année dédouble ses cours B2 par option de spécialité.
     *
     * @var array<string, string>
     */
    private const array GROUP_IS_OPTION = ['BTS SIO1 SISR' => 'SISR', 'BTS SIO1 SLAM' => 'SLAM'];

    private const array OPTION_COLORS = ['#4299e1', '#38b2ac', '#ed8936', '#9f7aea', '#48bb78', '#f56565'];

    /**
     * Regroupement des matières, par famille de formation puis par libellé exact. Les préfixes
     * B1/B2/B3 des BTS SIO sont ceux du référentiel, ils font les groupes tels quels.
     *
     * @var array<string, array<string, string>>
     */
    private const array TOPIC_GROUPS = [
        'SIO' => [
            'B1 - Système réseau' => 'Bloc 1 - Support & réseau',
            'B1 - Les Fondamentaux' => 'Bloc 1 - Support & réseau',
            'B1 - Base de données' => 'Bloc 1 - Support & réseau',
            'B1 - Programmation' => 'Bloc 1 - Support & réseau',
            'B1 - Gestion patrimoine info' => 'Bloc 1 - Support & réseau',
            "B1 - Dévpt d'applications" => 'Bloc 1 - Support & réseau',
            'B1 - Infra Web / Cloud' => 'Bloc 1 - Support & réseau',
            'B2 - SISR' => 'Bloc 2 - Développement & infrastructure',
            'B2 SLAM - Base de données' => 'Bloc 2 - Développement & infrastructure',
            'B2 SLAM - Programmation' => 'Bloc 2 - Développement & infrastructure',
            'B3 - Cybersécurité' => 'Bloc 3 - Cybersécurité',
            'B3 - Cybersécurité des SI' => 'Bloc 3 - Cybersécurité',
            'B3 - Cybersécurité et données' => 'Bloc 3 - Cybersécurité',
        ],
        'CG' => [
            'CONT. & TRAIT. COMPTABLE' => 'Comptabilité & fiscalité',
            'GESTION DES OBLIG. FISCALES' => 'Comptabilité & fiscalité',
            "CONT. & PRODUC. DE L'INFO" => 'Comptabilité & fiscalité',
            "ANAL. & PREV. DE L'ACTIVITE" => 'Analyse & finance',
            'ANAL. DE LA SITUATION FINANCIE' => 'Analyse & finance',
            'P7' => 'Analyse & finance',
            'GEST. DES REL. SOCIALES' => 'Gestion sociale',
        ],
        'MCO' => [
            'DRCVC' => 'Blocs professionnels',
            'ADOC' => 'Blocs professionnels',
            'GESTION OPERATIONNELLE' => 'Blocs professionnels',
            'MANAGEMENT DES UC' => 'Blocs professionnels',
        ],
        'DCG' => [
            'DROIT DES SOCIETES' => 'UE juridiques',
            'DROIT SOCIAL' => 'UE juridiques',
            "FINANCE D'ENTREPRISES" => 'UE gestion',
            'CONTROLE DE GESTION' => 'UE gestion',
        ],
    ];

    /** Matières transverses, quel que soit le diplôme. */
    private const array COMMON_TOPIC_GROUPS = [
        'MATHEMATIQUES' => 'Enseignement général',
        'ANGLAIS LV1' => 'Enseignement général',
        'FRANCAIS' => 'Enseignement général',
        'C.E.J.M.' => 'Enseignement général',
        'CULT.ECO JUR. MANAG.' => 'Enseignement général',
        'CULT.ECO.JUR.MAN.AP.' => 'Enseignement général',
        'CULTURE GENE.ET EXPR' => 'Enseignement général',
        'CULT.GENE.EXPRESSION' => 'Enseignement général',
    ];

    private const string FALLBACK_TOPIC_GROUP = 'Accompagnement & parcours';

    private const string LESSON_TYPE = 'Cours';

    /** @var array<string, User> */
    private array $teachers = [];
    /** @var array<string, Room> */
    private array $rooms = [];
    /** @var array<string, Option> */
    private array $options = [];
    /** @var array<string, TopicGroup> */
    private array $topicGroups = [];
    /** @var array<string, Topic> */
    private array $topics = [];
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgramRepository $programRepository,
        private readonly UserRepository $userRepository,
        private readonly RoomRepository $roomRepository,
        private readonly OptionRepository $optionRepository,
        private readonly TopicRepository $topicRepository,
        private readonly TopicGroupRepository $topicGroupRepository,
        private readonly LessonTypeRepository $lessonTypeRepository,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('author', null, InputOption::VALUE_REQUIRED, 'Auteur porté par les lignes créées', 'stharaud')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Supprime les créneaux déjà importés avant de regénérer')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte sans rien enregistrer')
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

        $dir = $this->projectDir.'/design/sources/EDT/';
        $presence = $this->readJson($dir.'presence-2026-2027.json');
        $cells = [];
        foreach ($this->readJson($dir.self::BASE_GRID) as $cell) {
            $cells[] = $cell + ['grille' => 'base'];
        }
        foreach (self::GRID_OVERRIDES as $classe => $override) {
            foreach ($this->readJson($dir.$override['file']) as $cell) {
                $cells[] = $cell + ['grille' => $classe];
            }
        }
        if ([] === $cells || [] === $presence) {
            $io->error(\sprintf('Grilles ou presence-2026-2027.json introuvables ou vides dans %s.', $dir));

            return Command::FAILURE;
        }

        $programs = [];
        foreach (self::PROGRAMS as $key => $shortName) {
            $program = $this->programRepository->findOneBy(['shortName' => $shortName]);
            if (!$program instanceof Program) {
                $this->warnings[] = \sprintf('Formation « %s » absente de la base, ignorée.', $shortName);
                continue;
            }
            $programs[$key] = $program;
        }

        // Les étapes de référentiel enregistrent au fil de l'eau (une salle doit exister avant
        // qu'un créneau la référence) : l'essai à blanc tient donc dans une transaction annulée à
        // la fin, plutôt que dans un « ne pas enregistrer » qui laisserait passer ces écritures.
        $dryRun = (bool) $input->getOption('dry-run');
        if ($dryRun) {
            $this->entityManager->getConnection()->beginTransaction();
        }

        if ($input->getOption('replace') && !$dryRun) {
            $deleted = $this->entityManager->createQuery(
                'DELETE FROM App\Entity\LessonSession s WHERE s.program IN (:programs)'
            )->setParameter('programs', array_values($programs))->execute();
            $io->note(\sprintf('%d créneau(x) supprimé(s) avant regénération.', $deleted));
        }

        $io->section('Référentiel');
        $lessonType = $this->resolveLessonType($author);
        $this->resolveRooms($author);
        $this->resolveTeachers($cells, $programs);
        $this->resolveOptions($programs, $author);
        $this->resolveTopics($cells, $programs, $author);
        $io->writeln(\sprintf(
            '  %d salle(s), %d enseignant(s), %d option(s), %d groupe(s) de matières, %d matière(s)',
            \count($this->rooms), \count($this->teachers), \count($this->options),
            \count($this->topicGroups), \count($this->topics),
        ));

        $io->section('Créneaux');
        $roomBySlot = $this->assignRooms($cells);
        $rows = [];
        $total = 0;
        foreach ($programs as $key => $program) {
            // Les clés d'origine sont conservées : ce sont elles qui indexent les salles attribuées.
            $classCells = array_filter($cells, static fn (array $c): bool => $c['classe'] === $key);
            $days = $presence[$key] ?? [];
            $switch = self::GRID_OVERRIDES[$key]['from'] ?? null;

            $count = 0;
            foreach ($days as $date) {
                $day = new \DateTimeImmutable($date);
                $weekday = self::WEEKDAYS[(int) $day->format('N')] ?? null;
                // Avant la bascule (ou sans bascule du tout) c'est la grille de base qui vaut,
                // après c'est celle de la formation.
                $grille = null !== $switch && $date >= $switch ? $key : 'base';

                foreach ($classCells as $index => $cell) {
                    if ($cell['jour'] !== $weekday || $cell['grille'] !== $grille) {
                        continue;
                    }
                    if (!$dryRun) {
                        $this->entityManager->persist($this->buildSession($cell, $program, $day, $lessonType, $roomBySlot[$index] ?? null));
                    }
                    ++$count;
                }
                if (!$dryRun && 0 === $count % 500) {
                    $this->entityManager->flush();
                }
            }

            $weekly = \count(array_filter($classCells, static fn (array $c): bool => 'base' === $c['grille']));
            $rows[] = [
                $key,
                null !== $switch ? \sprintf('%d puis %d', $weekly, \count($classCells) - $weekly) : (string) $weekly,
                (string) \count($days),
                (string) $count,
            ];
            $total += $count;
        }

        $this->entityManager->flush();
        foreach ($programs as $program) {
            $program->setTimetableManagementEnabled(true);
        }
        $this->entityManager->flush();

        if ($dryRun) {
            $this->entityManager->getConnection()->rollBack();
            $this->entityManager->clear();
        }

        $io->table(['Formation', 'Cours/semaine', 'Jours de présence', 'Créneaux'], $rows);

        if ([] !== $this->warnings) {
            $io->section('Points à vérifier');
            $io->listing(array_unique($this->warnings));
        }

        $message = \sprintf('%d créneaux pour %d formations.', $total, \count($programs));
        $dryRun ? $io->note($message." (essai à blanc : rien n'a été enregistré)") : $io->success($message);

        return Command::SUCCESS;
    }

    private const array WEEKDAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi'];

    /** @param array<string, mixed> $cell */
    private function buildSession(array $cell, Program $program, \DateTimeImmutable $day, LessonType $lessonType, ?Room $room): LessonSession
    {
        $session = new LessonSession($program);
        $session->setDay($day);
        $session->setStartHour(new \DateTimeImmutable($cell['debut']));
        $session->setEndHour(new \DateTimeImmutable($cell['fin']));
        $session->setLength((string) $cell['heures']);
        $session->setLessonType($lessonType);
        $session->setClassRoom($room);
        $session->setTopic($this->topics[$this->topicKey($cell['classe'], $cell['matiere'])] ?? null);
        $session->setTeacher($this->teachers[$cell['profs'][0]] ?? null);

        // Un seul enseignant par créneau côté modèle : le co-intervenant est nommé dans le titre,
        // seul endroit où il peut encore apparaître.
        if (\count($cell['profs']) > 1) {
            $session->setTitle(\sprintf('%s (avec %s)', $cell['matiere'], implode(', ', \array_slice($cell['profs'], 1))));
        }

        foreach ($this->cellOptions($cell) as $option) {
            $session->addOption($option);
        }

        return $session;
    }

    /**
     * @param array<string, mixed> $cell
     *
     * @return list<Option>
     */
    private function cellOptions(array $cell): array
    {
        $options = [];
        $groupe = self::GROUP_IS_OPTION[$cell['groupe'] ?? ''] ?? $cell['groupe'];
        if (null !== $groupe && isset($this->options[$groupe])) {
            $options[] = $this->options[$groupe];
        }
        foreach (self::SUBJECT_OPTIONS as $marker => $shortName) {
            if (str_contains($cell['matiere'], $marker) && isset($this->options[$shortName])) {
                $options[] = $this->options[$shortName];
            }
        }

        return $options;
    }

    /**
     * Attribue une salle à chaque cours de la semaine type. La grille se répétant à l'identique,
     * il suffit de la résoudre une fois : deux cours d'une même formation qui se chevauchent
     * (demi-groupes, parcours) prennent la salle suivante de la formation, puis une salle de
     * débordement, en vérifiant qu'elle n'est pas déjà prise à cette heure-là toutes formations
     * confondues.
     *
     * @param list<array<string, mixed>> $grid
     *
     * @return array<string, Room>
     */
    private function assignRooms(array $grid): array
    {
        $assigned = [];
        $busy = [];
        $shared = [];

        // Une formation qui bascule de grille en cours d'année vit deux semaines types successives,
        // jamais simultanées : ses cours d'après bascule sont placés dans un second temps, en
        // libérant les salles que ses propres cours d'avant occupaient, mais en gardant occupées
        // celles des autres formations.
        $passes = [array_filter($grid, static fn (array $c): bool => 'base' === $c['grille'])];
        foreach (array_keys(self::GRID_OVERRIDES) as $classe) {
            $passes[] = array_filter($grid, static fn (array $c): bool => $c['grille'] === $classe);
        }

        foreach ($passes as $pass => $cells) {
            if ($pass > 0) {
                $classe = array_values($cells)[0]['classe'] ?? null;
                foreach ($busy as $room => $slots) {
                    $busy[$room] = array_values(array_filter($slots, static fn (array $s): bool => $s[3] !== $classe));
                }
            }
            $assigned += $this->allocate($cells, $busy, $shared);
        }

        return $assigned;
    }

    /**
     * @param array<int, array<string, mixed>> $grid
     * @param array<string, list<array{0: string, 1: string, 2: string, 3: string}>> $busy
     * @param array<string, Room>              $shared
     *
     * @return array<int, Room>
     */
    private function allocate(array $grid, array &$busy, array &$shared): array
    {
        $assigned = [];

        foreach ($grid as $index => $cell) {
            $classe = $cell['classe'];
            $pool = self::ROOMS[$classe] ?? [];

            // Les cours communs à plusieurs classes (Parcours Bilingue, Mini-entreprise) figurent
            // sur chaque emploi du temps mais n'ont lieu qu'une fois : même jour, même horaire,
            // même intervenant, même groupe - donc la même salle, pas une salle chacun.
            $signature = implode('|', [$cell['jour'], $cell['debut'], $cell['fin'], $cell['matiere'], implode(',', $cell['profs']), $cell['groupe'] ?? '']);
            if (isset($shared[$signature])) {
                $assigned[$index] = $shared[$signature];
                continue;
            }

            // SIO2 : les cours SISR se tiennent dans la seconde salle, le reste dans la première.
            if ('SIO2' === $classe && str_contains($cell['matiere'], self::SISR_MARKER)) {
                $pool = [self::ROOMS['SIO2'][1], self::ROOMS['SIO2'][0]];
            }

            foreach ([...$pool, ...self::SPARE_ROOMS] as $name) {
                if (!isset($this->rooms[$name])) {
                    continue;
                }
                $free = true;
                foreach ($busy[$name] ?? [] as [$jour, $debut, $fin]) {
                    if ($jour === $cell['jour'] && $cell['debut'] < $fin && $debut < $cell['fin']) {
                        $free = false;
                        break;
                    }
                }
                if ($free) {
                    $assigned[$index] = $shared[$signature] = $this->rooms[$name];
                    $busy[$name][] = [$cell['jour'], $cell['debut'], $cell['fin'], $classe];
                    continue 2;
                }
            }

            $this->warnings[] = \sprintf(
                'Aucune salle libre pour %s %s %s (%s) : créneau laissé sans salle.',
                $classe, $cell['jour'], $cell['debut'], $cell['matiere'],
            );
        }

        return $assigned;
    }

    private function resolveLessonType(User $author): LessonType
    {
        $type = $this->lessonTypeRepository->findOneBy(['name' => self::LESSON_TYPE]);
        if (!$type instanceof LessonType) {
            // Le PDF ne distingue ni CM, ni TD, ni TP : un type unique plutôt que trois inventés.
            $type = new LessonType(self::LESSON_TYPE, '#4299e1');
            $type->setCreatedBy($author);
            $this->entityManager->persist($type);
        }

        return $type;
    }

    private function resolveRooms(User $author): void
    {
        foreach ($this->roomRepository->findAll() as $room) {
            $this->rooms[$room->getName()] = $room;
        }
        foreach (self::NEW_ROOMS as $name) {
            if (!isset($this->rooms[$name])) {
                $room = new Room($name);
                $room->setCreatedBy($author);
                $this->entityManager->persist($room);
                $this->rooms[$name] = $room;
            }
        }
        $this->entityManager->flush();
    }

    /**
     * Apparie les enseignants du PDF aux comptes existants, crée les manquants, et rattache chacun
     * aux formations où il intervient réellement (relation program_teacher + rôles de filière et de
     * classe). Rien n'est écrit dans ldap_manage_* : ces comptes sont des données de dev, ils n'ont
     * pas à remonter dans l'annuaire.
     *
     * @param list<array<string, mixed>> $grid
     * @param array<string, Program>     $programs
     */
    private function resolveTeachers(array $grid, array $programs): void
    {
        $classesByTeacher = [];
        foreach ($grid as $cell) {
            foreach ($cell['profs'] as $name) {
                $classesByTeacher[$name][$cell['classe']] = true;
            }
        }

        $slugger = new AsciiSlugger();
        foreach ($classesByTeacher as $name => $classes) {
            $username = self::KNOWN_TEACHERS[$name] ?? null;
            $teacher = null !== $username ? $this->userRepository->findOneBy(['username' => $username]) : null;

            if (!$teacher instanceof User) {
                [$lastname, $initial] = $this->splitName($name);
                $username ??= strtolower($slugger->slug(mb_substr($initial, 0, 1).' '.$lastname, '')->toString());
                $teacher = $this->userRepository->findOneBy(['username' => $username]);
            }

            if (!$teacher instanceof User) {
                [$lastname, $initial] = $this->splitName($name);
                $teacher = new User($username);
                $teacher->setEmail($username.'@beaupeyrat.lan');
                $teacher->setFirstname($initial);
                $teacher->setLastname($lastname);
                $this->entityManager->persist($teacher);
            }

            $roles = $teacher->getRoles();
            $roles[] = 'ROLE_TEACHER';
            $roles[] = 'ROLE_CAMPUS';
            foreach (array_keys($classes) as $classe) {
                foreach (self::PROGRAM_ROLES[$classe] ?? [] as $role) {
                    $roles[] = $role;
                }
                if (isset($programs[$classe])) {
                    $programs[$classe]->addTeacher($teacher);
                }
            }
            $teacher->setRoles(array_values(array_unique(array_filter($roles, static fn (string $r): bool => 'ROLE_USER' !== $r))));

            $this->teachers[$name] = $teacher;
        }

        $this->entityManager->flush();
    }

    /** « GOUBAULT DE BRUGIERE C. » → [« Goubault De Brugiere », « C »] */
    private function splitName(string $name): array
    {
        $parts = explode(' ', trim($name));
        $initial = rtrim(array_pop($parts) ?? '', '.');

        return [mb_convert_case(implode(' ', $parts), \MB_CASE_TITLE, 'UTF-8'), $initial];
    }

    /** @param array<string, Program> $programs */
    private function resolveOptions(array $programs, User $author): void
    {
        foreach (self::SUBJECT_OPTIONS as $shortName) {
            $option = $this->optionRepository->findOneBy(['shortName' => $shortName]);
            if ($option instanceof Option) {
                $this->options[$shortName] = $option;
            }
        }

        $color = 0;
        foreach (self::GROUP_OPTIONS as $code => [$name, $shortName, $classes]) {
            $option = $this->optionRepository->findOneBy(['shortName' => $shortName]);
            if (!$option instanceof Option) {
                $option = new Option($name, $shortName, self::OPTION_COLORS[$color % \count(self::OPTION_COLORS)]);
                $option->setCreatedBy($author);
                $this->entityManager->persist($option);
            }
            foreach ($classes as $classe) {
                if (isset($programs[$classe])) {
                    $option->addProgram($programs[$classe]);
                }
            }
            $this->options[$code] = $option;
            ++$color;
        }

        $this->entityManager->flush();
    }

    /**
     * Une matière appartient à une formation (Topic::$program) : une matière commune à deux classes
     * fait donc deux lignes, une par formation, comme le veut le modèle.
     *
     * @param list<array<string, mixed>> $grid
     * @param array<string, Program>     $programs
     */
    private function resolveTopics(array $grid, array $programs, User $author): void
    {
        $mainTeacher = [];
        foreach ($grid as $cell) {
            $key = $this->topicKey($cell['classe'], $cell['matiere']);
            $mainTeacher[$key][$cell['profs'][0]] = ($mainTeacher[$key][$cell['profs'][0]] ?? 0) + 1;
        }

        foreach ($grid as $cell) {
            $classe = $cell['classe'];
            $program = $programs[$classe] ?? null;
            if (null === $program) {
                continue;
            }

            $key = $this->topicKey($classe, $cell['matiere']);
            if (isset($this->topics[$key])) {
                continue;
            }

            $groupName = $this->topicGroupName($classe, $cell['matiere']);
            $groupKey = $classe.'|'.$groupName;
            if (!isset($this->topicGroups[$groupKey])) {
                $group = $this->topicGroupRepository->findOneBy(['name' => $groupName, 'program' => $program])
                    ?? new TopicGroup($groupName, $program);
                $group->setCreatedBy($author);
                $this->entityManager->persist($group);
                $this->topicGroups[$groupKey] = $group;
            }

            $topic = $this->topicRepository->findOneBy(['name' => $cell['matiere'], 'program' => $program])
                ?? new Topic($cell['matiere'], $program);
            $topic->setCreatedBy($author);
            $topic->setTopicGroup($this->topicGroups[$groupKey]);
            arsort($mainTeacher[$key]);
            $topic->setTeacher($this->teachers[array_key_first($mainTeacher[$key])] ?? null);
            $this->entityManager->persist($topic);
            $this->topics[$key] = $topic;
        }

        $this->entityManager->flush();
    }

    private function topicGroupName(string $classe, string $subject): string
    {
        $family = rtrim($classe, '12');

        return self::TOPIC_GROUPS[$family][$subject]
            ?? self::COMMON_TOPIC_GROUPS[$subject]
            ?? self::FALLBACK_TOPIC_GROUP;
    }

    private function topicKey(string $classe, string $subject): string
    {
        return $classe.'|'.$subject;
    }

    /** @return list<array<string, mixed>> */
    private function readJson(string $file): array
    {
        if (!is_readable($file)) {
            return [];
        }

        return json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
    }
}
