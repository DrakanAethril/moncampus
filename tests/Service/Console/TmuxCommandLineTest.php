<?php

declare(strict_types=1);

namespace App\Tests\Service\Console;

use App\Service\Console\TmuxCommandLine;
use PHPUnit\Framework\TestCase;

/**
 * The only piece of the console that is entirely testable without a machine, and the one everything
 * else rides on: every keystroke, every screenshot and every resize is a line this class writes.
 *
 * Written before the class it tests, because the failure modes here are silent ones. A byte that
 * does not reach `send-keys -H` is a key that does nothing; a digest that is not echoed back into
 * the loop guard turns the long exchange into a busy loop; a width that is not clamped hands the
 * machine whatever the browser said it measured.
 */
class TmuxCommandLineTest extends TestCase
{
    public function testPresenceAsksTheMachineRatherThanAssuming(): void
    {
        self::assertStringContainsString('command -v tmux', TmuxCommandLine::presence());
    }

    /** Installed with the same non-interactive guards the rest of the provisioning chain uses. */
    public function testInstallNamesTmuxAndNeverStopsToAsk(): void
    {
        $line = TmuxCommandLine::install();

        self::assertStringContainsString('tmux', $line);
        self::assertStringContainsString('-y', $line, 'apt must not stop to ask');
    }

    public function testOpenAttachesOrCreatesOneDetachedSessionOfTheGivenSize(): void
    {
        $line = TmuxCommandLine::open(164, 42);

        // -A is the whole of « reprise »: it attaches to the session that is already there, and
        // only creates one when there is none. Without it a second visit starts a second shell and
        // the `apt upgrade` left running before lunch becomes invisible.
        self::assertStringContainsString('new-session -A -d', $line);
        self::assertStringContainsString('-s '.TmuxCommandLine::SESSION, $line);
        self::assertStringContainsString('-x 164', $line);
        self::assertStringContainsString('-y 42', $line);
    }

    public function testTheBytesTheBrowserTypedTravelAsHexToSendKeys(): void
    {
        // "ls" then Enter, exactly as xterm.js produces it.
        $line = TmuxCommandLine::exchange('6c730d', str_repeat('0', 32), 100, 30, 8.0);

        self::assertStringContainsString('send-keys -t '.TmuxCommandLine::SESSION.' -H 6c 73 0d', $line);
    }

    /**
     * An exchange with nothing typed is the ordinary case - it is how the screen watches a command
     * that is already running - and it must not send an empty keystroke.
     */
    public function testAnExchangeWithNothingTypedSendsNoKeysAtAll(): void
    {
        self::assertStringNotContainsString('send-keys', TmuxCommandLine::exchange('', str_repeat('0', 32), 100, 30, 8.0));
    }

