<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

use App\Enum\EcoleDirecteGradeState;
use App\Enum\GradeStatus;

/**
 * Decides, without sending anything, what one MonCampus evaluation would write into an École Directe
 * gradebook - and builds what École Directe's website posts to save grades.
 *
 * **Students are matched by name, exactly.** MonCampus holds no École Directe identifier, so a
 * MonCampus student is the École Directe student of the class whose surname and first name are the
 * same once accents, case and punctuation are set aside. No such student, or two of them, and the
 * grade is not sent: a grade entered under the wrong child is worse than one entered by hand.
 *
 * **The evaluation is found again by its name and date.** Sending twice updates the same École
 * Directe evaluation rather than creating a second one.
 */
final class EcoleDirecteGradePlanner
{
    private const string ABSENT = 'abs';
    private const string NOT_EVALUATED = 'ne';

    /**
     * @param list<EcoleDirecteGradeEntry> $grades
     * @param array<array-key, mixed>      $grid   the `data` of École Directe's notes route
     *
     * @return array{evaluation: ?array<array-key, mixed>, rows: list<EcoleDirecteGradeRow>, ecoleDirecteOnly: list<string>, refusal: ?string}
     */
    public function plan(array $grades, array $grid, string $name, string $date, float $scale): array
    {
        $evaluation = self::findEvaluation($grid, $name, $date);

        $refusal = null;
        if (null !== $evaluation && true === ($evaluation['readOnly'] ?? null)) {
            $refusal = 'ecoleDirecteEvaluationLockedMessage';
        } elseif (null !== $evaluation && is_numeric($evaluation['noteSur'] ?? null) && abs((float) $evaluation['noteSur'] - $scale) > 0.001) {
            $refusal = 'ecoleDirecteEvaluationScaleMismatchMessage';
        }

        $byName = [];
        $names = [];
        foreach (self::studentRows($grid) as $row) {
            $student = \is_array($row['eleve'] ?? null) ? $row['eleve'] : [];
            if (!\is_int($student['id'] ?? null)) {
                continue;
            }
            $lastName = trim(self::text($student['particule'] ?? null).' '.self::text($student['nom'] ?? null));
            $firstName = self::text($student['prenom'] ?? null);
            $key = self::nameKey($firstName, $lastName);
            $byName[$key][] = ['id' => $student['id'], 'row' => $row];
            $names[$student['id']] = trim($lastName.' '.$firstName);
            // A particle École Directe keeps apart may be written into the surname in MonCampus, or not.
            $bare = self::nameKey($firstName, self::text($student['nom'] ?? null));
            if ($bare !== $key) {
                $byName[$bare][] = ['id' => $student['id'], 'row' => $row];
            }
        }

        $rows = [];
        $matched = [];
        foreach ($grades as $grade) {
            $candidates = $byName[self::nameKey($grade->firstName, $grade->lastName)] ?? [];
            $value = $grade->note ?? '';

            if (1 !== \count($candidates)) {
                $rows[] = new EcoleDirecteGradeRow($grade->label(), null, $value, '', EcoleDirecteGradeState::NoMatch);
                continue;
            }

            $studentId = $candidates[0]['id'];
            $matched[$studentId] = true;
            $current = null === $evaluation ? '' : self::currentNote($candidates[0]['row'], $evaluation);

            $state = match (true) {
                null === $grade->note => EcoleDirecteGradeState::Empty,
                '' === $current => EcoleDirecteGradeState::New,
                self::sameNote($current, $grade->note) => EcoleDirecteGradeState::Same,
                default => EcoleDirecteGradeState::Replace,
            };

            $rows[] = new EcoleDirecteGradeRow($grade->label(), $studentId, $value, $current, $state);
        }

        $ecoleDirecteOnly = [];
        foreach ($names as $id => $label) {
            if (!isset($matched[$id])) {
                $ecoleDirecteOnly[] = $label;
            }
        }

        return ['evaluation' => $evaluation, 'rows' => $rows, 'ecoleDirecteOnly' => $ecoleDirecteOnly, 'refusal' => $refusal];
    }

