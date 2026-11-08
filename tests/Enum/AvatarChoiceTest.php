<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\AvatarChoice;
use PHPUnit\Framework\TestCase;

/**
 * A choice is offered only when there is something behind it: « Utiliser ma photo » once a photo
 * was sent, « Utiliser mon avatar » once one was drawn (design/validated/quiz-tournament.md §16).
 * The initials are always possible.
 */
final class AvatarChoiceTest extends TestCase
{
    public function testThePhotoNeedsAPhoto(): void
    {
        self::assertTrue(AvatarChoice::Photo->isPossible(hasPhoto: true, hasDrawing: false));
        self::assertFalse(AvatarChoice::Photo->isPossible(hasPhoto: false, hasDrawing: true));
    }

    public function testTheDrawingNeedsADrawing(): void
    {
        self::assertTrue(AvatarChoice::Drawn->isPossible(hasPhoto: false, hasDrawing: true));
        self::assertFalse(AvatarChoice::Drawn->isPossible(hasPhoto: true, hasDrawing: false));
    }

    public function testTheMenagerieNeedsAnAvatarPicked(): void
    {
        self::assertTrue(AvatarChoice::Fixed->isPossible(hasPhoto: false, hasDrawing: false, hasFixed: true));
        self::assertFalse(AvatarChoice::Fixed->isPossible(hasPhoto: true, hasDrawing: true, hasFixed: false));
    }

    public function testTheFormValueIsReadStrictly(): void
    {
        self::assertSame(AvatarChoice::Photo, AvatarChoice::fromFormValue('photo'));
        self::assertSame(AvatarChoice::Drawn, AvatarChoice::fromFormValue('drawn'));
        self::assertSame(AvatarChoice::Fixed, AvatarChoice::fromFormValue('fixed'));
        self::assertNull(AvatarChoice::fromFormValue('none'));
        $this->expectException(\ValueError::class);
        AvatarChoice::fromFormValue('gravatar');
    }
}
