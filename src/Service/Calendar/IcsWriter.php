<?php

declare(strict_types=1);

namespace App\Service\Calendar;

/**
 * Serialises a list of events to an RFC 5545 VCALENDAR - written by hand rather than pulled in as a
 * dependency, because a published read-only feed uses maybe a dozen of the specification's
 * properties and the three rules that actually bite are all here: CRLF endings, 75-octet line
 * folding, and TEXT escaping.
 *
 * Times are emitted in **UTC** (the `Z` form) rather than as local times with a VTIMEZONE block.
 * Both are correct; UTC is the one that needs no timezone definition shipped alongside it, and each
 * event carries a real date, so converting from Europe/Paris resolves summer time per event instead
 * of assuming one offset for the year.
 *
 * @phpstan-type IcsEvent array{
 *     uid: string,
 *     start: \DateTimeImmutable,
 *     end: \DateTimeImmutable,
 *     summary: string,
 *     location?: string|null,
 *     description?: string|null,
 *     url?: string|null,
 * }
 */
class IcsWriter
{
    private const LINE_BREAK = "\r\n";

    // How often a client is asked to come back. Both spellings are needed: REFRESH-INTERVAL is the
    // standard one (RFC 7986), X-PUBLISHED-TTL the Outlook-era name that several clients still read.
    // Neither is binding - Google refetches on its own schedule whatever a feed asks for.
    private const REFRESH_INTERVAL = 'PT6H';

    /**
     * @param list<IcsEvent> $events
     */
    public function write(string $calendarName, array $events): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MonCampus//Emploi du temps//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escapeText($calendarName),
            'X-WR-TIMEZONE:Europe/Paris',
            'REFRESH-INTERVAL;VALUE=DURATION:'.self::REFRESH_INTERVAL,
            'X-PUBLISHED-TTL:'.self::REFRESH_INTERVAL,
        ];

        // One stamp for the whole document: DTSTAMP says when this iCalendar object was built, not
        // when the séance was last touched (LessonSession keeps no such date), so every event of a
        // given fetch shares it.
        $stamp = $this->formatUtc(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$event['uid'];
            $lines[] = 'DTSTAMP:'.$stamp;
            $lines[] = 'DTSTART:'.$this->formatUtc($event['start']);
            $lines[] = 'DTEND:'.$this->formatUtc($event['end']);
            $lines[] = 'SUMMARY:'.$this->escapeText($event['summary']);

            foreach (['location' => 'LOCATION', 'description' => 'DESCRIPTION', 'url' => 'URL'] as $key => $property) {
                $value = $event[$key] ?? null;

                if (null !== $value && '' !== $value) {
                    $lines[] = $property.':'.$this->escapeText($value);
                }
            }

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode(self::LINE_BREAK, array_map($this->fold(...), $lines)).self::LINE_BREAK;
    }

    private function formatUtc(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    // RFC 5545 §3.3.11: inside a TEXT value, a backslash, a semicolon and a comma are literals only
    // when escaped, and a line break is written as \n. The order matters - backslashes first, or the
    // escapes added below would be escaped in turn.
    private function escapeText(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\;', '\\,'],
            $value,
        );
    }

    /**
     * RFC 5545 §3.1: no line exceeds 75 **octets**, continuations starting with one space.
     *
     * Splitting on octets rather than characters is what makes this safe for the accented French
     * the summaries are full of: cutting a UTF-8 sequence in half produces a line a client either
     * drops or renders as mojibake. So the boundary is walked back to the start of a character
     * whenever it would land inside one.
     */
    private function fold(string $line): string
    {
        if (\strlen($line) <= 75) {
            return $line;
        }

        $chunks = [];
        $offset = 0;
        // The first line takes 75 octets; every continuation is prefixed with a space, so it can
        // only carry 74 of its own.
        $limit = 75;

        while ($offset < \strlen($line)) {
            $length = min($limit, \strlen($line) - $offset);

            // Never cut inside a multi-byte character: a continuation byte is 10xxxxxx.
            while ($length > 1 && 0x80 === (\ord($line[$offset + $length] ?? "\0") & 0xC0)) {
                --$length;
            }

            $chunks[] = ([] === $chunks ? '' : ' ').substr($line, $offset, $length);
            $offset += $length;
            $limit = 74;
        }

        return implode(self::LINE_BREAK, $chunks);
    }
}
