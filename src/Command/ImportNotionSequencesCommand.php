<?php

declare(strict_types=1);

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
use App\Util\DurationParser;
use App\Util\MarkdownRenderer;
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
 * Takes a Notion Markdown/CSV export of pedagogical séquences into a teacher's personal library
 * (App\Entity\SequenceTemplate and its séances/phases).
 *
 * The expected export is the one produced by « Exporter » on the Notion séquence database, in
 * Markdown & CSV format, with the sub-pages: an index CSV at the root, one .md per séquence, then
 * per séquence an « Organisation de la séquence » CSV and one .md per séance.
 *
 * Matching is done on titles - the export carries no identifier in common with the database -
 * ignoring apostrophes, since the export writes the typographic apostrophe where input made in the
 * application often carries the straight one.
 *
 * What the export contains and the library does not store is left aside: séance periods and dates
 * (a library séquence has no dates, by construction) and attachments (images, PDFs) - only the
 * texts are taken.
 */
#[AsCommand(
    name: 'app:import-notion-sequences',
    description: "Importe un export Notion de séquences pédagogiques dans la bibliothèque d'un enseignant.",
)]
class ImportNotionSequencesCommand extends Command
{
    private const string DEFAULT_RELATIVE_PATH = 'design/notion/Séquences pédagogiques 2025-2026';

    /** Known Notion properties: any other line of the header block continues the previous one. */
    private const array PROPERTY_LABELS = [
        'Bloc', 'Niveau', 'Option', 'Période', 'Séances', 'Date', 'Durée', 'Objectifs', 'Objectifs/Activité',
    ];

    /** Table headers of a séquence page → SequenceTemplate field, by normalised key. */
    private const array SEQUENCE_FIELDS = [
        'capacitesoucompetencesattendues' => 'setCapacitesAttendues',
        'prerequiscapacitesoucompetences' => 'setPreRequis',
        'objectifsdelasequence' => 'setObjectifs',
        'transversalites' => 'setTransversalites',
        'situationouproblematique' => 'setSituationProblematique',
        'supports' => 'setSupportsGeneraux',
    ];

    /** Row labels of a phase table → SeancePhaseTemplate field, by normalised key. */
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
     * Tags already resolved during the import, by class and label. App\Service\LibraryTagResolver
     * looks them up in the database: without this cache, nineteen séquences carrying the same Niveau
     * would create the tag nineteen times as long as nothing is saved, and the final flush would hit
     * the uniqueness constraint.
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

    /** @param array<string, string> $indexRow row of the index CSV, which carries the séquence's facets */
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
            // The export's « AP - … » pages have no séances: a project followed over the period,
            // described in prose and pictures, not the running order of a séquence.
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
        $seance->setDuree(DurationParser::minutes($properties['Durée'] ?? null));
        // « Objectifs » on most séquences, « Objectifs/Activité » on the ones where the teacher
        // renamed the property: it is the same Notion column.
        $objectifs = MarkdownRenderer::toPlainText($properties['Objectifs'] ?? ($properties['Objectifs/Activité'] ?? ''));
        $seance->setObjectifs('' === $objectifs ? null : $objectifs);
        $seance->setEvaluationNature($this->guessEvaluationNature($title));
        $seance->setAvantDescription($this->twoColumnText($sections['Avant la séance'] ?? ''));
        $seance->setApresDescription($this->twoColumnText($sections['Après la séance'] ?? ''));
        $seance->setCahierDeTexteDescription(MarkdownRenderer::toHtml($sections['Cahier de texte'] ?? ''));

        $sequence->getSeanceTemplates()->add($seance);
        $this->entityManager->persist($seance);

