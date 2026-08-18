<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a batch turns a class into machines.
 *
 * **Only one shape is built.** The design names three - one machine per student, one per group with
 * individual accounts, one per group shared - and the decision taken when this was built is to
 * ship the first and only the first. The enum keeps the other two out rather than declaring cases
 * nothing produces: a value that can be stored and never means anything is worse than a value that
 * does not exist, and adding a case later is a two-line change.
 *
 * What the per-student shape *does* have is targeting: a batch starts from a Program and can be
 * narrowed to the students following particular Options and/or Modalities within it, so "one
 * machine per student of SIO2" and "one per student of SIO2 taking SISR" are both expressible.
 */
enum VmBatchShape: string
{
    /** One machine per student of the program, after the option/modality filters. */
    case PerStudent = 'per_student';

    public function labelKey(): string
    {
        return match ($this) {
            self::PerStudent => 'vmBatchPerStudentLabel',
        };
    }

    public function descriptionKey(): string
    {
        return match ($this) {
            self::PerStudent => 'vmBatchPerStudentDescription',
        };
    }
}
