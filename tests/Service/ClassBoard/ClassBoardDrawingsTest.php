<?php

declare(strict_types=1);

namespace App\Tests\Service\ClassBoard;

use App\Service\ClassBoard\ClassBoardDrawings;
use PHPUnit\Framework\TestCase;

/**
 * What a « Dessin » widget may store: a PNG, bounded, under a key that names its board and its
 * widget - and the layout's keys, read back for deletion.
 */
class ClassBoardDrawingsTest extends TestCase
{
    public function testOnlyAPngIsAccepted(): void
    {
        // A 1×1 transparent PNG: the image extension is not in the container, and this needs none.
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);

        ClassBoardDrawings::assertPng($png);

        foreach (['', '<svg xmlns="http://www.w3.org/2000/svg"></svg>', "\x89PNG\r\n\x1a\nnot really"] as $bytes) {
            try {
                ClassBoardDrawings::assertPng($bytes);
                self::fail('Accepted: '.substr($bytes, 0, 20));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAKeyNamesItsBoardAndItsWidget(): void
    {
        self::assertTrue(ClassBoardDrawings::isKeyOf('class-board/7/w1-0123456789abcdef.png', 7, 'w1'));
        self::assertFalse(ClassBoardDrawings::isKeyOf('class-board/7/w1-0123456789abcdef.png', 8, 'w1'));
        self::assertFalse(ClassBoardDrawings::isKeyOf('class-board/7/w1-0123456789abcdef.png', 7, 'w2'));
        self::assertFalse(ClassBoardDrawings::isKeyOf('class-board/7/../avatars/x-0123456789abcdef.png', 7, 'w1'));
    }

    public function testTheKeysOfALayoutAreItsDrawingsOnly(): void
    {
        $keys = ClassBoardDrawings::keys([
            ['id' => 'w1', 'type' => 'drawing', 'config' => ['key' => 'class-board/7/w1-0123456789abcdef.png']],
            ['id' => 'w2', 'type' => 'drawing', 'config' => ['key' => null]],
            ['id' => 'w3', 'type' => 'qr_code', 'config' => ['url' => 'https://example.org']],
        ]);

        self::assertSame(['w1' => 'class-board/7/w1-0123456789abcdef.png'], $keys);
    }
}
