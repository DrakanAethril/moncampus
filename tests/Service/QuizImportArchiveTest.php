<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\JsonDocumentSplitter;
use App\Service\QuizCsvImportException;
use App\Service\QuizImportArchive;
use PHPUnit\Framework\TestCase;

/**
 * The second door into a batch: a `.zip` of `.json` files.
 *
 * A teacher who saved a model's answers one file at a time has ten files rather than one paste, and
 * zipping them is what they would do next. Nothing is extracted - entries are read into memory -
 * so what is pinned down here is the order they come back in and what is quietly ignored.
 */
class QuizImportArchiveTest extends TestCase
{
    private QuizImportArchive $archive;

    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $this->archive = new QuizImportArchive(new JsonDocumentSplitter());
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    public function testEveryJsonEntryBecomesADocument(): void
    {
        $path = $this->zip([
            'quiz-1.json' => '{"format":"moncampus-quiz/1","template":{"name":"Un"}}',
            'quiz-2.json' => '{"format":"moncampus-quiz/1","template":{"name":"Deux"}}',
        ]);

        $documents = $this->archive->documents($path);

        self::assertCount(2, $documents);
        self::assertSame('quiz-1.json', $documents[0]['fileName']);
        self::assertStringContainsString('"Deux"', $documents[1]['json']);
    }

    public function testEntriesAreOrderedTheWayTheirNamesRead(): void
    {
        $path = $this->zip([
            'quiz-10.json' => '{"n":10}',
            'quiz-2.json' => '{"n":2}',
            'quiz-1.json' => '{"n":1}',
        ]);

        self::assertSame(
            ['quiz-1.json', 'quiz-2.json', 'quiz-10.json'],
            array_column($this->archive->documents($path), 'fileName'),
        );
    }

    public function testTheOperatingSystemsOwnLitterIsIgnored(): void
    {
        $path = $this->zip([
            '__MACOSX/._quiz-1.json' => 'rubbish',
            '.DS_Store' => 'rubbish',
            'notes.txt' => 'des notes',
            'quiz-1.json' => '{"format":"moncampus-quiz/1"}',
        ]);

        self::assertSame(['quiz-1.json'], array_column($this->archive->documents($path), 'fileName'));
    }

    public function testAnEntryHoldingSeveralDocumentsIsSplitLikeAPaste(): void
    {
        $path = $this->zip(['tout.json' => '{"n":1}'."\n".'{"n":2}']);

        $documents = $this->archive->documents($path);

        self::assertCount(2, $documents);
        self::assertSame('tout.json', $documents[1]['fileName']);
    }

    public function testAnArchiveWithoutAnyJsonIsRefused(): void
    {
        $path = $this->zip(['lisez-moi.txt' => 'rien ici']);

        $this->expectException(QuizCsvImportException::class);

        $this->archive->documents($path);
    }

    public function testAFileThatIsNotAnArchiveIsRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'quiz-batch-');
        self::assertIsString($path);
        $this->paths[] = $path;
        file_put_contents($path, 'ceci n’est pas un zip');

        $this->expectException(QuizCsvImportException::class);

        $this->archive->documents($path);
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'quiz-batch-');
        self::assertIsString($path);
        $this->paths[] = $path;

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }
}
