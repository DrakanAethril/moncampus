<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which of the two Qualiopi documents « Exporter en PDF » produces - the choice offered in the
 * submenu of the button, on templates/progression/manage_list.html.twig.
 *
 * The two answer the same question with a different price. The dated one is the document of record:
 * every hour it prints sits on a validated placement, on a dated créneau an auditor can look up.
 * It costs the teacher the placement work, and it goes stale every time the timetable moves.
 *
 * The undated one exists because that price is too high to pay for a justification file: it drops
 * the dates entirely and prints volumes and months, counting the auto-planner's proposal wherever
 * nothing was validated. It is a global view of what will be (or was) taught, deliberately not an
 * exact one - a séance the planner found no créneau for is simply not counted, and the document
 * says how many there were rather than pretending the year is shorter than it is.
 *
 * The two never mix inside one séance: see ProgressionQualiopiBuilder::countedPlacements().
 */
enum ProgressionExportMode: string
{
    /** Validated placements only, with the dates and hours they carry. */
    case Dated = 'dated';

    /** Volumes and months, the planner's proposal standing in for what nobody validated. */
    case Undated = 'undated';

    /**
     * The mode a query string asks for, falling back to the document of record.
     *
     * Anything unreadable - an absent parameter, an empty string, a hand-typed value - is the dated
     * one: it is what every link produced before the submenu existed, and the one whose figures need
     * no caveat.
     */
    public static function fromRequestValue(string $value): self
    {
        return self::tryFrom($value) ?? self::Dated;
    }
}
