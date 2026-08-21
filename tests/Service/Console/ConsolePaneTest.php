<?php

declare(strict_types=1);

namespace App\Tests\Service\Console;

use App\Service\Console\ConsoleNotReadyException;
use App\Service\Console\ConsolePane;
use PHPUnit\Framework\TestCase;

/**
 * Reading what came back from the machine.
 *
 * The failure that matters here is the quiet one: a machine with no tmux answers *something* on
 * stdout, and a reader that shrugs and shows it turns « the console is not installed yet » into a
 * terminal full of an apt error. Anything that is not a screen has to be refused as such, because
 * that refusal is what triggers the repair.
 */
class ConsolePaneTest extends TestCase
{
    private const string DIGEST = '0123456789abcdef0123456789abcdef';

    public function testAScreenIsItsDigestItsMeasurementsAndThenTheBytesVerbatim(): void
    {
        $pane = ConsolePane::parse(self::DIGEST."\n12 3 164 42 0\nmoncampus@tp-sisr-03:~$ ls");

        self::assertSame(self::DIGEST, $pane->digest);
        self::assertSame('moncampus@tp-sisr-03:~$ ls', $pane->content);
        self::assertSame(12, $pane->cursorX);
        self::assertSame(3, $pane->cursorY);
        self::assertSame(164, $pane->columns);
        self::assertSame(42, $pane->rows);
        self::assertFalse($pane->alternate);
    }

    /** A pane is arbitrary bytes: newlines in it belong to the screen, not to the envelope. */
    public function testThePaneKeepsItsOwnNewlinesAndEscapeSequences(): void
    {
        $screen = "\e[32mok\e[0m\nsecond line\n\nfourth";
        $pane = ConsolePane::parse(self::DIGEST."\n0 0 80 24 0\n".$screen);

        self::assertSame($screen, $pane->content);
    }

    public function testAFullScreenProgramIsFlaggedAsSuch(): void
    {
        self::assertTrue(ConsolePane::parse(self::DIGEST."\n0 0 80 24 1\nvim")->alternate);
    }

    public function testSomethingThatIsNotAScreenIsRefusedRatherThanShown(): void
    {
        $this->expectException(ConsoleNotReadyException::class);

        ConsolePane::parse("sh: 1: tmux: not found\n");
    }

    public function testAnEmptyAnswerIsNotAScreenEither(): void
    {
        $this->expectException(ConsoleNotReadyException::class);

        ConsolePane::parse('');
    }

    public function testAScreenWithoutItsMeasurementsIsRefused(): void
    {
        $this->expectException(ConsoleNotReadyException::class);

        ConsolePane::parse(self::DIGEST."\n\nmoncampus@tp-sisr-03:~$");
    }

    /** The transcript is read by a person: the colours are for the browser, not for the record. */
    public function testTheTranscriptTextCarriesNoEscapeSequences(): void
    {
        $pane = ConsolePane::parse(self::DIGEST."\n0 0 80 24 0\n\e[1;32mmoncampus@tp\e[0m:~$ \e[Kls\n\n\n");

        self::assertSame('moncampus@tp:~$ ls', $pane->plainText());
    }
}
