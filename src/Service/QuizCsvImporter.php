<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Enum\BlankMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionTimeMode;
use App\Enum\QuestionType;
use App\Util\BlankTextParser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns an uploaded CSV into a *proposal* for a new QuizTemplate - the "Importer un CSV" entry of
 * the quiz library (App\Controller\QuizImportController). parse() never touches Doctrine: it
 * returns a plain array that the controller parks in the session and renders as a preview, and
 * only appendQuestions() - called after the teacher confirms - builds entities. That split is the
 * whole point of the feature: nothing is written until the confirmation form is submitted.
 *
 * The column set is the one design/generations/quiz produces (see that folder's README.md), so
 * those files import as-is; the reader is deliberately more tolerant than they need, since the
 * other source of these files is a teacher's spreadsheet export: the delimiter is detected, a BOM
 * and a Windows-1252 encoding are recovered from, headers are matched accent- and case-insensitively,
 * and a handful of column/type spellings are aliased.
 *
 * A row the reader cannot make sense of is *skipped and reported*, not fatal - one malformed line
 * in a 48-question file must not cost the teacher the other 47. Only a file with no usable row at
 * all (or no `enonce` column) raises QuizCsvImportException.
 *
 * `blankMode` and `blanks` stay on the shape for every type, not just texte à trous: parseRow()
 * seeds them empty for all of them and only parseBlanksRow() fills them in.
 *
 * There are two shapes because appendQuestions() does not receive what parse() returned: its payload
 * comes back out of the session (see App\Controller\QuizImportController::confirm()), so neither key
 * order nor the optional keys survive the round trip. That is what the array_values() and the `??`
 * defaults in there are for - they are normalisation, not redundancy.
 *
 * @phpstan-type QuizImportQuestion array{
 *     type: string,
 *     difficulty: ?string,
 *     label: string,
 *     explanation: ?string,
 *     points: float,
 *     blankMode: ?string,
 *     timeMode: string,
 *     timeSeconds: ?int,
 *     answers: list<array{label: string, correct: bool}>,
 *     blanks: list<list<string>>,
 * }
 * @phpstan-type QuizImportQuestionFromSession array{
 *     type: string,
 *     difficulty: ?string,
 *     label: string,
 *     explanation: ?string,
 *     points: float,
 *     blankMode?: ?string,
 *     timeMode?: ?string,
 *     timeSeconds?: ?int,
 *     answers: array<array-key, array{label: string, correct: bool}>,
 *     blanks: array<array-key, list<string>>,
 * }
 */
final class QuizCsvImporter
{
    // A quiz bank far past what a teacher hand-builds; the guard exists so a wrong file (a full
    // export, a CSV of something else entirely) is refused before it becomes a session payload.
    public const int MAX_QUESTIONS = 500;

    private const array DELIMITERS = [';', ',', "\t", '|'];

    // Deliberately not iconv('ASCII//TRANSLIT'), whose output depends on the runtime's locale -
    // header matching must behave the same in the container, in CI and on a dev Mac.
    private const array ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ÿ' => 'y', 'ñ' => 'n',
    ];

    /** Normalized header spelling => canonical column name. */
    private const array COLUMN_ALIASES = [
        'sequence' => 'sequence',
        'seance' => 'seance',
        'cours' => 'seance',
        'referentiel' => 'referentiel',
        'competence' => 'referentiel',
        'bloc' => 'referentiel',
        'type' => 'type',
        'difficulte' => 'difficulte',
        'niveau' => 'difficulte',
        'enonce' => 'enonce',
        'question' => 'enonce',
        'intitule' => 'enonce',
        'bonnes' => 'bonnes',
        'bonne' => 'bonnes',
        'bonnes_reponses' => 'bonnes',
        'bonne_reponse' => 'bonnes',
        'reponses_correctes' => 'bonnes',
        'points' => 'points',
        'bareme' => 'points',
        'explication' => 'explication',
        'correction' => 'explication',
        'mode' => 'mode',
        'temps' => 'temps',
        'duree' => 'temps',
        'secondes' => 'temps',
        'time' => 'temps',
    ];

    private const array TYPE_ALIASES = [
        'multi' => 'qcm_multi',
        'qcm_multiple' => 'qcm_multi',
        'choix_multiple' => 'qcm_multi',
        'vf' => 'vrai_faux',
        'vraifaux' => 'vrai_faux',
        'vrai_ou_faux' => 'vrai_faux',
        'trous' => 'texte_a_trous',
        'texte_trous' => 'texte_a_trous',
        'texte_a_trou' => 'texte_a_trous',
        'remise_en_ordre' => 'ordre',
    ];

    private const array DIFFICULTY_ALIASES = [
        'moyenne' => 'moyen',
        'medium' => 'moyen',
        'dur' => 'difficile',
        'difficulte_elevee' => 'difficile',
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @return array{
     *     fileName: string,
     *     name: string,
     *     subject: string|null,
     *     description: string,
     *     questions: list<QuizImportQuestion>,
     *     errors: list<string>,
     * }
     *
     * @throws QuizCsvImportException when the file carries no usable question at all
     */
    public function parse(UploadedFile $file): array
    {
        return $this->parseRows($this->readRows($file), $file->getClientOriginalName());
    }

    /**
     * The format-agnostic half: a header row followed by one row per question, whatever produced
     * them. App\Service\KahootXlsxImporter reshapes a Kahoot report into exactly this and hands it
     * over, so the two imports share every rule below and differ only in how the file is read.
     *
     * @param list<list<string|null>> $rows
     * @param string                    $fileName the uploaded file's own name - all a generated
     *        quiz name has to go on when the rows carry no séquence/séance of their own
     *
     * @throws QuizCsvImportException
     */
    public function parseRows(array $rows, string $fileName = ''): array
    {
        $header = array_shift($rows) ?? throw new QuizCsvImportException('quizImportErrorEmptyFileMessage');
        $columns = $this->mapColumns($header);
        $answerColumns = $this->mapAnswerColumns($header);

        if (!isset($columns['enonce'])) {
            throw new QuizCsvImportException('quizImportErrorMissingEnonceColumnMessage');
        }

        $questions = [];
        $errors = [];
        $sequences = [];
        $seances = [];
        $referentiels = [];
        // The header is line 1 for the teacher, who will look for the bad line in a spreadsheet.
        $line = 1;

        foreach ($rows as $row) {
            ++$line;
            if ($this->isBlankRow($row)) {
                continue;
            }

            $question = $this->parseRow($row, $columns, $answerColumns, $line, $errors);
            if (null === $question) {
                continue;
            }

            $questions[] = $question;
            $this->collect($sequences, $this->cell($row, $columns, 'sequence'));
            $this->collect($seances, $this->cell($row, $columns, 'seance'));
            $this->collect($referentiels, $this->cell($row, $columns, 'referentiel'));
        }

        if ([] === $questions) {
            throw new QuizCsvImportException('quizImportErrorNoValidRowMessage');
        }

        if (\count($questions) > self::MAX_QUESTIONS) {
            throw new QuizCsvImportException('quizImportErrorTooManyQuestionsMessage', ['%max%' => self::MAX_QUESTIONS]);
        }

        return [
            'fileName' => $fileName,
            'name' => $this->generateName($sequences, $seances, $fileName),
            'subject' => [] === $referentiels ? null : mb_substr(implode(' · ', $referentiels), 0, 255),
            'description' => $this->generateDescription($fileName, \count($questions), $seances),
            'questions' => $questions,
            'errors' => $errors,
        ];
    }

    /**
     * Appends a parse() payload's questions to a template, at the end of whatever bank it already
     * has. Called once, on confirmation - see the class docblock.
     *
     * @param list<QuizImportQuestionFromSession> $questions the `questions` key of a parse() payload
     */
    public function appendQuestions(QuizTemplate $template, array $questions): void
    {
        // Same 1-based convention as QuizLibraryController::questionNew().
        $orderIndex = $template->getQuestions()->count() + 1;

        foreach ($questions as $data) {
            $question = new QuizQuestion($template);
            $question->setType(QuestionType::from((string) $data['type']));
            $question->setDifficulty(null !== $data['difficulty'] ? QuestionDifficulty::from((string) $data['difficulty']) : null);
            $question->setLabel((string) $data['label']);
            $question->setExplanation(null !== $data['explanation'] ? (string) $data['explanation'] : null);
            $question->setOrderIndex($orderIndex++);
            $question->setTimeMode(QuestionTimeMode::tryFrom((string) ($data['timeMode'] ?? '')) ?? QuestionTimeMode::Quiz);
            $question->setTimeSeconds(null !== ($data['timeSeconds'] ?? null) ? (int) $data['timeSeconds'] : null);

            if (QuestionType::TexteATrous === $question->getType()) {
                // Only a texte à trous reads $points (App\Service\QuizAttemptConcluder scores every
                // other type 1 point flat), so the CSV's difficulty-indexed barème is deliberately
                // not written onto the other types - it would show a worth the app never honours.
                $question->setPoints((float) $data['points']);
                $question->setBlankMode(BlankMode::tryFrom((string) ($data['blankMode'] ?? '')) ?? BlankMode::Banque);
                $question->setBlankAnswers($data['blanks']);
            }

            foreach (array_values($data['answers']) as $index => $answer) {
                $row = new QuizAnswer($question);
                $row->setLabel((string) $answer['label']);
                $row->setIsCorrect((bool) $answer['correct']);
                $row->setOrderIndex($index);
                $question->addAnswer($row);
            }

            $template->addQuestion($question);
        }
    }

    /**
     * @param array<string, int> $columns
     * @param list<int>          $answerColumns
     * @param list<string>       $errors        appended to, one entry per rejected row
     *
     * @return QuizImportQuestion|null null when the row was rejected
     */
    private function parseRow(array $row, array $columns, array $answerColumns, int $line, array &$errors): ?array
    {
        $label = $this->cell($row, $columns, 'enonce');
        if ('' === $label) {
            $errors[] = $this->error('quizImportRowErrorEmptyLabelTemplate', $line);

            return null;
        }

        $rawType = $this->normalize($this->cell($row, $columns, 'type'));
        $type = '' === $rawType
            ? QuestionType::Qcm
            : QuestionType::tryFrom(self::TYPE_ALIASES[$rawType] ?? $rawType);
        if (null === $type) {
            $errors[] = $this->error('quizImportRowErrorUnknownTypeTemplate', $line, ['%value%' => $this->cell($row, $columns, 'type')]);

            return null;
        }

        $rawDifficulty = $this->normalize($this->cell($row, $columns, 'difficulte'));
        $difficulty = null;
        if ('' !== $rawDifficulty) {
            $difficulty = QuestionDifficulty::tryFrom(self::DIFFICULTY_ALIASES[$rawDifficulty] ?? $rawDifficulty);
            if (null === $difficulty) {
                $errors[] = $this->error('quizImportRowErrorUnknownDifficultyTemplate', $line, ['%value%' => $this->cell($row, $columns, 'difficulte')]);

                return null;
            }
        }

        // Empty option columns are dropped rather than kept as blanks, so "bonnes" indexes the
        // options as the teacher sees them listed - same rule as design/generations/quiz/validate.php.
        $options = [];
        foreach ($answerColumns as $index) {
            $option = trim((string) ($row[$index] ?? ''));
            if ('' !== $option) {
                $options[] = $option;
            }
        }

        $explanation = $this->cell($row, $columns, 'explication');
        [$timeMode, $timeSeconds] = $this->parseTime($this->cell($row, $columns, 'temps'));
        $question = [
            'type' => $type->value,
            'difficulty' => $difficulty?->value,
            'label' => $label,
            'explanation' => '' === $explanation ? null : $explanation,
            'points' => max(0.25, (float) str_replace(',', '.', $this->cell($row, $columns, 'points') ?: '1')),
            'blankMode' => null,
            'timeMode' => $timeMode->value,
            'timeSeconds' => $timeSeconds,
            'answers' => [],
            'blanks' => [],
        ];

        if (QuestionType::TexteATrous === $type) {
            return $this->parseBlanksRow($question, $row, $columns, $options, $line, $errors);
        }

        if (\count($options) < 2) {
            $errors[] = $this->error('quizImportRowErrorNotEnoughOptionsTemplate', $line);

            return null;
        }

        $correctIndexes = $this->parseCorrectIndexes($this->cell($row, $columns, 'bonnes'));
        if ([] === $correctIndexes) {
            $errors[] = $this->error('quizImportRowErrorMissingCorrectTemplate', $line);

            return null;
        }

        foreach ($correctIndexes as $index) {
            if ($index < 1 || $index > \count($options)) {
                $errors[] = $this->error('quizImportRowErrorCorrectOutOfRangeTemplate', $line, ['%value%' => $index, '%count%' => \count($options)]);

                return null;
            }
        }

        if (QuestionType::VraiFaux === $type && 2 !== \count($options)) {
            $errors[] = $this->error('quizImportRowErrorTrueFalseShapeTemplate', $line);

            return null;
        }

        if (\in_array($type, [QuestionType::Qcm, QuestionType::VraiFaux, QuestionType::Image], true) && 1 !== \count($correctIndexes)) {
            $errors[] = $this->error('quizImportRowErrorSingleCorrectExpectedTemplate', $line);

            return null;
        }

        if (QuestionType::Ordre === $type) {
            if (\count($correctIndexes) !== \count($options)) {
                $errors[] = $this->error('quizImportRowErrorOrderIncompleteTemplate', $line, ['%count%' => \count($options)]);

                return null;
            }

            // An "ordre" question stores its options *in the expected sequence* (order index ASC is
            // the answer - see QuizLibraryController::isTestAnswerCorrectOrder), while the CSV lists
            // them shuffled and spells the sequence out in "bonnes". Reorder here, once.
            $question['answers'] = array_map(
                static fn (int $index): array => ['label' => $options[$index - 1], 'correct' => false],
                $correctIndexes,
            );

            return $question;
        }

        $question['answers'] = array_map(
            static fn (int $position, string $option): array => ['label' => $option, 'correct' => \in_array($position + 1, $correctIndexes, true)],
            array_keys($options),
            $options,
        );

        return $question;
    }

    /**
     * @param QuizImportQuestion $question
     * @param array<string, int> $columns
     * @param list<string>         $options  one "variante|variante" cell per blank
     * @param list<string>         $errors
     *
     * @return QuizImportQuestion|null
     */
    private function parseBlanksRow(array $question, array $row, array $columns, array $options, int $line, array &$errors): ?array
    {
        $blankCount = BlankTextParser::countBlanks((string) $question['label']);
        if (0 === $blankCount) {
            $errors[] = $this->error('quizImportRowErrorNoBlankTemplate', $line);

            return null;
        }

        if ($blankCount !== \count($options)) {
            $errors[] = $this->error('quizImportRowErrorBlankCountTemplate', $line, ['%blanks%' => $blankCount, '%answers%' => \count($options)]);

            return null;
        }

        $blanks = [];
        foreach ($options as $option) {
            $variants = array_values(array_filter(
                array_map(trim(...), explode('|', $option)),
                static fn (string $variant): bool => '' !== $variant,
            ));
            if ([] === $variants) {
                $errors[] = $this->error('quizImportRowErrorEmptyBlankAnswerTemplate', $line);

                return null;
            }
            $blanks[] = $variants;
        }

        $mode = $this->normalize($this->cell($row, $columns, 'mode'));
        $question['blankMode'] = (BlankMode::tryFrom($mode) ?? BlankMode::Banque)->value;
        $question['blanks'] = $blanks;

        return $question;
    }

    /** @return list<int> the 1-based option numbers listed in "bonnes", in the order written */
    private function parseCorrectIndexes(string $value): array
    {
        $indexes = [];
        foreach (preg_split('/[,;\s]+/', $value) ?: [] as $part) {
            if (ctype_digit($part)) {
                $index = (int) $part;
                if (!\in_array($index, $indexes, true)) {
                    $indexes[] = $index;
                }
            }
        }

        return $indexes;
    }

    /**
     * Reads the whole file into rows, recovering from the two things a spreadsheet export routinely
     * gets wrong: a UTF-8 BOM and a Windows-1252 encoding (the accents would otherwise land in the
     * database as mojibake, and MySQL would reject them outright).
     *
     * @return list<list<string|null>>
     */
    private function readRows(UploadedFile $file): array
    {
        $content = @file_get_contents($file->getPathname());
        if (false === $content || '' === trim($content)) {
            throw new QuizCsvImportException('quizImportErrorEmptyFileMessage');
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new QuizCsvImportException('quizImportErrorUnreadableMessage');
        }

        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        $delimiter = $this->detectDelimiter($content);
        // Escape character explicitly disabled: a backslash inside a statement (a Windows path, a
        // regex) is content, not an escape, and only doubled quotes delimit-escape a quote here.
        while (false !== ($row = fgetcsv($stream, 0, $delimiter, '"', ''))) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    private function detectDelimiter(string $content): string
    {
        $firstLine = preg_split('/\r\n|\r|\n/', $content, 2)[0] ?? '';
        $best = ';';
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = substr_count($firstLine, $delimiter);
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @param list<string|null> $header
     *
     * @return array<string, int> canonical column name => position in a row
     */
    private function mapColumns(array $header): array
    {
        $columns = [];
        foreach ($header as $index => $name) {
            $canonical = self::COLUMN_ALIASES[$this->normalize((string) $name)] ?? null;
            if (null !== $canonical && !isset($columns[$canonical])) {
                $columns[$canonical] = $index;
            }
        }

        return $columns;
    }

    /**
     * Positions of the option columns (`reponse_1`, `reponse 2`, `proposition3`…), in the order
     * their number gives - so a file listing them out of order still reads left to right.
     *
     * @param list<string|null> $header
     *
     * @return list<int>
     */
    private function mapAnswerColumns(array $header): array
    {
        $positions = [];
        foreach ($header as $index => $name) {
            if (preg_match('/^(?:reponse|proposition|choix)_?(\d+)$/', $this->normalize((string) $name), $matches)) {
                $positions[(int) $matches[1]] = $index;
            }
        }
        ksort($positions);

        return array_values($positions);
    }

    /**
     * The optional "temps" column: blank follows the quiz, a word spelling "illimité" lifts the
     * limit, a number sets it. Anything else is treated as blank rather than refused - the column
     * is a convenience, not a reason to lose a question.
     *
     * @return array{0: QuestionTimeMode, 1: int|null}
     */
    private function parseTime(string $raw): array
    {
        $value = $this->normalize($raw);

        if ('' === $value) {
            return [QuestionTimeMode::Quiz, null];
        }

        if (\in_array($value, ['illimite', 'unlimited', 'sans_limite', 'aucun', 'none'], true)) {
            return [QuestionTimeMode::Unlimited, null];
        }

        $seconds = (int) filter_var($raw, \FILTER_SANITIZE_NUMBER_INT);

        return $seconds > 0 ? [QuestionTimeMode::Fixed, $seconds] : [QuestionTimeMode::Quiz, null];
    }

    /** @param array<string, int> $columns */
    private function cell(array $row, array $columns, string $key): string
    {
        $index = $columns[$key] ?? null;

        return null === $index ? '' : trim((string) ($row[$index] ?? ''));
    }

    /** @param list<string|null> $row */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if ('' !== trim((string) $value)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $values */
    private function collect(array &$values, string $value): void
    {
        if ('' !== $value && !\in_array($value, $values, true)) {
            $values[] = $value;
        }
    }

    /** @param array<string, string|int> $parameters */
    private function error(string $key, int $line, array $parameters = []): string
    {
        return $this->translator->trans($key, ['%line%' => $line] + $parameters);
    }

    /**
     * The proposed quiz name, read off the content the way a teacher would name it: "séquence —
     * séance" for a single séance's bank, the séquence alone when the file covers several of its
     * séances, and the file name as a last resort.
     *
     * @param list<string> $sequences
     * @param list<string> $seances
     */
    private function generateName(array $sequences, array $seances, string $fileName): string
    {
        $name = match (true) {
            1 === \count($sequences) && 1 === \count($seances) => \sprintf('%s — %s', $sequences[0], $seances[0]),
            1 === \count($sequences) => $sequences[0],
            [] === $sequences && 1 === \count($seances) => $seances[0],
            default => $this->humanizeFileName($fileName),
        };

        return mb_substr($name, 0, 255);
    }

    /** @param list<string> $seances */
    private function generateDescription(string $fileName, int $questionCount, array $seances): string
    {
        $description = $this->translator->trans('quizImportGeneratedDescriptionTemplate', [
            '%file%' => $fileName,
            '%date%' => (new \DateTimeImmutable())->format('d/m/Y'),
            '%count%' => $questionCount,
        ]);

        if (\count($seances) > 1) {
            $listed = \array_slice($seances, 0, 6);
            $description .= ' '.$this->translator->trans('quizImportGeneratedDescriptionSeancesTemplate', [
                '%seances%' => implode(', ', $listed).(\count($seances) > \count($listed) ? '…' : ''),
            ]);
        }

        return $description;
    }

    private function humanizeFileName(string $fileName): string
    {
        $name = trim((string) preg_replace('/[\s_-]+/', ' ', pathinfo($fileName, \PATHINFO_FILENAME)));

        return '' === $name
            ? $this->translator->trans('quizImportDefaultNameLabel')
            : mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }

    private function normalize(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), self::ACCENTS);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $value), '_');
    }
}
