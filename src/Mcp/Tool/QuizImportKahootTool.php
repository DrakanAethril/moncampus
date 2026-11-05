<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Enum\Feature;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\QuizTemplateRepository;
use App\Service\FileUploadService;
use App\Service\Kahoot\KahootNote;
use App\Service\Kahoot\KahootReport;
use App\Service\Kahoot\KahootReportReader;
use App\Service\MixedJsonImporter;
use App\Service\QuizCsvImportException;
use App\Service\QuizTemplateImportWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Imports Kahoot game reports into the teacher's quiz library: one report (.xlsx) or a .zip of
 * them, deposited first in the file library (`file_upload` / `file_upload_url`) - the platform never
 * fetches a file. Each report becomes one quiz, through the very reader of the import screen
 * (App\Service\Kahoot\KahootReportReader) and the very writer of every quiz import
 * (App\Service\QuizTemplateImportWriter).
 *
 * The whole batch is read before anything is written, and a file that is not a usable report
 * refuses the batch - nothing is half-imported. What is *not* a refusal: a question type Formatool
 * does not play, left out and named; a slider's margin, guessed from the game and named; a report
 * already in the batch (a game exported twice) or a quiz of the same name already in the target
 * folder, skipped and named - this tool never makes a duplicate and never replaces anything.
 *
 * `dryRun` answers the same reading without writing - what the teacher should be shown first.
 */