    /**
     * The hex comes off the network. Anything that is not a byte is dropped rather than escaped:
     * there is no legitimate keystroke that needs a semicolon in this field, so a value carrying
     * one is not a keystroke.
     */
    public function testAnythingThatIsNotAByteIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TmuxCommandLine::exchange('6c; rm -rf /', str_repeat('0', 32), 100, 30, 8.0);
    }

    public function testAnOddNumberOfHexDigitsIsNotABytestring(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TmuxCommandLine::exchange('6c7', str_repeat('0', 32), 100, 30, 8.0);
    }

    public function testUppercaseHexIsAcceptedAndNormalised(): void
    {
        self::assertStringContainsString('-H 6c 0d', TmuxCommandLine::exchange('6C0D', str_repeat('0', 32), 100, 30, 8.0));
    }

    /**
     * The digest of the screen the browser is already showing. It is what the loop inside the
     * machine compares against, so it has to arrive intact - and it comes off the network too.
     */
    public function testTheDigestOfTheScreenAlreadyShownIsWhatTheLoopWaitsOn(): void
    {
        $digest = str_repeat('ab', 16);

        self::assertStringContainsString($digest, TmuxCommandLine::exchange('', $digest, 100, 30, 8.0));
    }

    public function testADigestThatIsNotADigestIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TmuxCommandLine::exchange('', 'not-a-digest', 100, 30, 8.0);
    }

    /** The first exchange of a session has nothing to compare against, and that is not an error. */
    public function testAnEmptyDigestIsAllowedAndMeansShowMeWhateverIsThere(): void
    {
        self::assertStringContainsString('capture-pane', TmuxCommandLine::exchange('', '', 100, 30, 8.0));
    }

    /**
     * A width the browser measured is a number the browser chose. Eight thousand columns is not a
     * terminal, and zero is not one either - tmux would answer an error for both, into a screen
     * built to show a pane.
     */
    public function testTheMeasuredSizeIsClampedToSomethingThatIsActuallyATerminal(): void
    {
        $tiny = TmuxCommandLine::exchange('', '', 1, 1, 8.0);
        $huge = TmuxCommandLine::exchange('', '', 9000, 9000, 8.0);

        self::assertStringContainsString('-x '.TmuxCommandLine::MIN_COLUMNS, $tiny);
        self::assertStringContainsString('-y '.TmuxCommandLine::MIN_ROWS, $tiny);
        self::assertStringContainsString('-x '.TmuxCommandLine::MAX_COLUMNS, $huge);
        self::assertStringContainsString('-y '.TmuxCommandLine::MAX_ROWS, $huge);
    }

    /**
     * The screen is watched inside the machine, not over the network: how many times it looks is
     * how long the request may hold a worker, and it is a number this class computes rather than
     * one the caller writes twice.
     */
    public function testHowLongTheMachineWatchesFollowsTheBudgetItWasGiven(): void
    {
        self::assertStringContainsString((string) (int) (8.0 / TmuxCommandLine::TICK_SECONDS), TmuxCommandLine::exchange('', '', 100, 30, 8.0));
        self::assertStringContainsString((string) (int) (2.0 / TmuxCommandLine::TICK_SECONDS), TmuxCommandLine::exchange('', '', 100, 30, 2.0));
    }

    /** A budget of zero still looks once: an exchange that photographs nothing answers nothing. */
    public function testAnExchangeAlwaysLooksAtLeastOnce(): void
    {
        self::assertStringContainsString('capture-pane', TmuxCommandLine::exchange('', '', 100, 30, 0.0));
    }

    /** The colours are the point: `-e` is what makes the pane arrive with its escape sequences. */
    public function testTheScreenshotKeepsItsColours(): void
    {
        self::assertStringContainsString('capture-pane -p -e', TmuxCommandLine::exchange('', '', 100, 30, 8.0));
    }

    /** The cursor is not in the pane text, and a terminal without one is a terminal nobody trusts. */
    public function testTheCursorTravelsBesideThePane(): void
    {
        $line = TmuxCommandLine::exchange('', '', 100, 30, 8.0);

        self::assertStringContainsString('#{cursor_x}', $line);
        self::assertStringContainsString('#{cursor_y}', $line);
    }

    public function testTheScrollbackIsReadFromWhereItWasAsked(): void
    {
        self::assertStringContainsString('-S -3000', TmuxCommandLine::history(3000));
    }

    /** Asking for the whole scrollback of a machine nobody has bounded is a way to time a request out. */
    public function testTheScrollbackIsBounded(): void
    {
        self::assertStringContainsString('-S -'.TmuxCommandLine::MAX_HISTORY_LINES, TmuxCommandLine::history(999999));
    }

    /**
     * A line sent to the shell is not a keystroke sequence: it is text plus a Return, and the text
     * is somebody's command. It travels as hex for the same reason the keys do - so that nothing in
     * it can be read as tmux syntax.
     */
    public function testALineIsSentAsItsBytesAndTerminatedByAReturn(): void
    {
        $line = TmuxCommandLine::sendLine('echo "hi"');

        self::assertStringContainsString('-H 65 63 68 6f', $line, 'the bytes of « echo »');
        self::assertStringEndsWith('0d', trim($line), 'and a Return to run it');
    }

    public function testALineCarryingATmuxSeparatorIsStillJustText(): void
    {
        // A semicolon ends a tmux command. Sent as bytes, it is nothing but a semicolon.
        $line = TmuxCommandLine::sendLine('true ; tmux kill-server');

        self::assertStringNotContainsString('kill-server', $line);
    }
}
