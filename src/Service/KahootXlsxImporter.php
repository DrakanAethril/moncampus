<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Turns a Kahoot game report (.xlsx) into the same *proposal* a CSV produces - see
 * App\Service\QuizCsvImporter, which does all the work from there on.
 *
 * This class only reshapes. A Kahoot report is a record of a game that was played, not a quiz
 * definition: its last worksheet holds one row per player per question, so the same question
 * repeats once for every participant. Collapsing that back to one row per question, and relabelling
 * the columns to the ones the CSV importer already understands, is the whole job - after which the
 * two imports share their validation, their preview and their creation step.
 *
 * Two mismatches the reshaping resolves:
 *  - Kahoot names its correct answers by their text ("centralisé"), the CSV format numbers them
 *    ("4"). The text is matched back against the answer columns it came from.
 *  - The report writes HTML entities into statements (&nbsp; between words), which have no business
 *    reaching a question label.
 */
class KahootXlsxImporter
{
    // Kahoot's own name for the sheet holding the full grid. The per-question tabs before it are
    // presentation, and only this one carries the answer texts and the correct ones together.
    private const string SHEET_NAME = 'RawReportData Data';

    private const string COLUMN_QUESTION_NUMBER = 'question number';
    private const string COLUMN_QUESTION = 'question';
    private const string COLUMN_CORRECT = 'correct answers';
    private const string COLUMN_TIME = 'time allotted to answer (seconds)';
    // Strictly "Answer 1".."Answer 6". The report also carries a bare "Answer" (what the player
    // picked) and two "Answer Time ..." columns, none of which is a proposition - a prefix match
    // would swallow all three and shift every option along.
    private const string ANSWER_PATTERN = '/^answer (\d+)$/';

    public function __construct(
        private readonly XlsxSheetReader $reader,
        private readonly QuizCsvImporter $importer,
    ) {
    }

    /**
     * @return array{name: string, subject: ?string, description: ?string, questions: list<array<string, mixed>>, errors: list<array<string, string|int>>, skipped: int, sequences: list<string>, seances: list<string>, referentiels: list<string>}
     *
     * @throws QuizCsvImportException
     */
    public function parse(UploadedFile $file): array
    {
        try {
            $rows = $this->reader->rows($file->getPathname(), self::SHEET_NAME);
        } catch (XlsxReadException $exception) {
            throw new QuizCsvImportException($exception->getMessageKey(), $exception->getParameters());
        }

        // The report carries no séquence/séance, so the shared name generator falls back to the
        // file name - which is what the teacher called the game. Nothing to override here.
        return $this->importer->parseRows($this->toCanonicalRows($rows), $file->getClientOriginalName());
    }

    /**
     * The Kahoot grid, collapsed and relabelled into the header + one-row-per-question shape
     * QuizCsvImporter::parseRows() expects.
     *
     * @param list<list<string>> $rows
     *
     * @return list<list<string|null>>
     */
    private function toCanonicalRows(array $rows): array
    {
        $header = array_shift($rows) ?? [];
        $columns = [];
        foreach ($header as $index => $name) {
            $columns[mb_strtolower(trim($name))] = $index;
        }

        if (!isset($columns[self::COLUMN_QUESTION])) {
            throw new QuizCsvImportException('quizImportKahootUnexpectedShapeMessage');
        }

        $answerColumns = [];
        foreach ($columns as $name => $index) {
            if (preg_match(self::ANSWER_PATTERN, $name, $matches)) {
                $answerColumns[(int) $matches[1]] = $index;
            }
        }
        ksort($answerColumns);
        $answerColumns = array_values($answerColumns);

        $questionColumn = $columns[self::COLUMN_QUESTION];
        $keyColumn = $columns[self::COLUMN_QUESTION_NUMBER] ?? $questionColumn;

        $canonical = [$this->canonicalHeader(\count($answerColumns))];
        $seen = [];

        foreach ($rows as $row) {
            // One row per player per question: the first occurrence carries everything the quiz
            // needs, the rest only differ by who answered and how fast.
            $key = trim((string) ($row[$keyColumn] ?? ''));
            if ('' === $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $answers = [];
            foreach ($answerColumns as $index) {
                $answers[] = $this->clean((string) ($row[$index] ?? ''));
            }

            $correct = $this->correctIndexes($this->clean((string) ($row[$columns[self::COLUMN_CORRECT] ?? -1] ?? '')), $answers);

            $canonical[] = [
                $this->clean((string) ($row[$questionColumn] ?? '')),
                ...$answers,
                $correct,
                // The report does not name a type; how many answers it marks correct does. Without
                // this every multiple-answer question would be refused as a single-answer QCM
                // carrying two right answers.
                str_contains($correct, ',') ? 'qcm_multi' : 'qcm',
                $this->seconds((string) ($row[$columns[self::COLUMN_TIME] ?? -1] ?? '')),
            ];
        }

        return $canonical;
    }

    /** @return list<string> */
    private function canonicalHeader(int $answerCount): array
    {
        $header = ['enonce'];
        for ($i = 1; $i <= $answerCount; ++$i) {
            $header[] = 'reponse_'.$i;
        }

        return [...$header, 'bonnes', 'type', 'temps'];
    }

    /**
     * Kahoot names its correct answers, the CSV format numbers them: "Recursive DNS Server, Cache
     * DNS Server" against the answer columns gives "1,3".
     *
     * @param list<string> $answers
     */
    private function correctIndexes(string $correct, array $answers): string
    {
        if ('' === $correct) {
            return '';
        }

        $indexes = [];
        // Comma-separated, and a multiple-answer question is the only case with more than one.
        // Compared loosely so a stray double space or a trailing dot does not lose the match.
        foreach (explode(',', $correct) as $label) {
            $needle = $this->comparable($label);
            foreach ($answers as $index => $answer) {
                if ('' !== $needle && $this->comparable($answer) === $needle) {
                    $indexes[] = $index + 1;
                }
            }
        }

        sort($indexes);

        return implode(',', array_unique($indexes));
    }

    /** Kahoot writes "20.0" where the importer wants "20"; a blank means the quiz's own default. */
    private function seconds(string $raw): string
    {
        $seconds = (int) round((float) str_replace(',', '.', trim($raw)));

        return $seconds > 0 ? (string) $seconds : '';
    }

    /**
     * The report is HTML-ish: statements come through with entities in them (&nbsp; between words),
     * and the odd tag. Neither belongs in a question label.
     */
    private function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // html_entity_decode turns &nbsp; into a non-breaking space, which looks like a space and
        // is not one - it would survive every trim and comparison downstream.
        $value = str_replace("\u{00A0}", ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function comparable(string $value): string
    {
        return mb_strtolower(trim($this->clean($value)));
    }
}
