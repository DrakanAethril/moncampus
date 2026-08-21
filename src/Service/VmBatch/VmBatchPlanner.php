<?php

declare(strict_types=1);

namespace App\Service\VmBatch;

use App\Service\Network\GuestNetworkConfigurator;

/**
 * Turns a class into a list of machines: who is in it, what each machine is called, and which VMID
 * each one takes.
 *
 * Pure - plain values in, plain rows out - so the whole plan can be shown on a confirmation screen
 * before a single call goes to the hypervisor, and judged in a test without either.
 *
 * **The filters apply to the student, not to the class.** A Program *offers* SISR and SLAM; which
 * students actually take which is a per-student fact (App\Entity\ProgramStudentOption). Filtering
 * on what the program offers would match every student of the class - which is the difference
 * between eight machines and twenty-four.
 *
 * Names are corrected rather than trusted: on a QEMU machine the name *is* the hostname, since
 * Proxmox derives cloud-init's `local-hostname` from it, so a pattern producing `TP Réseau 01`
 * would boot a machine nobody can resolve.
 */
class VmBatchPlanner
{
    public function __construct(private readonly GuestNetworkConfigurator $configurator = new GuestNetworkConfigurator())
    {
    }

    /**
     * The students a batch is for.
     *
     * Empty filters mean everyone - the absence is the meaning, the same convention as the
     * shared-documents targeting. Several options are a union ("the SISR *and* the SLAM students"),
     * while options and modalities together narrow.
     *
     * @param list<BatchMember> $members
     * @param list<int>         $optionIds
     * @param list<int>         $modalityIds
     *
     * @return list<BatchMember>
     */
    public function select(array $members, array $optionIds, array $modalityIds): array
    {
        return array_values(array_filter($members, static function (BatchMember $member) use ($optionIds, $modalityIds): bool {
            if ([] !== $optionIds && [] === array_intersect($optionIds, $member->optionIds)) {
                return false;
            }

            return [] === $modalityIds || [] !== array_intersect($modalityIds, $member->modalityIds);
        }));
    }

    /**
     * One row per machine to build.
     *
     * A window too small for the class plans what fits rather than refusing outright: eighteen of
     * twenty-four machines is a useful outcome, and the screen says what did not fit.
     *
     * @param list<BatchMember> $members
     * @param list<int>         $usedVmids VMIDs already taken on the host
     *
     * @return list<array{userId: int, studentLabel: string, login: string, guestName: string, vmid: int, position: int}>
     */
    public function plan(array $members, string $namePattern, int $vmidMin, int $vmidMax, array $usedVmids): array
    {
        $taken = array_flip($usedVmids);
        $names = [];
        $rows = [];
        $vmid = $vmidMin;

        foreach ($members as $index => $member) {
            while (isset($taken[$vmid]) && $vmid <= $vmidMax) {
                ++$vmid;
            }

            if ($vmid > $vmidMax) {
                break;
            }

            $name = $this->uniqueName($this->name($namePattern, $index + 1, $member->login), $names);
            $names[$name] = true;

            $rows[] = [
                'userId' => $member->userId,
                'studentLabel' => $member->displayName,
                'login' => $member->login,
                'guestName' => $name,
                'vmid' => $vmid,
                'position' => $index + 1,
            ];

            $taken[$vmid] = true;
            ++$vmid;
        }

        return $rows;
    }

    /**
     * One row per machine, when the machines are groups rather than students.
     *
     * The differences with plan() above are the ones that matter for the shape: a row carries the
     * whole group instead of one person, and the number in the name is the group's own number in
     * the set - never its rank among the machines actually built. A group everybody has been
     * dragged out of gets no machine, and if that is group 2, the third group still comes out as
     * `tp-03`: the admin is reading the same numbers on the group-creation screen.
     *
     * `{login}` has no single value here, so it renders the group's slug. The machine's own `login`
     * is that slug too - it names the machine, and never becomes an account.
     *
     * A group may carry its own `slug` rather than have one derived from its label. Groups of a set
     * are called « Groupe 3 » and slug perfectly well; a machine named after the people on it does
     * not, and its label is three names long - see BatchMemberResolver::forUsers().
     *
     * @param list<array{label: string, slug?: string, members: list<BatchMember>}> $groups    in set order, empty groups included
     * @param list<int>                                             $usedVmids VMIDs already taken on the host
     *
     * @return list<array{groupLabel: string, slug: string, members: list<array{userId: int, label: string, login: string}>, guestName: string, vmid: int, position: int}>
     */
    public function planGroups(array $groups, string $namePattern, int $vmidMin, int $vmidMax, array $usedVmids): array
    {
        $taken = array_flip($usedVmids);
        $names = [];
        $rows = [];
        $vmid = $vmidMin;

        foreach ($groups as $index => $group) {
            if ([] === $group['members']) {
                continue;
            }

            while (isset($taken[$vmid]) && $vmid <= $vmidMax) {
                ++$vmid;
            }

            if ($vmid > $vmidMax) {
                break;
            }

            $slug = $this->configurator->suggestHostname($group['slug'] ?? $group['label']);
            $name = $this->uniqueName($this->name($namePattern, $index + 1, $slug), $names);
            $names[$name] = true;

            $rows[] = [
                'groupLabel' => $group['label'],
                'slug' => $slug,
                'members' => array_map(
                    static fn (BatchMember $member): array => [
                        'userId' => $member->userId,
                        'label' => $member->displayName,
                        'login' => $member->login,
                    ],
                    $group['members'],
                ),
                'guestName' => $name,
                'vmid' => $vmid,
                'position' => $index + 1,
            ];

            $taken[$vmid] = true;
            ++$vmid;
        }

        return $rows;
    }

    private function name(string $pattern, int $index, string $login): string
    {
        $rendered = str_replace(
            ['{index}', '{login}'],
            // Zero-padded, so `tp-2` sorts before `tp-10` in every list that shows them.
            [str_pad((string) $index, 2, '0', \STR_PAD_LEFT), $login],
            $pattern,
        );

        // The name is the hostname - see the class docblock - so it goes through the same
        // correction the creation wizard applies to a name typed by hand.
        return $this->configurator->suggestHostname($rendered);
    }

    /**
     * @param array<string, true> $taken
     */
    private function uniqueName(string $name, array $taken): string
    {
        if (!isset($taken[$name])) {
            return $name;
        }

        // A pattern with no {index}, or two students whose names collapse to the same slug. Neither
        // is worth refusing over; both need distinct machines.
        $suffix = 2;
        while (isset($taken[$name.'-'.$suffix])) {
            ++$suffix;
        }

        return $name.'-'.$suffix;
    }
}
