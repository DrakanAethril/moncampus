<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

use App\Enum\EcoleDirecteSendState;

/**
 * Decides, without sending anything, what a teacher's MonCampus cahier de texte would write into
 * their École Directe one.
 *
 * **Matching.** A séance and a slot are the same lesson when they fall on the same day at the same
 * start time: both timetables come from the school's one EDT, and a teacher is in one place at a
 * time. A séance with no such slot is reported, never sent somewhere near.
 *
 * **Where each part goes.** École Directe files homework under the lesson it is *due* for.
 * - « Contenu réalisé » of a séance -> the session content of its own slot.
 * - « Travail avant » of a séance (to do before it) -> the homework of its own slot.
 * - « Travail après » (to do after it) -> the homework of the *next* slot of the same class and
 *   subject, when that slot is among the ones read. Both kinds can land on one slot, joined in the
 *   order they were given.
 *
 * Nothing empty is ever sent: an empty MonCampus part leaves École Directe as it is.
 */
final class EcoleDirecteLessonLogPlanner
{
    private const string JOIN = '<hr>';

    /**
     * @param list<EcoleDirecteLessonLogEntry>     $entries the séances whose cahier de texte may be sent
     * @param list<array<array-key, mixed>>        $slots   École Directe's slots, over a span that
     *                                                      reaches past the séances so the next
     *                                                      lesson of each can be found
     *
     * @return array{targets: list<EcoleDirecteLessonLogTarget>, unmatched: list<EcoleDirecteLessonLogEntry>, unplaced: list<EcoleDirecteLessonLogEntry>}
     *                                                      - unmatched: no slot at that moment;
     *                                                      unplaced: a « travail après » whose next
     *                                                      lesson is not among the slots read
     */
    public function plan(array $entries, array $slots): array
    {
        $ordered = [];
        foreach ($slots as $slot) {
            $date = self::day($slot);
            $start = self::clock(self::text($slot['start_date'] ?? null));
            if ('' !== $date && '' !== $start) {
                $ordered[] = ['slot' => $slot, 'date' => $date, 'start' => $start, 'key' => self::key($slot, $date, $start)];
            }
        }
        usort($ordered, static fn (array $a, array $b): int => [$a['date'], $a['start']] <=> [$b['date'], $b['start']]);

        $byMoment = [];
        foreach ($ordered as $index => $row) {
            $byMoment[$row['date'].' '.$row['start']] ??= $index;
        }

        /** @var array<int, array{content: list<string>, homework: list<string>, givenOn: string, sources: list<string>}> $wanted */
        $wanted = [];
        $unmatched = [];
        $unplaced = [];

        foreach ($entries as $entry) {
            if ($entry->isEmpty()) {
                continue;
            }

            $index = $byMoment[$entry->date.' '.$entry->start] ?? null;
            if (null === $index) {
                $unmatched[] = $entry;
                continue;
            }

            if ('' !== trim($entry->content) || '' !== trim($entry->workBefore)) {
                $wanted[$index] ??= ['content' => [], 'homework' => [], 'givenOn' => $entry->date, 'sources' => []];
                $wanted[$index]['sources'][] = $entry->label;

                if ('' !== trim($entry->content)) {
                    $wanted[$index]['content'][] = trim($entry->content);
                }
                if ('' !== trim($entry->workBefore)) {
                    $wanted[$index]['homework'][] = trim($entry->workBefore);
                    $wanted[$index]['givenOn'] = self::previousDate($ordered, $index) ?? $entry->date;
                }
            }

            if ('' !== trim($entry->workAfter)) {
                $next = self::nextSameLesson($ordered, $index);
                if (null === $next) {
                    $unplaced[] = $entry;
                    continue;
                }
                $wanted[$next] ??= ['content' => [], 'homework' => [], 'givenOn' => $entry->date, 'sources' => []];
                $wanted[$next]['homework'][] = trim($entry->workAfter);
                $wanted[$next]['givenOn'] = $entry->date;
                $wanted[$next]['sources'][] = $entry->label;
            }
        }

        ksort($wanted);
        $targets = [];

        foreach ($wanted as $index => $parts) {
            $row = $ordered[$index];
            $slot = $row['slot'];

            $content = [] === $parts['content'] ? null : implode(self::JOIN, $parts['content']);
            $homework = [] === $parts['homework'] ? null : implode(self::JOIN, $parts['homework']);

            $targets[] = new EcoleDirecteLessonLogTarget(
                $row['key'],
                $slot,
                $row['date'],
                $row['start'],
                self::text($slot['entityLibelle'] ?? null),
                self::text($slot['matiereLibelle'] ?? null),
                $content,
                null === $content ? null : EcoleDirecteSendState::between(self::currentHtml($slot, 'seance'), $content),
                $homework,
                null === $homework ? null : EcoleDirecteSendState::between(self::currentHtml($slot, 'aFaire'), $homework),
                $parts['givenOn'],
                array_values(array_unique($parts['sources'])),
            );
        }

        return ['targets' => $targets, 'unmatched' => $unmatched, 'unplaced' => $unplaced];
    }