        $ordrePhase = 0;
        foreach ($this->readSections($sections['Déroulé de la séance'] ?? '', '###') as $nom => $body) {
            $phase = new SeancePhaseTemplate($seance);
            $phase->setNom($nom);
            $phase->setOrdre(++$ordrePhase);

            foreach ($this->readLabelledTable($body, self::PHASE_FIELDS + ['duree' => 'setDuree']) as $setter => $value) {
                $phase->{$setter}('setDuree' === $setter ? DurationParser::minutes($value) : $value);
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

    // A séance carries an evaluation nature when its title announces it - « Evaluation sommative
    // (BTS blanc) », « Evaluation diagnostique ». A séance that merely names an evaluation
    // (« Correction évaluation sommative », « Remédiation et préparation à l'évaluation
    // sommative ») is not one: hence the title having to *begin* with the word.
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
     * Header block of a Notion page: « Label: value », the value possibly running over several
     * lines up to the next property.
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

        return array_map(MarkdownRenderer::toPlainText(...), array_filter($properties, static fn (string $value): bool => '' !== trim($value)));
    }

    /**
     * Splits a Markdown document into sections of a given heading level: « title → body ».
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
     * Notion tables where each value is preceded by its label in bold - both the two-column table of
     * a séquence (the labels make one row, the values the next) and that of a phase (one label and
     * its value per row).
     *
     * @param array<string, string> $fields normalised label → setter name
     *
     * @return array<string, string> setter name → value
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

            // Mixed « **Label** | value » row (phases), or a row of values under its labels.
            if (1 === \count($rowLabels) && 2 === \count($cells)) {
                $labels = [$rowLabels[0]];
                $cells = [$cells[1]];
            }

            foreach ($cells as $index => $cell) {
                $field = $fields[$labels[$index] ?? ''] ?? null;
                if (null !== $field && '' !== ($text = MarkdownRenderer::toPlainText($cell))) {
                    $values[$field] = $text;
                }
            }
        }

        return $values;
    }

    /**
     * Rows of a Notion Markdown table. A cell may contain real line breaks: a table row starts with
     * « | » and runs up to the line that ends with « | ».
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
                continue; // separator row
            }
            if ([] !== $cells) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /**
     * « Avant / Après la séance » sections: a two-column titled table, rendered as plain text -
     * these séance fields are not rich text.
     */
    private function twoColumnText(string $markdown): ?string
    {
        $rows = $this->readTableRows($markdown);
        if (\count($rows) < 2) {
            return null;
        }

        $labels = array_map(fn (string $cell): string => trim(MarkdownRenderer::toPlainText($cell), '*'), array_shift($rows));
        $parts = [];
        foreach ($rows as $cells) {
            foreach ($cells as $index => $cell) {
                $value = MarkdownRenderer::toPlainText($cell);
                if ('' !== $value) {
                    $parts[] = trim(($labels[$index] ?? '')." :\n".$value);
                }
            }
        }

        return [] === $parts ? null : implode("\n\n", $parts);
    }

    /** Markdown → plain text: bold removed, links flattened, label followed by the URL if it differs. */
    /**
     * A Notion database CSV is named « <title> <hash>.csv »; its « _all » variant only adds the
     * columns hidden from the view, in a different order - the view is what we follow.
     */
    private function findDatabaseCsv(string $directory, string $title): ?string
    {
        // File names are compared in normalised form: macOS stores « é » decomposed where the same
        // string written in the code is composed, and the two are not equal.
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
     * Normalised title → page path, for every .md of a folder. The file name being truncated by
     * Notion, the title is read from the page's H1.
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

    /** Sub-page folder of a page or a database: its file name without the hash nor the extension. */
    private function pageDirectoryName(string $page): string
    {
        return preg_replace('/ [0-9a-f]{32}\.md$/', '', basename($page)) ?? basename($page);
    }

    private function databaseDirectoryName(string $csv): string
    {
        return preg_replace('/ [0-9a-f]{32}\.csv$/', '', basename($csv)) ?? basename($csv);
    }

    /**
     * Matching by title: the Notion export writes the typographic apostrophe where the same string
     * typed into the application often carries the straight one. Apostrophes are therefore stripped
     * on both sides rather than betting on either form.
     */
    private function normalize(string $value): string
    {
        return mb_strtolower(trim(str_replace(['’', '‘', "'"], '', preg_replace('/\s+/u', ' ', $value) ?? $value)));
    }

    /**
     * Key of a table label: accents, punctuation and spaces removed, the export writing the same
     * label in several ways from one page to the next (« Moyens/Supports », « Pré-requis :
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
     * Rows of a Notion CSV, indexed by header (BOM included).
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
