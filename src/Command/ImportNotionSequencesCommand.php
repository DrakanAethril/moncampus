<?php

namespace App\Command;

use App\Entity\AbstractLibraryTag;
use App\Entity\LibraryBlocTag;
use App\Entity\LibraryNiveauTag;
use App\Entity\LibraryOptionTag;
use App\Entity\SeancePhaseTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\EvaluationNature;
use App\Repository\AbstractLibraryTagRepository;
use App\Repository\LibraryBlocTagRepository;
use App\Repository\LibraryNiveauTagRepository;
use App\Repository\LibraryOptionTagRepository;
use App\Repository\SequenceTemplateRepository;
use App\Repository\UserRepository;
use App\Service\LibraryTagResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reprend un export Markdown/CSV Notion de séquences pédagogiques dans la bibliothèque personnelle
 * d'un enseignant (App\Entity\SequenceTemplate et ses séances/phases).
 *
 * L'export attendu est celui produit par « Exporter » sur la base Notion des séquences, au format
 * Markdown & CSV, avec les sous-pages : un CSV d'index à la racine, un .md par séquence, puis par
 * séquence un CSV « Organisation de la séquence » et un .md par séance.
 *
 * L'appariement se fait sur les titres - l'export ne porte aucun identifiant commun avec la base -
 * en ignorant les apostrophes, l'export écrivant l'apostrophe typographique là où une saisie faite
 * dans l'application porte souvent l'apostrophe droite.
 *
 * Ce que l'export contient et que la bibliothèque ne stocke pas est laissé de côté : les périodes
 * et dates de séance (une séquence de bibliothèque est sans dates, par construction) et les pièces
 * jointes (images, PDF) - seuls les textes sont repris.
 */
#[AsCommand(
    name: 'app:import-notion-sequences',
    description: "Importe un export Notion de séquences pédagogiques dans la bibliothèque d'un enseignant.",
)]
class ImportNotionSequencesCommand extends Command
{
    private const string DEFAULT_RELATIVE_PATH = 'design/notion/Séquences pédagogiques 2025-2026';

    /** Propriétés Notion connues : toute autre ligne du bloc d'entête prolonge la précédente. */
    private const array PROPERTY_LABELS = [
        'Bloc', 'Niveau', 'Option', 'Période', 'Séances', 'Date', 'Durée', 'Objectifs', 'Objectifs/Activité',
    ];

    /** Entêtes du tableau d'une page séquence → champ de SequenceTemplate, par clé normalisée. */
    private const array SEQUENCE_FIELDS = [
        'capacitesoucompetencesattendues' => 'setCapacitesAttendues',
        'prerequiscapacitesoucompetences' => 'setPreRequis',
        'objectifsdelasequence' => 'setObjectifs',
        'transversalites' => 'setTransversalites',
        'situationouproblematique' => 'setSituationProblematique',
        'supports' => 'setSupportsGeneraux',
    ];