    /**
     * What École Directe currently holds in one part of a slot, decoded - '' when nothing is written,
     * null when the slot says something is written but its text did not come back.
     *
     * @param array<array-key, mixed> $slot
     * @param 'seance'|'aFaire'       $part
     */
    public static function currentHtml(array $slot, string $part): ?string
    {
        $record = $slot[$part] ?? null;
        $flag = 'seance' === $part ? ($slot['contenuDeSeance'] ?? null) : ($slot['travailAFaire'] ?? null);
        if ('seance' === $part && !\is_array($record) && \is_array($flag)) {
            $record = $flag;
        }

        $encoded = \is_array($record) ? ($record['contenu'] ?? null) : null;
        if (\is_string($encoded)) {
            $decoded = base64_decode($encoded, true);

            return false === $decoded ? null : $decoded;
        }

        return true === $flag || 1 === $flag ? null : '';
    }

    /** @param list<array{slot: array<array-key, mixed>, date: string, start: string, key: string}> $ordered */
    private static function nextSameLesson(array $ordered, int $index): ?int
    {
        $lesson = self::lesson($ordered[$index]['slot']);
        $count = \count($ordered);

        for ($i = $index + 1; $i < $count; ++$i) {
            if ($ordered[$i]['date'] !== $ordered[$index]['date'] && self::lesson($ordered[$i]['slot']) === $lesson) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The day of the previous lesson of the same class and subject - when « travail avant » was, in
     * all likelihood, given.
     *
     * @param list<array{slot: array<array-key, mixed>, date: string, start: string, key: string}> $ordered
     */
    private static function previousDate(array $ordered, int $index): ?string
    {
        $lesson = self::lesson($ordered[$index]['slot']);

        for ($i = $index - 1; $i >= 0; --$i) {
            if ($ordered[$i]['date'] !== $ordered[$index]['date'] && self::lesson($ordered[$i]['slot']) === $lesson) {
                return $ordered[$i]['date'];
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $slot */
    private static function lesson(array $slot): string
    {
        return self::text($slot['entityCode'] ?? null).'|'.self::text($slot['matiereCode'] ?? null);
    }

    /** @param array<array-key, mixed> $slot */
    private static function key(array $slot, string $date, string $start): string
    {
        return $date.' '.$start.' '.self::lesson($slot);
    }

    /** @param array<array-key, mixed> $slot */
    private static function day(array $slot): string
    {
        $date = self::text($slot['date'] ?? null);

        return substr('' !== $date ? $date : self::text($slot['start_date'] ?? null), 0, 10);
    }

    private static function clock(string $dateTime): string
    {
        return 1 === preg_match('/(\d{2}:\d{2})/', $dateTime, $match) ? $match[1] : '';
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
