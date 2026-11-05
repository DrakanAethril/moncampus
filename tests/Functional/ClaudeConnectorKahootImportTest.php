<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuestionTimeMode;
use App\Enum\QuestionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Kahoot reports imported through the Claude connector (quiz_import_kahoot): deposited in the file
 * library first, one or a .zip of several, simulated, then imported - one quiz per report, a game
 * exported twice imported once, a quiz already there never imported again, a stray spreadsheet
 * refusing the whole batch. The reports are built here, cell by cell.
 */
class ClaudeConnectorKahootImportTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;

    public function testAZipOfReportsIsSimulatedThenImportedOnce(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.kahoot');
        $token = $this->accessTokenFor($teacher);

        $report = $this->report();
        $fileId = $this->upload($token, 'kahoot.zip', $this->zip([
            'CEJM - Révision Thème 4.xlsx' => $report,
            'CEJM - Révision Thème 4 (1).xlsx' => $report,
            '__MACOSX/._CEJM - Révision Thème 4.xlsx' => 'litter',
        ]));

        $simulated = $this->callTool($token, 'quiz_import_kahoot', ['fileId' => $fileId, 'dryRun' => true]);
        self::assertFalse($simulated['isError'], $simulated['text']);
        self::assertStringContainsString('Rien n\'a été créé', $simulated['text']);
        $first = $this->line($simulated['data'], 0);
        self::assertSame(['qcm_multi' => 1, 'numerique' => 1, 'vote' => 1], $first['types']);
        self::assertStringContainsString('± 400', (string) json_encode($first['notes'], \JSON_UNESCAPED_UNICODE), 'the guessed margin is said');
        self::assertStringContainsString('Même quiz', $this->skipped($simulated['data'], 1));
        self::assertSame([], $this->quizzes($teacher));

        $imported = $this->callTool($token, 'quiz_import_kahoot', ['fileId' => $fileId]);
        self::assertFalse($imported['isError'], $imported['text']);
        $quizzes = $this->quizzes($teacher);
        self::assertCount(1, $quizzes, 'a game exported twice is imported once');
        $quiz = $quizzes[0];
        self::assertSame('CEJM - Révision Thème 4', $quiz->getName());
        $questions = $quiz->getQuestions()->toArray();
        self::assertSame([QuestionType::QcmMulti, QuestionType::Numerique, QuestionType::Vote], array_map(static fn ($question) => $question->getType(), $questions));
        self::assertSame([QuestionTimeMode::Fixed, 30], [$questions[0]->getTimeMode(), $questions[0]->getTimeSeconds()]);
        self::assertSame(400.0, $questions[1]->getNumericTolerance());
        self::assertSame(['min' => 0.0, 'max' => 4000.0, 'step' => 1.0], $questions[1]->getNumericRange());

        $again = $this->callTool($token, 'quiz_import_kahoot', ['fileId' => $fileId]);
        self::assertFalse($again['isError'], $again['text']);
        self::assertStringContainsString('existe déjà', $this->skipped($again['data'], 0));
        self::assertCount(1, $this->quizzes($teacher), 'never imported again');
    }

    public function testASpreadsheetThatIsNoReportRefusesTheWholeBatch(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.kahoot');
        $token = $this->accessTokenFor($teacher);

        $fileId = $this->upload($token, 'lot.zip', $this->zip([
            'rapport.xlsx' => $this->report(),
            'notes.xlsx' => $this->xlsx(['Feuil1' => [['Nom', 'Note'], ['Alice', '15']]]),
        ]));

        $refused = $this->callTool($token, 'quiz_import_kahoot', ['fileId' => $fileId]);
        self::assertTrue($refused['isError']);
        self::assertStringContainsString('notes.xlsx', $refused['text']);
        self::assertSame([], $this->quizzes($teacher), 'nothing is half-imported');
    }

    private function upload(string $token, string $name, string $bytes): int
    {
        $uploaded = $this->callTool($token, 'file_upload', ['name' => $name, 'base64' => base64_encode($bytes)]);
        self::assertFalse($uploaded['isError'], $uploaded['text']);
        $fileId = $uploaded['data']['fileId'] ?? null;
        self::assertIsInt($fileId);

        return $fileId;
    }

    /** @return list<QuizTemplate> */
    private function quizzes(User $teacher): array
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getRepository(QuizTemplate::class)->findBy(['teacher' => $teacher]);
    }

    /** @param array<string, mixed> $data */
    private function skipped(array $data, int $index): string
    {
        $skipped = $this->line($data, $index)['skipped'] ?? null;
        self::assertIsString($skipped);

        return $skipped;
    }

    /**
     * One report of the tool's answer.
     *
     * @param array<string, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function line(array $data, int $index): array
    {
        $reports = $data['reports'] ?? null;
        self::assertIsArray($reports);
        $line = $reports[$index] ?? null;
        self::assertIsArray($line);

        return $line;
    }

    /** A newer-layout report: a QCM multiple, a slider, a poll. */
    private function report(): string
    {
        $header = ['Question Number', 'Question', 'Answer 1', 'Answer 2', 'Answer 3', 'Answer 4', 'Answer 5', 'Answer 6', 'Correct Answers', 'Time Allotted to Answer (seconds)', 'Player', 'Answer', 'Answer Status', 'Correct', 'Incorrect', 'Score (points)'];

        return $this->xlsx([
            'Overview' => [['CEJM - Révision Thème 4'], ['Played on', '12 May 2026']],
            '1 Multiple-select Quiz' => [],
            '2 Slider' => [['CEJM - Révision Thème 4'], ['2 Slider', 'Le salaire net moyen'], ['Correct answer', '2733.0'], ['Choice range', '0.0 to 4000.0']],
            '3 Poll' => [],
            'Raw Report Data' => [
                $header,
                ['1', 'Les cycles en finance', "d'exploitation", "d'investissement", 'conjoncturels', '-', '-', '-', "d'exploitation,d'investissement", '30', 'Pseudo A', "d'exploitation", 'Incorrect', '0', '1', '0'],
                ['2', 'Le salaire net moyen', '-', '-', '-', '-', '-', '-', '', '20', 'Pseudo A', '2373.0', 'Correct', '1', '0', '189'],
                ['2', 'Le salaire net moyen', '-', '-', '-', '-', '-', '-', '', '20', 'Pseudo B', '3173.0', 'Incorrect', '0', '1', '0'],
                ['3', 'Je vote pour', 'Projet A', 'Projet B', '-', '-', '-', '-', '', '120', 'Pseudo A', 'Projet A', '', '0', '0', '0'],
            ],
        ]);
    }

    /**
     * The smallest workbook App\Service\XlsxSheetReader reads: one part per sheet, inline strings.
     *
     * @param array<string, list<list<string>>> $sheets
     */
    private function xlsx(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kahoot-xlsx-');
        self::assertIsString($path);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);

        $entries = '';
        $relations = '';
        $index = 0;
        foreach ($sheets as $name => $rows) {
            ++$index;
            $entries .= \sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"/>', htmlspecialchars($name, \ENT_XML1), $index, $index);
            $relations .= \sprintf('<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>', $index, $index);

            $xml = '';
            foreach ($rows as $r => $cells) {
                $xml .= \sprintf('<row r="%d">', $r + 1);
                foreach ($cells as $c => $value) {
                    if ('' !== $value) {
                        $xml .= \sprintf('<c r="%s%d" t="inlineStr"><is><t>%s</t></is></c>', \chr(65 + $c), $r + 1, htmlspecialchars($value, \ENT_XML1));
                    }
                }
                $xml .= '</row>';
            }
            $zip->addFromString(\sprintf('xl/worksheets/sheet%d.xml', $index), '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$entries.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>');
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kahoot-zip-');
        self::assertIsString($path);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
