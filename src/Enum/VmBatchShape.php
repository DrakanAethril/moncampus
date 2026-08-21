<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a batch turns a class into machines.
 *
 * The shapes differ in what a machine is *for*, not merely in how many are made. Per student, the
 * machine and the account are the same fact. Per group, the machine belongs to a group and carries
 * **one account per member**, so the work is shared while the login that did it is not - which is
 * the whole point of the shape, and why GuestAccount is keyed on (host, node, vmid, login) rather
 * than on the machine alone.
 *
 * ForAccounts is the third, and it is what makes this screen the only way a machine is ever
 * created: one machine, for the people named on it, chosen one by one from the platform rather than
 * read out of a class. It is the shape of "I need a machine for these three", and of "I need a
 * machine" - a single account is not a special case of anything, it is this shape with one name in
 * it. Mechanically it is a per-group batch whose set holds exactly one group, which is why it needs
 * no planning of its own.
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

    /**
     * One machine for accounts picked by hand - students, teachers, or a mix - each getting their
     * own Unix account on it. The class is not the targeting here: the names are.
     */
    case ForAccounts = 'for_accounts';

    public function labelKey(): string
    {
        return match ($this) {
            self::PerStudent => 'vmBatchPerStudentLabel',
            self::PerGroup => 'vmBatchPerGroupLabel',
            self::ForAccounts => 'vmBatchForAccountsLabel',
        };
    }

    public function descriptionKey(): string
    {
        return match ($this) {
            self::PerStudent => 'vmBatchPerStudentDescription',
            self::PerGroup => 'vmBatchPerGroupDescription',
            self::ForAccounts => 'vmBatchForAccountsDescription',
        };
    }

    /**
     * Whether this shape builds machines that carry several accounts - which is the same thing as
     * saying it plans through App\Service\VmBatch\VmBatchPlanner::planGroups(). Asked in three
     * places that would otherwise each spell out a list of cases and drift apart.
     */
    public function isMultiAccount(): bool
    {
        return self::PerStudent !== $this;
    }
}
