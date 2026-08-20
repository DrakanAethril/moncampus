<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a batch turns a class into machines.
 *
 * **Two of the three shapes are built.** The design names three - one machine per student, one per
 * group with individual accounts, one per group shared - and the third stays out rather than
 * declaring a case nothing produces: a value that can be stored and never means anything is worse
 * than a value that does not exist.
 *
 * The two that exist differ in what a machine is *for*, not merely in how many are made. Per
 * student, the machine and the account are the same fact. Per group, the machine belongs to a
 * group and carries **one account per member**, so the work is shared while the login that did it
 * is not - which is the whole point of the shape, and why GuestAccount is keyed on
 * (host, node, vmid, login) rather than on the machine alone.
 *
 * What the per-student shape *does* have is targeting: a batch starts from a Program and can be
 * narrowed to the students following particular Options and/or Modalities within it, so "one
 * machine per student of SIO2" and "one per student of SIO2 taking SISR" are both expressible.
 */
enum VmBatchShape: string
{
    /** One machine per student of the program, after the option/modality filters. */
    case PerStudent = 'per_student';

    /**
     * One machine per group of a saved set (App\Entity\GroupBatch), each carrying one Unix account
     * per member of that group. The set replaces the class roster as the targeting: the groups
     * already say who is concerned, so the option/modality filters do not apply.
     */
    case PerGroup = 'per_group';

    public function labelKey(): string
    {
        return match ($this) {
            self::PerStudent => 'vmBatchPerStudentLabel',
            self::PerGroup => 'vmBatchPerGroupLabel',
        };
    }

    public function descriptionKey(): string
    {
        return match ($this) {
            self::PerStudent => 'vmBatchPerStudentDescription',
            self::PerGroup => 'vmBatchPerGroupDescription',
        };
    }
}
