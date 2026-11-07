<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizLive;

use App\Service\QuizLive\LiveNickname;
use PHPUnit\Framework\TestCase;

/**
 * What a guest may call themselves in a public live contest (design/validated/quiz-live-public.md):
 * long enough, nothing of the refused list, and nobody else's name in the room - case and accents
 * left aside, accounts' names included.
 */
class LiveNicknameTest extends TestCase
{
    public function testAReasonableNicknameIsAccepted(): void
    {
        self::assertNull(LiveNickname::refusal('Loutre Cosmique', ['Inès M.', 'Captain Kiwi']));
        self::assertNull(LiveNickname::refusal('Nina_42', []));
    }

    public function testItIsCleanedAndCutBeforeAnythingElse(): void
    {
        self::assertSame('Loutre Cosmique', LiveNickname::clean("  <b>Loutre</b>\t  Cosmique "));
        self::assertSame(LiveNickname::MAX, mb_strlen(LiveNickname::clean(str_repeat('é', 40))));
        self::assertSame(LiveNickname::TOO_SHORT, LiveNickname::refusal(' a ', []), 'one character once cleaned');
        self::assertSame(LiveNickname::TOO_SHORT, LiveNickname::refusal('<i></i>', []));
    }

    public function testAnotherPlayersNameIsTakenWhateverTheCaseOrAccents(): void
    {
        self::assertSame(LiveNickname::TAKEN, LiveNickname::refusal('INES M.', ['Inès M.']));
        self::assertSame(LiveNickname::TAKEN, LiveNickname::refusal('captain  kiwi', ['Captain Kiwi']));
    }

    public function testAWordOfTheRefusedListIsRefusedAloneOrInAName(): void
    {
        self::assertSame(LiveNickname::REFUSED, LiveNickname::refusal('Gros con', []));
        self::assertSame(LiveNickname::REFUSED, LiveNickname::refusal('M.E.R.D.E', []), 'spelt out');
        self::assertSame(LiveNickname::REFUSED, LiveNickname::refusal('Bâtard', []), 'accented');
        self::assertNull(LiveNickname::refusal('Conan', []), 'a longer word that merely starts like one');
        self::assertNull(LiveNickname::refusal('Faucon', []));
    }
}
