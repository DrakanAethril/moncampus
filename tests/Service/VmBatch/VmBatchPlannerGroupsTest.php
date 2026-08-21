<?php

declare(strict_types=1);

namespace App\Tests\Service\VmBatch;

use App\Service\VmBatch\BatchMember;
use App\Service\VmBatch\VmBatchPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Planning one machine per group, each shared by its members.
 *
 * The three rules pinned here are the ones that would go wrong silently: an empty group must not
 * consume a VMID, the number in a machine's name must be the group's number and not its rank among
 * the machines built, and every member of a group must reach the same machine - a group whose
 * members land on two VMIDs is the per-student shape wearing the wrong name.
 */
class VmBatchPlannerGroupsTest extends TestCase
{
    public function testOneMachinePerGroupCarryingEveryMember(): void
    {
        $rows = (new VmBatchPlanner())->planGroups([
            $this->group('Groupe 1', ['Célia L.', 'Marc T.']),
            $this->group('Groupe 2', ['Ana R.']),
        ], 'tp-{index}', 100, 999, []);

        self::assertCount(2, $rows);
        self::assertSame('tp-01', $rows[0]['guestName']);
        self::assertSame(100, $rows[0]['vmid']);
        self::assertSame(['celia-l', 'marc-t'], array_column($rows[0]['members'], 'login'));
        self::assertSame('tp-02', $rows[1]['guestName']);
        self::assertSame(101, $rows[1]['vmid']);
        self::assertSame(['ana-r'], array_column($rows[1]['members'], 'login'));
    }

    public function testAnEmptyGroupTakesNoMachineAndNoVmid(): void
    {
        $rows = (new VmBatchPlanner())->planGroups([
            $this->group('Groupe 1', ['Célia L.']),
            ['label' => 'Groupe 2', 'members' => []],
            $this->group('Groupe 3', ['Ana R.']),
        ], 'tp-{index}', 100, 999, []);

        self::assertCount(2, $rows);
        self::assertSame([100, 101], array_column($rows, 'vmid'));
    }

    public function testTheNameCarriesTheGroupNumberNotTheRankAmongTheMachines(): void
    {
        $rows = (new VmBatchPlanner())->planGroups([
            $this->group('Groupe 1', ['Célia L.']),
            ['label' => 'Groupe 2', 'members' => []],
            $this->group('Groupe 3', ['Ana R.']),
        ], 'tp-{index}', 100, 999, []);

        self::assertSame('tp-01', $rows[0]['guestName']);
        // Second machine built, third group of the set - the admin reads "Groupe 3" on the other
        // screen, so the machine may not be called tp-02.
        self::assertSame('tp-03', $rows[1]['guestName']);
        self::assertSame(3, $rows[1]['position']);
    }

    public function testTheLoginTokenRendersTheGroupSlug(): void
    {
        $rows = (new VmBatchPlanner())->planGroups([
            $this->group('Groupe 1', ['Célia L.']),
        ], 'tp-{login}', 100, 999, []);

        self::assertSame('tp-groupe-1', $rows[0]['guestName']);
        self::assertSame('groupe-1', $rows[0]['slug']);
    }

    /**
     * A group may name its own slug rather than have one derived from its label. « Groupe 3 » slugs
     * perfectly well; a machine labelled with the three names of the people on it does not, and the
     * name of a machine is its hostname.
     */
    public function testAGroupThatNamesItsOwnSlugKeepsIt(): void
    {
        $group = $this->group('Célia L., Ana R.', ['Célia L.', 'Ana R.']) + ['slug' => 'poste'];

        $rows = (new VmBatchPlanner())->planGroups([$group], 'tp-{login}', 100, 999, []);

        self::assertSame('poste', $rows[0]['slug']);
        self::assertSame('tp-poste', $rows[0]['guestName']);
        // The label is untouched: it is what the screen shows, and it is not a hostname.
        self::assertSame('Célia L., Ana R.', $rows[0]['groupLabel']);
    }

    public function testVmidsAlreadyTakenAreSkipped(): void
    {
        $rows = (new VmBatchPlanner())->planGroups([
            $this->group('Groupe 1', ['Célia L.']),
            $this->group('Groupe 2', ['Ana R.']),
        ], 'tp-{index}', 100, 999, [100, 101]);

        self::assertSame([102, 103], array_column($rows, 'vmid'));
    }

    public function testAWindowTooSmallPlansWhatFits(): void
    {
        $rows = (new VmBatchPlanner())->planGroups([
            $this->group('Groupe 1', ['Célia L.']),
            $this->group('Groupe 2', ['Ana R.']),
        ], 'tp-{index}', 100, 100, []);

        self::assertCount(1, $rows);
    }

    /**
     * @param list<string> $names
     *
     * @return array{label: string, members: list<BatchMember>}
     */
    private function group(string $label, array $names): array
    {
        return [
            'label' => $label,
            'members' => array_map(
                static fn (string $name, int $i): BatchMember => new BatchMember(
                    $i + 1,
                    $name,
                    strtolower(str_replace([' ', '.', 'é'], ['-', '', 'e'], $name)),
                ),
                $names,
                array_keys($names),
            ),
        ];
    }
}
