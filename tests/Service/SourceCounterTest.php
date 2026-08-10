<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SourceCounter;
use PHPUnit\Framework\TestCase;

/**
 * The figures the "Description technique" screen shows are counted from the deployed application
 * itself, never written down - a number typed into a template is true the day it is typed and a lie
 * a month later. This is the counting, and it has to be exact: it is the one part of that page a
 * reader can check against the source.
 */
class SourceCounterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/source-counter-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/nested/deeper', 0o777, true);

        file_put_contents($this->root.'/one.php', "<?php\n\necho 1;\n");          // 3 lignes
        file_put_contents($this->root.'/nested/two.php', "<?php\n");              // 1 ligne
        file_put_contents($this->root.'/nested/deeper/three.php', "a\nb\nc\nd\n"); // 4 lignes
        file_put_contents($this->root.'/ignored.twig', "x\ny\n");
    }

    protected function tearDown(): void
    {
        foreach (['nested/deeper/three.php', 'nested/two.php', 'one.php', 'ignored.twig'] as $file) {
            @unlink($this->root.'/'.$file);
        }
        @rmdir($this->root.'/nested/deeper');
        @rmdir($this->root.'/nested');
        @rmdir($this->root);
    }

    public function testCountsMatchingFilesThroughSubdirectories(): void
    {
        self::assertSame(3, (new SourceCounter())->files($this->root, 'php'));
    }

    public function testCountsOnlyTheExtensionItWasAsked(): void
    {
        self::assertSame(1, (new SourceCounter())->files($this->root, 'twig'));
    }

    public function testCountsLinesAcrossEveryMatchingFile(): void
    {
        self::assertSame(8, (new SourceCounter())->lines($this->root, 'php'));
    }

    public function testALastLineWithoutANewlineStillCounts(): void
    {
        file_put_contents($this->root.'/four.php', "a\nb");

        self::assertSame(10, (new SourceCounter())->lines($this->root, 'php'));

        unlink($this->root.'/four.php');
    }

    public function testAnEmptyFileCountsAsNoLine(): void
    {
        file_put_contents($this->root.'/empty.php', '');

        self::assertSame(4, (new SourceCounter())->files($this->root, 'php'));
        self::assertSame(8, (new SourceCounter())->lines($this->root, 'php'));

        unlink($this->root.'/empty.php');
    }

    public function testAMissingDirectoryCountsZeroRatherThanFailing(): void
    {
        // The screen must render on an installation where a directory was excluded from the image
        // (tests/ and .git/ are, in production) rather than answer a 500 over a missing folder.
        $counter = new SourceCounter();

        self::assertSame(0, $counter->files($this->root.'/nowhere', 'php'));
        self::assertSame(0, $counter->lines($this->root.'/nowhere', 'php'));
    }

    public function testAFileCanBeCountedByNameSuffixRatherThanExtension(): void
    {
        // Stimulus controllers are named *_controller.js - counting ".js" would also catch every
        // other script in the same folder.
        self::assertSame(1, (new SourceCounter())->files($this->root, 'php', 'one'));
    }
}
