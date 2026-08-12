<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuizImportImageBatch;
use PHPUnit\Framework\TestCase;

/**
 * The images a teacher deposits before generating their questions, and the short keys (img1, img2…)
 * that are the only thing the prompt carries about them - see design/comparaison/conception_import_quiz_ia.md,
 * section 5 ter. Everything here is about those keys behaving as identifiers a model can reproduce
 * exactly, which is the whole reason they exist instead of an URL.
 */
class QuizImportImageBatchTest extends TestCase
{
    public function testAddNumbersImagesFromOne(): void
    {
        $batch = QuizImportImageBatch::fromSession(null);

        self::assertSame('img1', $batch->add('schema.png', 'quiz-import-images/aaa.png'));
        self::assertSame('img2', $batch->add('baie.jpg', 'quiz-import-images/bbb.jpg'));
        self::assertFalse($batch->isEmpty());
    }

    public function testKeyForResolvesAReferenceAndIgnoresAnUnknownOne(): void
    {
        $batch = QuizImportImageBatch::fromSession(null);
        $batch->add('schema.png', 'quiz-import-images/aaa.png');

        self::assertSame('quiz-import-images/aaa.png', $batch->keyFor('img1'));
        self::assertSame('schema.png', $batch->nameFor('img1'));
        self::assertNull($batch->keyFor('img9'));
        self::assertNull($batch->keyFor(''));
    }

    /**
     * A removed reference is never handed out again. The prompt is copied into a conversation the
     * application cannot see: reusing "img1" for another photo would silently answer a question
     * about the picture the teacher removed.
     */
    public function testRemovedReferencesAreNeverReused(): void
    {
        $batch = QuizImportImageBatch::fromSession(null);
        $batch->add('a.png', 'quiz-import-images/a.png');
        $batch->add('b.png', 'quiz-import-images/b.png');

        self::assertSame('quiz-import-images/a.png', $batch->remove('img1'));
        self::assertNull($batch->remove('img1'), 'removing twice removes nothing');
        self::assertSame('img3', $batch->add('c.png', 'quiz-import-images/c.png'));
        self::assertNull($batch->keyFor('img1'));
    }

    public function testSurvivesTheSessionRoundTrip(): void
    {
        $batch = QuizImportImageBatch::fromSession(null);
        $batch->add('schema.png', 'quiz-import-images/aaa.png');
        $batch->add('baie.jpg', 'quiz-import-images/bbb.jpg');
        $batch->remove('img1');

        $restored = QuizImportImageBatch::fromSession($batch->toSession());

        self::assertSame('quiz-import-images/bbb.jpg', $restored->keyFor('img2'));
        self::assertSame('img3', $restored->add('c.png', 'quiz-import-images/c.png'));
        self::assertSame(['quiz-import-images/bbb.jpg', 'quiz-import-images/c.png'], $restored->storageKeys());
    }

    /** A payload that never was one of ours reads as an empty batch, never as a fatal. */
    public function testGarbageInTheSessionReadsAsEmpty(): void
    {
        self::assertTrue(QuizImportImageBatch::fromSession('nonsense')->isEmpty());
        self::assertTrue(QuizImportImageBatch::fromSession([['ref' => 'img1']])->isEmpty(), 'an entry without a key is not an image');
        self::assertSame([], QuizImportImageBatch::fromSession(null)->all());
    }
}