    /** Libellés de ligne d'un tableau de phase → champ de SeancePhaseTemplate, par clé normalisée. */
    private const array PHASE_FIELDS = [
        'contenu' => 'setContenu',
        'objectifs' => 'setObjectifs',
        'enseignant' => 'setEnseignant',
        'etudiant' => 'setEtudiant',
        'moyenssupports' => 'setMoyensSupports',
        'difficultes' => 'setDifficultes',
    ];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Étiquettes déjà résolues pendant l'import, par classe et libellé. App\Service\LibraryTagResolver
     * cherche en base : sans ce cache, dix-neuf séquences qui portent le même Niveau créeraient dix-neuf
     * fois l'étiquette tant que rien n'est enregistré, et le flush final buterait sur l'unicité.
     *
     * @var array<string, AbstractLibraryTag>
     */
    private array $tags = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly SequenceTemplateRepository $sequenceTemplateRepository,
        private readonly LibraryTagResolver $tagResolver,
        private readonly LibraryNiveauTagRepository $niveauTagRepository,
        private readonly LibraryOptionTagRepository $optionTagRepository,
        private readonly LibraryBlocTagRepository $blocTagRepository,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, "Identifiant de l'enseignant destinataire")
            ->addOption('path', null, InputOption::VALUE_REQUIRED, "Dossier racine de l'export Notion", self::DEFAULT_RELATIVE_PATH)
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Remplace les séquences de même titre au lieu de les ignorer')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, "Analyse l'export et affiche le résultat sans rien enregistrer")
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = (string) $input->getArgument('username');
        $teacher = $this->userRepository->findOneBy(['username' => $username]);
        if (!$teacher instanceof User) {
            $io->error(\sprintf('Aucun utilisateur « %s ».', $username));

            return Command::FAILURE;
        }

        $path = (string) $input->getOption('path');
        $exportDir = str_starts_with($path, '/') ? $path : $this->projectDir.'/'.$path;
        $exportDir = rtrim($exportDir, '/');
        if (!is_dir($exportDir)) {
            $io->error(\sprintf('Dossier introuvable : %s', $exportDir));

            return Command::FAILURE;
        }

        $indexCsv = $this->findDatabaseCsv(\dirname($exportDir), basename($exportDir));
        if (null === $indexCsv) {
            $io->error(\sprintf("Aucun CSV d'index à côté de %s.", $exportDir));

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $replace = (bool) $input->getOption('replace');
        $pagesByTitle = $this->indexMarkdownPages($exportDir);

        $io->title(\sprintf('Import de %s vers la bibliothèque de %s', basename($exportDir), $username));

        $rows = [];
        $order = 0;
        foreach ($this->readCsv($indexCsv) as $row) {
            $title = trim((string) ($row['Intitulé de séquence'] ?? ''));
            if ('' === $title) {
                continue;
            }

            $page = $pagesByTitle[$this->normalize($title)] ?? null;
            if (null === $page) {
                $this->warnings[] = \sprintf('Séquence « %s » : page Markdown introuvable, ignorée.', $title);
                continue;
            }

            $existing = $this->sequenceTemplateRepository->findOneBy(['teacher' => $teacher, 'titre' => $title]);
            if (null !== $existing && !$replace) {
                $rows[] = [$title, '—', '—', 'déjà présente, ignorée'];
                continue;
            }
            if (null !== $existing) {
                if (!$dryRun) {
                    $this->entityManager->remove($existing);
                    $this->entityManager->flush();
                }
                $this->warnings[] = \sprintf('Séquence « %s » : ancienne version remplacée.', $title);
            }

            $sequence = $this->importSequence($teacher, $title, $page, $row, ++$order);
            $seanceCount = $sequence->getSeanceTemplates()->count();
            $phaseCount = 0;
            foreach ($sequence->getSeanceTemplates() as $seance) {
                $phaseCount += $seance->getSeancePhaseTemplates()->count();
            }

            $rows[] = [$title, (string) $seanceCount, (string) $phaseCount, 'importée'];
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->table(['Séquence', 'Séances', 'Phases', 'Statut'], $rows);

        if ([] !== $this->warnings) {
            $io->section('Points à vérifier');
            $io->listing($this->warnings);
        }

        $imported = \count(array_filter($rows, static fn (array $row): bool => 'importée' === $row[3]));
        $message = \sprintf('%d séquence(s) importée(s) pour %s.', $imported, $username);
        $dryRun ? $io->note($message.' (essai à blanc : rien n\'a été enregistré)') : $io->success($message);

        return Command::SUCCESS;
    }

    /** @param array<string, string> $indexRow ligne du CSV d'index, qui porte les facettes de la séquence */
    private function importSequence(User $teacher, string $title, string $page, array $indexRow, int $order): SequenceTemplate
    {
        $markdown = (string) file_get_contents($page);
        $properties = $this->readProperties($markdown);

        $sequence = new SequenceTemplate($teacher);
        $sequence->setTitre($title);
        $sequence->setOrder($order);

        $niveau = $this->resolveTag($this->niveauTagRepository, LibraryNiveauTag::class, $teacher, $properties['Niveau'] ?? ($indexRow['Niveau'] ?? null));
        if ($niveau instanceof LibraryNiveauTag) {
            $sequence->setNiveau($niveau);
        }

        $option = $this->resolveTag($this->optionTagRepository, LibraryOptionTag::class, $teacher, $properties['Option'] ?? ($indexRow['Option'] ?? null));
        if ($option instanceof LibraryOptionTag) {
            $sequence->setOption($option);
        }

        $blocs = $properties['Bloc'] ?? ($indexRow['Bloc'] ?? '');
        foreach (explode(',', $blocs) as $label) {
            $bloc = $this->resolveTag($this->blocTagRepository, LibraryBlocTag::class, $teacher, $label);
            if ($bloc instanceof LibraryBlocTag) {
                $sequence->addBloc($bloc);
            }
        }

        foreach ($this->readLabelledTable($markdown, self::SEQUENCE_FIELDS) as $setter => $value) {
            $sequence->{$setter}($value);
        }

        $this->entityManager->persist($sequence);

        $seanceDir = \dirname($page).'/'.$this->pageDirectoryName($page);
        $seanceCsv = is_dir($seanceDir) ? $this->findDatabaseCsv($seanceDir, 'Organisation de la séquence') : null;
        if (null === $seanceCsv) {
            // Les pages « AP - … » de l'export n'ont pas de séances : un projet suivi sur la
            // période, décrit en prose et en images, pas un déroulé de séquence.
            $this->warnings[] = \sprintf('Séquence « %s » : aucune séance dans l\'export, séquence créée sans séance.', $title);

            return $sequence;
        }

        $seancePagesByTitle = $this->indexMarkdownPages(\dirname($seanceCsv).'/'.$this->databaseDirectoryName($seanceCsv));
        $ordre = 0;
        foreach ($this->readCsv($seanceCsv) as $row) {
            $seanceTitle = trim((string) ($row['Intitulé'] ?? ''));
            if ('' === $seanceTitle) {
                continue;
            }

            $seancePage = $seancePagesByTitle[$this->normalize($seanceTitle)] ?? null;
            if (null === $seancePage) {
                $this->warnings[] = \sprintf('Séance « %s » (%s) : page Markdown introuvable, ignorée.', $seanceTitle, $title);
                continue;
            }

            $this->importSeance($sequence, $seanceTitle, $seancePage, ++$ordre);
        }

        return $sequence;
    }

    private function importSeance(SequenceTemplate $sequence, string $title, string $page, int $ordre): void
    {
        $markdown = (string) file_get_contents($page);
        $properties = $this->readProperties($markdown);
        $sections = $this->readSections($markdown, '##');

        $seance = new SeanceTemplate($sequence);
        $seance->setTitre($title);
        $seance->setOrdre($ordre);
        $seance->setDuree($this->parseMinutes($properties['Durée'] ?? null));
        // « Objectifs » sur la plupart des séquences, « Objectifs/Activité » sur celles où
        // l'enseignant a renommé la propriété : c'est la même colonne Notion.
        $objectifs = $this->toText($properties['Objectifs'] ?? ($properties['Objectifs/Activité'] ?? ''));
        $seance->setObjectifs('' === $objectifs ? null : $objectifs);
        $seance->setEvaluationNature($this->guessEvaluationNature($title));
        $seance->setAvantDescription($this->twoColumnText($sections['Avant la séance'] ?? ''));
        $seance->setApresDescription($this->twoColumnText($sections['Après la séance'] ?? ''));
        $seance->setCahierDeTexteDescription($this->toHtml($sections['Cahier de texte'] ?? ''));

        $sequence->getSeanceTemplates()->add($seance);
        $this->entityManager->persist($seance);

        $ordrePhase = 0;
        foreach ($this->readSections($sections['Déroulé de la séance'] ?? '', '###') as $nom => $body) {
            $phase = new SeancePhaseTemplate($seance);
            $phase->setNom($nom);
            $phase->setOrdre(++$ordrePhase);

            foreach ($this->readLabelledTable($body, self::PHASE_FIELDS + ['duree' => 'setDuree']) as $setter => $value) {
                $phase->{$setter}('setDuree' === $setter ? $this->parseMinutes($value) : $value);
            }

            $seance->getSeancePhaseTemplates()->add($phase);
            $this->entityManager->persist($phase);
        }
    }

    /**
     * @template T of AbstractLibraryTag
     *
     * @param AbstractLibraryTagRepository<T> $repository
     * @param class-string<T>                 $tagClass
     */
    private function resolveTag(AbstractLibraryTagRepository $repository, string $tagClass, User $teacher, ?string $label): ?AbstractLibraryTag
    {
        $label = trim((string) $label);
        if ('' === $label) {
            return null;
        }

        return $this->tags[$tagClass.'|'.$label] ??= $this->tagResolver->resolveOne($repository, $tagClass, $teacher, $label);
    }

    // Une séance porte une nature d'évaluation quand son titre l'annonce - « Evaluation sommative
    // (BTS blanc) », « Evaluation diagnostique ». Une séance qui ne fait que nommer une évaluation
    // (« Correction évaluation sommative », « Remédiation et préparation à l'évaluation
    // sommative ») n'en est pas une : d'où le titre qui doit *commencer* par le mot.
    private function guessEvaluationNature(string $title): ?EvaluationNature
    {
        $normalized = $this->normalize($title);
        if (!str_starts_with($normalized, 'evaluation') && !str_starts_with($normalized, 'évaluation')) {
            return null;
        }

        return match (true) {
            str_contains($normalized, 'sommative') => EvaluationNature::Summative,
            str_contains($normalized, 'formative') => EvaluationNature::Formative,
            str_contains($normalized, 'diagnostique') => EvaluationNature::Diagnostic,
            default => null,
        };
    }

    /**
     * Bloc d'entête d'une page Notion : « Libellé: valeur », la valeur pouvant courir sur
     * plusieurs lignes jusqu'à la propriété suivante.
     *
     * @return array<string, string>
     */
    private function readProperties(string $markdown): array
    {
        $properties = [];
        $current = null;

        foreach (explode("\n", $markdown) as $index => $line) {
            if (0 === $index) {
                continue; // le titre H1
            }
            if (str_starts_with($line, '#') || str_starts_with($line, '|') || str_starts_with($line, '<')) {
                break;
            }

            $matched = null;
            foreach (self::PROPERTY_LABELS as $label) {
                if (str_starts_with($line, $label.':')) {
                    $matched = $label;
                    break;
                }
            }

            if (null !== $matched) {
                $current = $matched;
                $properties[$current] = trim(substr($line, \strlen($matched) + 1));
            } elseif (null !== $current && '' !== trim($line)) {
                $properties[$current] .= "\n".trim($line);
            }
        }

        return array_map($this->toText(...), array_filter($properties, static fn (string $value): bool => '' !== trim($value)));
    }

    /**
     * Découpe un Markdown en sections d'un niveau de titre donné : « titre → corps ».
     *
     * @return array<string, string>
     */
    private function readSections(string $markdown, string $level): array
    {
        $sections = [];
        $title = null;
        $body = [];

        foreach (explode("\n", $markdown) as $line) {
            if (str_starts_with($line, $level.' ') && !str_starts_with($line, $level.'# ')) {
                if (null !== $title) {
                    $sections[$title] = implode("\n", $body);
                }
                $title = trim(substr($line, \strlen($level) + 1));
                $body = [];
                continue;
            }
            if (null !== $title) {
                $body[] = $line;
            }
        }

        if (null !== $title) {
            $sections[$title] = implode("\n", $body);
        }

        return $sections;
    }

    /**
     * Tableaux Notion dont chaque valeur est précédée de son libellé en gras - aussi bien le
     * tableau à deux colonnes d'une séquence (les libellés forment une ligne, les valeurs la
     * suivante) que celui d'une phase (un libellé et sa valeur par ligne).
     *
     * @param array<string, string> $fields libellé normalisé → nom du setter
     *
     * @return array<string, string> nom du setter → valeur
     */
    private function readLabelledTable(string $markdown, array $fields): array
    {
        $values = [];
        $labels = [];

        foreach ($this->readTableRows($markdown) as $cells) {
            $rowLabels = [];
            foreach ($cells as $cell) {
                if (preg_match('/^\*\*(.+)\*\*$/us', $cell, $matches)) {
                    $rowLabels[] = $this->normalizeLabel($matches[1]);
                }
            }

            if (\count($rowLabels) === \count($cells)) {
                $labels = $rowLabels;
                continue;
            }

            // Ligne mixte « **Libellé** | valeur » (phases), ou ligne de valeurs sous ses libellés.
            if (1 === \count($rowLabels) && 2 === \count($cells)) {
                $labels = [$rowLabels[0]];
                $cells = [$cells[1]];
            }

            foreach ($cells as $index => $cell) {
                $field = $fields[$labels[$index] ?? ''] ?? null;
                if (null !== $field && '' !== ($text = $this->toText($cell))) {
                    $values[$field] = $text;
                }
            }
        }

        return $values;
    }

    /**
     * Lignes d'un tableau Markdown Notion. Une cellule peut contenir de vraies fins de ligne : une
     * ligne de tableau commence par « | » et court jusqu'à la ligne qui se termine par « | ».
     *
     * @return list<list<string>>
     */
    private function readTableRows(string $markdown): array
    {
        $rows = [];
        $buffer = null;

        foreach (explode("\n", $markdown) as $line) {
            if (null === $buffer) {
                if (!str_starts_with(ltrim($line), '|')) {
                    continue;
                }
                $buffer = ltrim($line);
            } else {
                $buffer .= "\n".$line;
            }

            if (!str_ends_with(rtrim($line), '|')) {
                continue;
            }

            $cells = array_map(trim(...), explode('|', trim($buffer)));
            array_shift($cells);
            array_pop($cells);
            $buffer = null;

            if ([] !== $cells && [] === array_filter($cells, static fn (string $cell): bool => '---' !== $cell)) {
                continue; // ligne de séparation
            }
            if ([] !== $cells) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /**
     * Sections « Avant / Après la séance » : un tableau de deux colonnes titrées, rendu en texte
     * simple - ces champs de la séance ne sont pas du texte enrichi.
     */
    private function twoColumnText(string $markdown): ?string
    {
        $rows = $this->readTableRows($markdown);
        if (\count($rows) < 2) {
            return null;
        }

        $labels = array_map(fn (string $cell): string => trim($this->toText($cell), '*'), array_shift($rows));
        $parts = [];
        foreach ($rows as $cells) {
            foreach ($cells as $index => $cell) {
                $value = $this->toText($cell);
                if ('' !== $value) {
                    $parts[] = trim(($labels[$index] ?? '')." :\n".$value);
                }
            }
        }

        return [] === $parts ? null : implode("\n\n", $parts);
    }

    /** Markdown → texte simple : gras retiré, liens aplatis, libellé suivi de l'URL si elle diffère. */
    private function toText(string $markdown): string
    {
        $text = preg_replace_callback(
            '/\[([^\]]*)\]\(([^)]*)\)/u',
            static fn (array $m): string => trim($m[1]) === trim($m[2]) ? $m[1] : \sprintf('%s (%s)', $m[1], $m[2]),
            $markdown,
        ) ?? $markdown;
        $text = preg_replace('/\*\*(.+?)\*\*/us', '$1', $text) ?? $text;

        return trim($text);
    }

    /**
     * Cahier de texte → HTML : c'est le seul champ de la séance saisi en texte enrichi côté
     * application (HugeRTE). Un paragraphe par bloc de lignes, un retour à la ligne entre les
     * lignes d'un même bloc, une liste pour les puces - aucune autre construction n'apparaît dans
     * l'export.
     */
    private function toHtml(string $markdown): ?string
    {
        $html = '';
        /** @var list<string> $paragraph */
        $paragraph = [];
        /** @var list<string> $list */
        $list = [];

        $flush = static function () use (&$html, &$paragraph, &$list): void {
            if ([] !== $paragraph) {
                $html .= '<p>'.implode('<br>', $paragraph).'</p>';
                $paragraph = [];
            }
            if ([] !== $list) {
                $html .= '<ul><li>'.implode('</li><li>', $list).'</li></ul>';
                $list = [];
            }
        };

        foreach (explode("\n", trim($markdown)) as $line) {
            $line = trim($line);
            if ('' === $line || '---' === $line) {
                $flush();
                continue;
            }

            if (preg_match('/^[-*]\s+(.*)$/u', $line, $matches)) {
                if ([] !== $paragraph) {
                    $html .= '<p>'.implode('<br>', $paragraph).'</p>';
                    $paragraph = [];
                }
                $list[] = $this->toInlineHtml($matches[1]);
                continue;
            }

            if ([] !== $list) {
                $html .= '<ul><li>'.implode('</li><li>', $list).'</li></ul>';
                $list = [];
            }
            $paragraph[] = $this->toInlineHtml($line);
        }

        $flush();

        return '' === $html ? null : $html;
    }

    private function toInlineHtml(string $line): string
    {
        $line = $this->toText($line);
        $line = htmlspecialchars($line, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace('/\*(.+?)\*/us', '<em>$1</em>', $line) ?? $line;
    }

    /**
     * Durées de l'export, écrites à la main et donc de toutes les formes : « 55 minutes », « 55’ »,
     * « 1h20 1/2G », « 4H », « 20-25 minutes », « 2h + 2h ». On retient la première durée lue -
     * borne basse d'une fourchette, durée d'un groupe quand la séance est jouée deux fois - sauf
     * quand elles s'additionnent explicitement.
     */
    private function parseMinutes(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ('' === $raw || !preg_match_all('/(\d+)\s*[hH](?:\s*(\d{1,2}))?|(\d+)\s*(?:-\s*\d+\s*)?(?:minutes?|min\b|’|\')/u', $raw, $matches, \PREG_SET_ORDER)) {
            return null;
        }

        $values = [];
        foreach ($matches as $match) {
            $values[] = '' !== ($match[1] ?? '')
                ? 60 * (int) $match[1] + (int) ($match[2] ?? 0)
                : (int) ($match[3] ?? 0);
        }

        $minutes = str_contains($raw, '+') ? array_sum($values) : $values[0];

        return $minutes > 0 ? (string) $minutes : null;
    }

    /**
     * Un CSV de base Notion s'appelle « <titre> <hash>.csv » ; sa variante « _all » n'apporte que
     * les colonnes masquées de la vue, dans un ordre différent - c'est la vue qu'on suit.
     */
    private function findDatabaseCsv(string $directory, string $title): ?string
    {
        // Les noms de fichier sont comparés sous forme normalisée : macOS stocke « é » décomposé
        // là où la même chaîne écrite dans le code est composée, et les deux ne s'égalent pas.
        $title = $this->normalizeUnicode($title).' ';
        foreach (glob($directory.'/*.csv') ?: [] as $candidate) {
            $name = $this->normalizeUnicode(basename($candidate));
            if (str_starts_with($name, $title) && !str_ends_with($name, '_all.csv')) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeUnicode(string $value): string
    {
        return \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
    }

    /**
     * Titre normalisé → chemin de la page, pour tous les .md d'un dossier. Le nom de fichier étant
     * tronqué par Notion, le titre est lu dans le H1 de la page.
     *
     * @return array<string, string>
     */
    private function indexMarkdownPages(string $directory): array
    {
        $pages = [];
        foreach (glob($directory.'/*.md') ?: [] as $page) {
            $first = strtok((string) file_get_contents($page), "\n");
            if (false === $first || !str_starts_with($first, '# ')) {
                continue;
            }
            $pages[$this->normalize(substr($first, 2))] = $page;
        }

        return $pages;
    }

    /** Dossier des sous-pages d'une page ou d'une base : son nom de fichier sans le hash ni l'extension. */
    private function pageDirectoryName(string $page): string
    {
        return preg_replace('/ [0-9a-f]{32}\.md$/', '', basename($page)) ?? basename($page);
    }

    private function databaseDirectoryName(string $csv): string
    {
        return preg_replace('/ [0-9a-f]{32}\.csv$/', '', basename($csv)) ?? basename($csv);
    }

    /**
     * Appariement par titres : l'export Notion écrit l'apostrophe typographique là où la même
     * chaîne saisie dans l'application porte souvent l'apostrophe droite. On retire donc les
     * apostrophes des deux côtés plutôt que de parier sur l'une des deux formes.
     */
    private function normalize(string $value): string
    {
        return mb_strtolower(trim(str_replace(['’', '‘', "'"], '', preg_replace('/\s+/u', ' ', $value) ?? $value)));
    }

    /**
     * Clé d'un libellé de tableau : accents, ponctuation et espaces retirés, l'export écrivant le
     * même libellé de plusieurs façons d'une page à l'autre (« Moyens/Supports », « Pré-requis :
     * capacités ou compétences »).
     */
    private function normalizeLabel(string $value): string
    {
        $value = strtr(mb_strtolower($value), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u',
            'ü' => 'u', 'ÿ' => 'y',
        ]);

        return preg_replace('/[^a-z0-9]/', '', $value) ?? $value;
    }

    /**
     * Lignes d'un CSV Notion, indexées par entête (BOM compris).
     *
     * @return list<array<string, string>>
     */
    private function readCsv(string $file): array
    {
        $handle = fopen($file, 'r');
        if (false === $handle) {
            return [];
        }

        $headers = fgetcsv($handle, escape: '');
        if (false === $headers) {
            fclose($handle);

            return [];
        }
        $headers = array_map(static fn (?string $header): string => trim(ltrim((string) $header, "\u{FEFF}")), $headers);

        $rows = [];
        while (false !== ($cells = fgetcsv($handle, escape: ''))) {
            if ([null] === $cells) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($cells[$index] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
