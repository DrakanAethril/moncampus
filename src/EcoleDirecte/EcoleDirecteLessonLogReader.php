<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

use App\Service\HtmlPlainText;

/**
 * A teacher's École Directe cahier de texte over a span of days - the first read of the prototype,
 * and the one the future sending will have to match MonCampus's séances against.
 *
 * École Directe answers one row per timetable slot, the written parts in base64-encoded HTML under
 * `seance` (or `contenuDeSeance`) and `aFaire`. They are turned into plain text here: this screen
 * reads, and HTML from another application is never printed as HTML.
 */
class EcoleDirecteLessonLogReader
{
    private const int EXCERPT_LENGTH = 280;

    /** A week is what the screen asks for; a school year is where a span stops being a read. */
    public const int MAX_DAYS = 31;

    public function __construct(
        private readonly EcoleDirecteClient $client,
        private readonly HtmlPlainText $plainText,
    ) {
    }

    /**
     * @return array{slots: list<EcoleDirecteSlot>, session: EcoleDirecteSession}
     *
     * @throws EcoleDirecteException
     */
    public function read(EcoleDirecteSession $session, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        self::assertSpan($from, $to);
        $raw = $this->rawSlots($session, $from, $to);

        $slots = array_map($this->slot(...), $raw['rows']);
        usort($slots, static fn (EcoleDirecteSlot $a, EcoleDirecteSlot $b): int => [$a->date?->format('Y-m-d'), $a->start] <=> [$b->date?->format('Y-m-d'), $b->start]);

        return ['slots' => $slots, 'session' => $raw['session']];
    }

    /**
     * The slots as École Directe answered them - what sending hands back, content aside. No bound on
     * the span here: the sending reads a little past the séances it sends, to find each one's next
     * lesson.
     *
     * @return array{rows: list<array<array-key, mixed>>, session: EcoleDirecteSession}
     *
     * @throws EcoleDirecteException
     */
    public function rawSlots(EcoleDirecteSession $session, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (!$session->account->isTeacher()) {
            throw new EcoleDirecteException('ecoleDirecteNotTeacherMessage');
        }

        $result = $this->client->read($session, \sprintf('cahierdetexte/loadslots/%s/%s.awp', $from->format('Y-m-d'), $to->format('Y-m-d')));

        $rows = [];
        foreach (\is_array($result->data) ? $result->data : [] as $row) {
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }

        return ['rows' => $rows, 'session' => $result->session];
    }

    /** @throws EcoleDirecteException */
    public static function assertSpan(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($to < $from || $from->diff($to)->days > self::MAX_DAYS) {
            throw new EcoleDirecteException('ecoleDirecteInvalidSpanMessage');
        }
    }

    /** @param array<array-key, mixed> $row */
    private function slot(array $row): EcoleDirecteSlot
    {
        $lesson = \is_array($row['seance'] ?? null) ? $row['seance'] : (\is_array($row['contenuDeSeance'] ?? null) ? $row['contenuDeSeance'] : []);
        $homework = \is_array($row['aFaire'] ?? null) ? $row['aFaire'] : [];

        $content = $this->excerpt($lesson['contenu'] ?? null);
        $work = $this->excerpt($homework['contenu'] ?? null);

        $start = self::text($row['start_date'] ?? null);
        $end = self::text($row['end_date'] ?? null);

        $day = substr('' !== self::text($row['date'] ?? null) ? self::text($row['date'] ?? null) : $start, 0, 10);

        return new EcoleDirecteSlot(
            \DateTimeImmutable::createFromFormat('!Y-m-d', $day) ?: null,
            self::clock($start),
            self::clock($end),
            self::text($row['entityLibelle'] ?? null),
            self::text($row['matiereLibelle'] ?? null),
            self::text($row['salle'] ?? null),
            $content,
            $work,
            '' !== $content || true === self::flag($row['contenuDeSeance'] ?? null),
            '' !== $work || true === self::flag($row['travailAFaire'] ?? null),
            true === self::flag($row['interrogation'] ?? null),
        );
    }

    private function excerpt(mixed $base64): string
    {
        if (!\is_string($base64) || '' === $base64) {
            return '';
        }

        $html = base64_decode($base64, true);
        if (false === $html) {
            return '';
        }

        return mb_strimwidth(trim($this->plainText->fromHtml($html) ?? ''), 0, self::EXCERPT_LENGTH, '…');
    }

    /** "2026-09-22 08:00" -> "08:00"; anything else is shown as it came. */
    private static function clock(string $dateTime): string
    {
        return 1 === preg_match('/(\d{2}:\d{2})/', $dateTime, $match) ? $match[1] : $dateTime;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }

    private static function flag(mixed $value): ?bool
    {
        return match (true) {
            \is_bool($value) => $value,
            \is_int($value) => 0 !== $value,
            \is_string($value) => \in_array(strtolower($value), ['1', 'true', 'oui'], true),
            default => null,
        };
    }
}