    /**
     * The body École Directe's website posts to save the grades of one evaluation: the evaluation as
     * the grid returned it, with one line per student of the grid - the new grade where one is sent,
     * the grade already there otherwise, nothing for a student who has none. Every student is listed,
     * as the website does, so that saving never reads as "the others have no grade any more".
     *
     * @param array<array-key, mixed> $evaluation
     * @param array<array-key, mixed> $grid
     * @param array<int, string>      $values     École Directe student id => grade to write
     *
     * @return array{devoirs: list<array<array-key, mixed>>}
     */
    public static function notesBody(array $evaluation, array $grid, array $values): array
    {
        $id = $evaluation['id'] ?? null;
        $defaults = [
            'idNote' => 0,
            'idDevoir' => $id,
            'idPeriode' => $evaluation['idPeriode'] ?? '',
            'coef' => $evaluation['coef'] ?? 1,
            'note' => '',
            'noteSur' => $evaluation['noteSur'] ?? 20,
            'lettre' => '',
            'notationLettre' => $evaluation['notationLettre'] ?? false,
            'date' => $evaluation['date'] ?? '',
            'devoirLibelle' => $evaluation['libelle'] ?? '',
            'ccf' => $evaluation['ccf'] ?? false,
            'nonSignificatif' => $evaluation['nonSignificatif'] ?? false,
            'codeMatiere' => $evaluation['codeMatiere'] ?? '',
            'codeSSMatiere' => $evaluation['codeSSMatiere'] ?? '',
            'commentaire' => '',
            'elementsProgramme' => [],
        ];

        $students = [];
        foreach (self::studentRows($grid) as $row) {
            $student = \is_array($row['eleve'] ?? null) ? $row['eleve'] : [];
            if (!\is_int($student['id'] ?? null)) {
                continue;
            }

            $existing = self::cell($row, $evaluation);
            $notes = [];
            if (isset($values[$student['id']])) {
                $notes[] = [...$defaults, ...($existing ?? []), 'note' => $values[$student['id']], 'idDevoir' => $id];
            } elseif (null !== $existing) {
                $notes[] = [...$defaults, ...$existing, 'idDevoir' => $id];
            }

            $students[] = [
                'nom' => $student['nom'] ?? '',
                'prenom' => $student['prenom'] ?? '',
                'particule' => $student['particule'] ?? '',
                'id' => $student['id'],
                'devoirs' => $notes,
            ];
        }

        return ['devoirs' => [[...$evaluation, 'eleves' => $students]]];
    }

    /**
     * @param array<array-key, mixed> $grid
     *
     * @return array<array-key, mixed>|null
     */
    public static function findEvaluation(array $grid, string $name, string $date): ?array
    {
        foreach (\is_array($grid['devoirs'] ?? null) ? $grid['devoirs'] : [] as $evaluation) {
            if (\is_array($evaluation)
                && \is_int($evaluation['id'] ?? null)
                && self::text($evaluation['libelle'] ?? null) === trim($name)
                && substr(self::text($evaluation['date'] ?? null), 0, 10) === $date) {
                return $evaluation;
            }
        }

        return null;
    }

    /**
     * What École Directe shows for a MonCampus grade - the one place the two vocabularies meet.
     *
     * - a counted grade: the number (`14.5`);
     * - Excluded, a grade entered but not counted: the number in brackets (`(12)`), which is exactly
     *   how École Directe keeps a grade out of the average - and how MonCampus displays it too;
     * - Absent: `abs`; NotEvaluated and NotTested: `ne` (non évalué), École Directe's own codes.
     *
     * Null when there is nothing to write: a counted or excluded row without a number.
     */
    public static function noteFor(GradeStatus $status, ?float $value): ?string
    {
        return match ($status) {
            GradeStatus::Normal => null === $value ? null : self::format($value),
            GradeStatus::Excluded => null === $value ? null : '('.self::format($value).')',
            GradeStatus::Absent => self::ABSENT,
            GradeStatus::NotEvaluated, GradeStatus::NotTested => self::NOT_EVALUATED,
        };
    }

    /**
     * Two notes École Directe would show the same: numbers compared as numbers (`12,5` is `12.5`),
     * brackets and codes compared without case or blanks.
     */
    public static function sameNote(string $current, string $wanted): bool
    {
        $normalize = static fn (string $note): string => str_replace([' ', ','], ['', '.'], mb_strtolower(trim($note)));
        $a = $normalize($current);
        $b = $normalize($wanted);

        $unbracket = static fn (string $note): string => 1 === preg_match('/^\((.*)\)$/', $note, $match) ? $match[1] : $note;
        if (str_starts_with($a, '(') !== str_starts_with($b, '(')) {
            return false;
        }
        $a = $unbracket($a);
        $b = $unbracket($b);

        return is_numeric($a) && is_numeric($b) ? abs((float) $a - (float) $b) < 0.001 : $a === $b;
    }

    public static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public static function nameKey(string $firstName, string $lastName): string
    {
        $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $normalize = static function (string $text) use ($transliterator): string {
            $ascii = null !== $transliterator ? $transliterator->transliterate($text) : false;

            return preg_replace('/[^a-z]/', '', false === $ascii ? mb_strtolower($text) : $ascii) ?? '';
        };

        return $normalize($lastName).'|'.$normalize($firstName);
    }

    /**
     * @param array<array-key, mixed> $grid
     *
     * @return list<array<array-key, mixed>>
     */
    private static function studentRows(array $grid): array
    {
        return \is_array($grid['eleves'] ?? null) ? array_values(array_filter($grid['eleves'], 'is_array')) : [];
    }

    /**
     * @param array<array-key, mixed> $row
     * @param array<array-key, mixed> $evaluation
     *
     * @return array<string, mixed>|null
     */
    private static function cell(array $row, array $evaluation): ?array
    {
        $id = $evaluation['id'] ?? null;
        $cells = \is_array($row['devoirs'] ?? null) ? $row['devoirs'] : [];
        $cell = \is_int($id) ? ($cells[$id] ?? $cells[(string) $id] ?? null) : null;
        if (!\is_array($cell)) {
            return null;
        }

        $keyed = [];
        foreach ($cell as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /**
     * @param array<array-key, mixed> $row
     * @param array<array-key, mixed> $evaluation
     */
    private static function currentNote(array $row, array $evaluation): string
    {
        $cell = self::cell($row, $evaluation);
        $note = $cell['note'] ?? null;

        return \is_string($note) ? trim($note) : (is_numeric($note) ? (string) $note : '');
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
