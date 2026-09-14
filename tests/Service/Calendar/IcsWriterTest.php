<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Service\Calendar\IcsWriter;
use PHPUnit\Framework\TestCase;

/**
 * The three rules of RFC 5545 that actually break a subscription when they are wrong: CRLF endings,
 * 75-octet folding, and TEXT escaping. None of them is visible on screen - a feed that gets them
 * wrong is simply refused by the agenda, silently, with no way to tell from this side.
 */
class IcsWriterTest extends TestCase
{
    private IcsWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new IcsWriter();
    }

    public function testItWrapsEventsInAVCalendarEnvelope(): void
    {
        $ics = $this->writer->write('BTS SIO 1', [$this->event()]);

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertStringContainsString("BEGIN:VEVENT\r\n", $ics);
        self::assertStringContainsString("END:VEVENT\r\n", $ics);
        self::assertStringContainsString('X-WR-CALNAME:BTS SIO 1', $ics);
    }

    public function testEveryLineEndsWithCrLf(): void
    {
        $ics = $this->writer->write('Agenda', [$this->event()]);

        // A lone LF is the classic failure: PHP's own "\n" slips in through a heredoc and several
        // clients reject the whole document rather than the line.
        self::assertSame(0, preg_match('/(?<!\r)\n/', $ics), 'a line ends with a bare LF');
    }

    /**
     * An emploi du temps in Europe/Paris crosses summer time twice a year, so an offset assumed once
     * for the calendar is wrong for half of it. January is +01:00, July is +02:00.
     */
    public function testItConvertsLocalTimesToUtcPerEvent(): void
    {
        $ics = $this->writer->write('Agenda', [
            $this->event(start: '2026-01-12 08:00', end: '2026-01-12 10:00'),
            $this->event(start: '2026-07-06 08:00', end: '2026-07-06 10:00'),
        ]);

        self::assertStringContainsString('DTSTART:20260112T070000Z', $ics);
        self::assertStringContainsString('DTEND:20260112T090000Z', $ics);
        self::assertStringContainsString('DTSTART:20260706T060000Z', $ics);
        self::assertStringContainsString('DTEND:20260706T080000Z', $ics);
    }

    public function testItEscapesTheCharactersThatAreSyntaxInsideAText(): void
    {
        $ics = $this->writer->write('Agenda', [$this->event(
            summary: 'Maths ; algèbre, suites',
            description: "Salle B12\nApporter la calculatrice",
        )]);

        self::assertStringContainsString('SUMMARY:Maths \; algèbre\\, suites', $ics);
        self::assertStringContainsString('DESCRIPTION:Salle B12\\nApporter la calculatrice', $ics);
    }

    public function testABackslashIsEscapedBeforeTheEscapesItWouldOtherwiseSwallow(): void
    {
        $ics = $this->writer->write('Agenda', [$this->event(summary: 'C:\\temp, suite')]);

        self::assertStringContainsString('SUMMARY:C:\\\\temp\\, suite', $ics);
    }

    public function testItFoldsLongLinesAt75Octets(): void
    {
        $ics = $this->writer->write('Agenda', [$this->event(summary: str_repeat('a', 200))]);

        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, \strlen($line), \sprintf('line longer than 75 octets: %s', $line));
        }

        // Folding is only a transport rule: unfolding (CRLF + one space removed) has to give the
        // value back exactly, or the agenda shows a truncated title.
        self::assertStringContainsString('SUMMARY:'.str_repeat('a', 200), str_replace("\r\n ", '', $ics));
    }

    /**
     * French summaries are full of accented characters, which are two octets each in UTF-8. A fold
     * that counts characters overflows the limit; one that counts octets without care cuts a
     * character in half and the client renders mojibake - or drops the line.
     */
    public function testItNeverFoldsInsideAMultiByteCharacter(): void
    {
        $ics = $this->writer->write('Agenda', [$this->event(summary: str_repeat('é', 120))]);

        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, \strlen($line));
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), \sprintf('folded line is not valid UTF-8: %s', bin2hex($line)));
        }

        self::assertStringContainsString('SUMMARY:'.str_repeat('é', 120), str_replace("\r\n ", '', $ics));
    }

    public function testItOmitsTheOptionalPropertiesItWasGivenNothingFor(): void
    {
        $ics = $this->writer->write('Agenda', [$this->event(location: null, description: '')]);

        self::assertStringNotContainsString('LOCATION:', $ics);
        self::assertStringNotContainsString('DESCRIPTION:', $ics);
    }

    /**
     * @return array{uid: string, start: \DateTimeImmutable, end: \DateTimeImmutable, summary: string, location?: string|null, description?: string|null, url?: string|null}
     */
    private function event(
        string $uid = 'lesson-session-1@moncampus.test',
        string $start = '2026-01-12 08:00',
        string $end = '2026-01-12 10:00',
        string $summary = 'Mathématiques',
        ?string $location = 'B12',
        ?string $description = 'BTS SIO 1',
    ): array {
        $paris = new \DateTimeZone('Europe/Paris');

        return [
            'uid' => $uid,
            'start' => new \DateTimeImmutable($start, $paris),
            'end' => new \DateTimeImmutable($end, $paris),
            'summary' => $summary,
            'location' => $location,
            'description' => $description,
        ];
    }
}
