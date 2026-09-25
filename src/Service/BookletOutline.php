<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The outline of the Livret de l'alternant: every section, its number and its anchor, in order.
 *
 * It is the single source of the booklet's own sommaire (templates/internship/booklet.html.twig)
 * and of the reader's menu (templates/internship/_livret_reader.html.twig): chapter I carries as
 * many sections as the modalités de contrat have, so neither list can be written by hand any more.
 *
 * A fixed section names a translation key - the reader follows the interface's language, the
 * booklet itself is always printed in French - and a section of the modalités carries the text of
 * its own heading.
 *
 * @phpstan-import-type BookletSection from BookletFreeText
 *
 * @phpstan-type BookletOutlineEntry array{number: string, labelKey: string|null, label: string|null, anchor: string, level: int, printed: bool}
 */
final class BookletOutline
{
    /**
     * @param list<BookletSection>     $modalitySections the numbered sections of the modalités de contrat
     * @param array<array-key, string> $periodNames      the evaluation periods, in booklet order
     *
     * @return list<BookletOutlineEntry>
     */
    public function entries(array $modalitySections, bool $hasTimetable, array $periodNames): array
    {
        $entries = [
            self::fixed('I.', 'ufaAlternanceLivretSectionActorsLabel', 'section-i', 1),
            self::fixed('1.', 'ufaAlternanceLivretSectionStudentLabel', 'section-i-1', 2),
            self::fixed('2.', 'ufaAlternanceLivretSectionEmployerLabel', 'section-i-2', 2),
            self::fixed('3.', 'ufaAlternanceLivretSectionCenterLabel', 'section-i-3', 2),
            self::fixed('4.', 'ufaAlternanceLivretSectionTeamLabel', 'section-i-4', 2),
        ];

        foreach ($modalitySections as $section) {
            $entries[] = ['number' => $section['number'].'.', 'labelKey' => null, 'label' => $section['label'], 'anchor' => $section['anchor'], 'level' => 2, 'printed' => true];
        }

        $entries[] = self::fixed('II.', 'ufaAlternanceLivretSectionPedagogicalLabel', 'section-ii', 1);
        $entries[] = self::fixed('1.', 'ufaAlternanceLivretSectionCalendarLabel', 'section-ii-1', 2);
        // The emploi du temps is a section only when the formation deposited one, and it is what
        // pushes the exam modalities from 2 to 3.
        if ($hasTimetable) {
            $entries[] = self::fixed('2.', 'ufaAlternanceLivretSectionTimetableLabel', 'section-ii-2', 2);
        }
        $examNumber = $hasTimetable ? 3 : 2;
        $entries[] = self::fixed($examNumber.'.', 'ufaAlternanceLivretSectionExamLabel', 'section-ii-'.$examNumber, 2);
        $entries[] = self::fixed('III.', 'ufaAlternanceLivretSectionFollowUpLabel', 'section-iii', 1);

        foreach (array_values($periodNames) as $index => $name) {
            $entries[] = ['number' => '', 'labelKey' => null, 'label' => $name, 'anchor' => 'section-period-'.($index + 1), 'level' => 2, 'printed' => false];
        }

        return $entries;
    }

    /** @return BookletOutlineEntry */
    private static function fixed(string $number, string $labelKey, string $anchor, int $level): array
    {
        return ['number' => $number, 'labelKey' => $labelKey, 'label' => null, 'anchor' => $anchor, 'level' => $level, 'printed' => true];
    }
}
