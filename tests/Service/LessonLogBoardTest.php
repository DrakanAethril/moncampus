<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LessonLogBoard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the badge on a row of the cahier de texte says.
 *
 * It is not visible from the screen that a badge reads only ONE of the three parts to decide
 * « rempli », nor that HugeRTE's leftovers have to be seen through - and both are exactly what a
 * teacher scanning a week for the gaps depends on.
 */
class LessonLogBoardTest extends TestCase
{
    private LessonLogBoard $board;

    protected function setUp(): void
    {
        $this->board = new LessonLogBoard();
    }

    // --- What the badge says ---

    public function testOnlyWhatWasActuallyTaughtDecidesTheBadge(): void
    {
        self::assertSame('empty', $this->board->stateOf(null), 'no log at all');
        self::assertSame('empty', $this->board->stateOf(''));
        self::assertSame('empty', $this->board->stateOf('   '));
        self::assertSame('empty', $this->board->stateOf('<p></p>'), 'markup with no text is not a record');
        self::assertSame('filled', $this->board->stateOf('<p>Boucles imbriquées</p>'));
    }

    /**
     * What HugeRTE leaves behind when a teacher types something and deletes it. The badge used to
     * read these as a kept log, on the one screen whose whole purpose is spotting the gaps.
     *
     * @return iterable<string, array{string}>
     */
    public static function emptyLookingContentProvider(): iterable
    {
        yield 'non-breaking space entity' => ['<p>&nbsp;</p>'];
        yield 'numeric non-breaking space' => ['<p>&#160;</p>'];
        yield 'a decoded non-breaking space' => ["<p>\u{00A0}</p>"];
        yield 'a lone line break' => ['<p><br></p>'];
        yield 'several empty paragraphs' => ['<p>&nbsp;</p><p><br></p><p> </p>'];
        yield 'a zero-width space' => ["<p>\u{200B}</p>"];
    }

    #[DataProvider('emptyLookingContentProvider')]
    public function testContentThatOnlyLooksFilledReadsAsEmpty(string $content): void
    {
        self::assertSame('empty', $this->board->stateOf($content));
    }

    public function testARealNonBreakingSpaceInsideTextStillCounts(): void
    {
        // Fixing the above must not go the other way: French typography puts a non-breaking space
        // before a colon, and that sentence is a kept log.
        self::assertSame('filled', $this->board->stateOf("<p>Chapitre 3\u{00A0}: les boucles</p>"));
    }

    // --- The three-state tag of the period screen ---

    public function testASeanceIsFilledWhenWhatWasTaughtIsWrittenDown(): void
    {
        // Same authority as the two-state badge above: only the « pendant » part says the log was
        // kept. What is added here is a middle state, not a second definition of « rempli ».
        self::assertSame('filled', $this->board->sessionStateOf('', '<p>Boucles imbriquées</p>', '', false));
        self::assertSame('filled', $this->board->sessionStateOf('', '<p>Boucles imbriquées</p>', '', true));
    }

    public function testASeanceIsPartialWhenSomethingWasStartedButNotTheAccountOfTheLesson(): void
    {
        self::assertSame('partial', $this->board->sessionStateOf('<p>Lire le chapitre 3</p>', '', '', false));
        self::assertSame('partial', $this->board->sessionStateOf('', '', '<p>Compte rendu de TP</p>', false));
        // A document or an assignment hung on the séance is a start too, with nothing typed.
        self::assertSame('partial', $this->board->sessionStateOf('', '', '', true));
    }

    public function testASeanceIsEmptyWhenNothingWasEverPutOnIt(): void
    {
        self::assertSame('empty', $this->board->sessionStateOf(null, null, null, false));
        self::assertSame('empty', $this->board->sessionStateOf('<p>&nbsp;</p>', '<p><br></p>', '   ', false));
    }

    public function testASectionReadsTheSameThreeStatesOnItsOwnContent(): void
    {
        self::assertSame('filled', $this->board->sectionStateOf('<p>TP VLAN</p>', false));
        // Nothing typed, but a document or an assignment is hanging there: the part is not blank.
        self::assertSame('partial', $this->board->sectionStateOf('<p>&nbsp;</p>', true));
        self::assertSame('empty', $this->board->sectionStateOf(null, false));
    }
}
