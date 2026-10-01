<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The three sizes a student filters on, each a set of INSEE headcount brackets (« tranches
 * d'effectif salarié »). The register's own brackets are finer; a student asks « une petite
 * équipe ou une grosse structure ? », not « 20 à 49 ».
 *
 * `NN` - no employee, or headcount unknown - belongs to none of them: it is the separate
 * « effectif non renseigné » box, because a fair share of real employers carry it.
 */
enum EmployeeBand: string
{
    case Micro = 'micro';
    case Medium = 'medium';
    case Large = 'large';

    public const string UNKNOWN_BRACKET = 'NN';

    /** @return list<string> */
    public function brackets(): array
    {
        return match ($this) {
            self::Micro => ['01', '02', '03'],
            self::Medium => ['11', '12', '21', '22', '31'],
            self::Large => ['32', '41', '42', '51', '52', '53'],
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Micro => 'employeeBandMicroLabel',
            self::Medium => 'employeeBandMediumLabel',
            self::Large => 'employeeBandLargeLabel',
        };
    }

    /**
     * The wording of one INSEE bracket as the register gives it (`'12'` → « 20 à 49 salariés »).
     * Null or `NN` read « Effectif non renseigné »; an unknown code is shown as such rather than
     * guessed at.
     *
     * @return array{key: string, params: array<string, string>}
     */
    public static function bracketLabel(?string $bracket): array
    {
        $ranges = [
            '00' => ['0', '0'], '01' => ['1', '2'], '02' => ['3', '5'], '03' => ['6', '9'],
            '11' => ['10', '19'], '12' => ['20', '49'], '21' => ['50', '99'], '22' => ['100', '199'],
            '31' => ['200', '249'], '32' => ['250', '499'], '41' => ['500', '999'],
            '42' => ['1 000', '1 999'], '51' => ['2 000', '4 999'], '52' => ['5 000', '9 999'],
        ];

        if (null === $bracket || self::UNKNOWN_BRACKET === $bracket) {
            return ['key' => 'employeeBracketUnknownLabel', 'params' => []];
        }

        if ('53' === $bracket) {
            return ['key' => 'employeeBracketAtLeastLabel', 'params' => ['%min%' => '10 000']];
        }

        if ('00' === $bracket) {
            return ['key' => 'employeeBracketNoneLabel', 'params' => []];
        }

        if (!isset($ranges[$bracket])) {
            return ['key' => 'employeeBracketUnknownLabel', 'params' => []];
        }

        return ['key' => 'employeeBracketRangeLabel', 'params' => ['%min%' => $ranges[$bracket][0], '%max%' => $ranges[$bracket][1]]];
    }
}