final readonly class QuizImportKahootTool implements McpTool
{
    /** A report weighs a hundred kilobytes; a few dozen in a zip is a whole course. */
    private const int MAX_BYTES = 30 * 1024 * 1024;
    private const int MAX_REPORTS = 100;

    public function __construct(
        private McpLibraryAccess $library,
        private FileUploadService $uploads,
        private KahootReportReader $reader,
        private MixedJsonImporter $importer,
        private QuizTemplateImportWriter $writer,
        private QuizTemplateRepository $quizzes,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
        private TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return 'quiz_import_kahoot';
    }

    public function title(): string
    {
        return 'Importer des Kahoot';
    }

    public function description(): string
    {
        return 'Importe des rapports de partie Kahoot (.xlsx exporté depuis Kahoot, « Télécharger le rapport ») dans la bibliothèque de quiz : un quiz par rapport. Le fichier doit d\'abord être déposé dans la bibliothèque de fichiers (file_upload, ou file_upload_url pour un .zip de plusieurs rapports) ; passer son fileId. Commencer par dryRun: true et montrer le résultat à l\'enseignant : nombre de questions par type, questions laissées de côté, tolérances de curseur déduites de la partie, doublons. Tous les types Kahoot joués en concours live sont repris (quiz, choix multiples, vrai/faux, puzzle, curseur, réponse tapée, sondage). Les rapports ne contiennent pas les images. Un quiz du même nom déjà présent dans le dossier n\'est pas réimporté. Rien n\'est lancé auprès d\'une classe.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fileId' => ['type' => 'integer', 'description' => 'Le rapport .xlsx, ou un .zip de rapports, déposé dans la bibliothèque de fichiers.'],
                'folderId' => ['type' => 'integer', 'description' => 'Dossier de quiz où ranger les quiz créés (voir library_list). Absent : à la racine.'],
                'dryRun' => ['type' => 'boolean', 'description' => 'true : lit et rend compte sans rien créer.'],
            ],
            'required' => ['fileId'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::QuizLibrary, Feature::FileLibrary];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $node = $this->library->file($call->requiredId('fileId'));
        $fileName = $node->getOriginalName() ?? (string) $node->getName();
        $extension = mb_strtolower(pathinfo($fileName, \PATHINFO_EXTENSION));
        if (!\in_array($extension, ['xlsx', 'zip'], true)) {
            throw new McpToolException(\sprintf('« %s » n\'est ni un rapport Kahoot (.xlsx) ni une archive .zip de rapports.', $fileName));
        }

        $key = (string) $node->getStorageKey();
        if ('' === $key || $this->uploads->size($key) > self::MAX_BYTES) {
            throw new McpToolException(\sprintf('« %s » est vide ou trop volumineux (30 Mo au plus).', $fileName));
        }

        $folder = $this->library->quizFolder($call->optionalId('folderId'));
        $dryRun = $call->arguments->bool('dryRun');

        $workspace = sys_get_temp_dir().'/mcp-kahoot-'.bin2hex(random_bytes(8));
        if (!mkdir($workspace) && !is_dir($workspace)) {
            throw new \RuntimeException('Could not create a temporary directory.');
        }

        try {
            $source = $workspace.'/source.'.$extension;
            file_put_contents($source, $this->uploads->read($key));
            $files = 'zip' === $extension ? $this->unzip($source, $workspace) : [$fileName => $source];

            return $this->import($files, $folder, $dryRun, $call);
        } finally {
            $this->remove($workspace);
        }
    }

    /**
     * @param array<string, string> $files display name => path on disk
     */
    private function import(array $files, ?QuizFolder $folder, bool $dryRun, McpToolCall $call): McpToolResult
    {
        // Read everything first: a refusal must leave the library untouched.
        $reports = [];
        $refusals = [];
        foreach ($files as $name => $path) {
            try {
                $report = $this->reader->read($path, $name);
                if ([] === ($report->document['questions'] ?? [])) {
                    throw new QuizCsvImportException('kahootImportNoQuestionError');
                }
                $payload = $this->importer->parse((string) json_encode($report->document, \JSON_UNESCAPED_UNICODE), $name);
                if ([] !== $payload['errors']) {
                    $refusals[] = \sprintf('%s : %s', $name, implode(' ', $payload['errors']));
                    continue;
                }
                $reports[] = ['file' => $name, 'report' => $report, 'payload' => $payload];
            } catch (QuizCsvImportException $exception) {
                $refusals[] = \sprintf('%s : %s', $name, $this->translator->trans($exception->getMessageKey(), $exception->getParameters()));
            }
        }

        if ([] !== $refusals) {
            throw new McpToolException('Import refusé ; rien n\'a été créé. Ces fichiers ne sont pas des rapports Kahoot utilisables (retirez-les du lot ou exportez-les à nouveau depuis Kahoot) :', $refusals);
        }

        $existing = [];
        foreach ($this->quizzes->findForTeacher($call->user) as $quiz) {
            if ($quiz->getFolder() === $folder) {
                $existing[mb_strtolower(trim((string) $quiz->getName()))] = $quiz;
            }
        }

        $lines = [];
        $created = [];
        $seen = [];
        foreach ($reports as $entry) {
            /** @var KahootReport $report */
            $report = $entry['report'];
            $line = [
                'file' => $entry['file'],
                'name' => $report->title,
                'playedOn' => $report->playedOn,
                'questions' => $report->questionCount(),
                'types' => $report->typeCounts,
                'notes' => array_map($this->reader->describe(...), $report->notes),
            ];

            $fingerprint = $report->fingerprint();
            $sameName = $existing[mb_strtolower(trim($report->title))] ?? null;
            if (isset($seen[$fingerprint])) {
                $line['skipped'] = \sprintf('Même quiz que « %s » dans ce lot : non importé une seconde fois.', $seen[$fingerprint]);
            } elseif ($sameName instanceof QuizTemplate) {
                $line['skipped'] = 'Un quiz de ce nom existe déjà dans ce dossier : non réimporté.';
                $line['url'] = $this->links->quiz($sameName);
            } elseif (!$dryRun) {
                $payload = $entry['payload'];
                $quiz = $this->writer->newTemplate($call->user, $folder, $report->title, null, $payload['description'], \count($payload['questions']));
                $this->writer->fill($quiz, $this->importer, $payload['questions'], null);
                $this->entityManager->persist($quiz);
                $created[] = ['quiz' => $quiz, 'line' => \count($lines)];
            }
            $seen[$fingerprint] ??= $entry['file'];
            $lines[] = $line;
        }

        if (!$dryRun && [] !== $created) {
            $this->entityManager->flush();
            foreach ($created as $one) {
                $lines[$one['line']]['id'] = $one['quiz']->getId();
                $lines[$one['line']]['url'] = $this->links->quiz($one['quiz']);
            }
        }

        $dropped = 0;
        foreach ($reports as $entry) {
            $dropped += \count(array_filter($entry['report']->notes, static fn (KahootNote $note): bool => $note->dropsQuestion()));
        }

        $summary = $dryRun
            ? \sprintf('Simulation : %d rapport(s) lu(s), %d quiz seraient créés. Rien n\'a été créé.', \count($reports), \count(array_filter($lines, static fn (array $line): bool => !isset($line['skipped']))))
            : \sprintf('%d quiz créé(s) sur %d rapport(s).', \count($created), \count($reports));
        if ($dropped > 0) {
            $summary .= \sprintf(' %d question(s) laissée(s) de côté, détail dans « notes ».', $dropped);
        }

        return McpToolResult::data(
            $summary,
            ['dryRun' => $dryRun, 'folderUrl' => null !== $folder ? $this->links->quizFolder($folder) : null, 'reports' => $lines],
            1 === \count($created) ? ['kind' => 'quiz', 'id' => (int) $created[0]['quiz']->getId()] : null,
        );
    }

    /**
     * The reports of an archive, each written to its own file. Anything else in it - a folder, the
     * macOS litter, a stray document - is ignored rather than refused: what is asked for is the
     * reports it holds.
     *
     * @return array<string, string> display name => path
     */
    private function unzip(string $archive, string $workspace): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($archive)) {
            throw new McpToolException('Cette archive .zip ne s\'ouvre pas.');
        }

        try {
            $files = [];
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                $name = \is_array($stat) ? $stat['name'] : '';
                $base = basename($name);
                if ('' === $base || str_starts_with($name, '__MACOSX/') || str_starts_with($base, '.') || 'xlsx' !== mb_strtolower(pathinfo($base, \PATHINFO_EXTENSION))) {
                    continue;
                }
                if (\count($files) >= self::MAX_REPORTS) {
                    throw new McpToolException(\sprintf('Cette archive contient plus de %d rapports : importez-la en plusieurs fois.', self::MAX_REPORTS));
                }
                if ((\is_array($stat) ? $stat['size'] : 0) > self::MAX_BYTES) {
                    continue;
                }

                $bytes = $zip->getFromIndex($index);
                if (false === $bytes) {
                    continue;
                }
                $path = \sprintf('%s/%03d.xlsx', $workspace, $index);
                file_put_contents($path, $bytes);
                // The name is only shown: an archive made on a Mac often stores it in an encoding
                // that is not UTF-8, and the quiz takes its name from the report itself anyway.
                $shown = mb_check_encoding($base, 'UTF-8') ? $base : (string) mb_convert_encoding($base, 'UTF-8', 'CP437');
                $files[isset($files[$shown]) ? \sprintf('%s (%d)', $shown, $index) : $shown] = $path;
            }
        } finally {
            $zip->close();
        }

        if ([] === $files) {
            throw new McpToolException('Cette archive ne contient aucun rapport Kahoot (.xlsx).');
        }

        return $files;
    }

    private function remove(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
}
