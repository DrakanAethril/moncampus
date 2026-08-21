<?php

declare(strict_types=1);

namespace App\Tests\Service\VmBatch;

use App\Service\VmBatch\BatchMember;
use App\Service\VmBatch\VmBatchPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Turning a class into a list of machines: who is in, what each one is called, and which VMIDs they
 * take.
 *
 * Pure, and therefore judged here rather than through a wizard. The part that carries the most
 * risk is the targeting: **the filters apply to the student, not to the class**. A Program *offers*
 * SISR and SLAM; which students take which is a per-student fact, and filtering on what the program
 * offers would deploy to everybody - the exact mistake that turns "one machine for the eight SISR
 * students" into twenty-four machines.
 *
 * The second is the naming: twenty-four machines need twenty-four predictable names, they have to
 * be valid hostnames, and two students called Dupont must not collide.
 */
class VmBatchPlannerTest extends TestCase
{
    private function planner(): VmBatchPlanner
    {
        return new VmBatchPlanner();
    }

    /** @return list<BatchMember> */

    /**
     * The teachers a per-class batch names: one account each on every machine, and no extra
     * machine. The student is in the list too - a machine with members carries **only** its
     * members, so leaving them out would build their machine for their teacher and not for them.
     */
    public function testANamedTeacherJoinsEveryMachineAlongsideItsStudent(): void
    {
        $rows = $this->planner()->plan(
            [new BatchMember(1, 'Célia L.', 'celia.l'), new BatchMember(2, 'Ana R.', 'ana.r')],
            'tp-{index}',
            100,
            999,
            [],
            [new BatchMember(90, 'M. Roux', 'p.roux')],
        );

        self::assertCount(2, $rows, 'a named teacher adds no machine');
        self::assertSame(['celia.l', 'p.roux'], array_column($rows[0]['members'], 'login'));
        self::assertSame(['ana.r', 'p.roux'], array_column($rows[1]['members'], 'login'));
    }

    /**
     * With nobody named, a machine carries no member list at all - which is what VmBatchExecutor
     * reads as "this machine is one person's", and the difference between one account and two.
     */
    public function testAMachineWithNobodyElseOnItCarriesNoMemberList(): void
    {
        $rows = $this->planner()->plan([new BatchMember(1, 'Célia L.', 'celia.l')], 'tp-{index}', 100, 999, []);

        self::assertSame([], $rows[0]['members']);
        self::assertSame('celia.l', $rows[0]['login']);
    }

    private function classOf(): array
    {
        return [
            new BatchMember(1, 'Marie Dupont', 'marie-dupont', optionIds: [10], modalityIds: [100]),
            new BatchMember(2, 'Jean Martin', 'jean-martin', optionIds: [11], modalityIds: [100]),
            new BatchMember(3, 'Léa Bernard', 'lea-bernard', optionIds: [10, 11], modalityIds: [101]),
            new BatchMember(4, 'Paul Simon', 'paul-simon', optionIds: [], modalityIds: []),
        ];
    }

    // --- targeting --------------------------------------------------------------------------

    public function testNoFilterTakesTheWholeClass(): void
    {
        // The absence is the meaning, the same convention as the shared-documents targeting: an
        // empty set is "everyone", not "nobody".
        self::assertCount(4, $this->planner()->select($this->classOf(), [], []));
    }

    public function testAnOptionFilterKeepsOnlyTheStudentsWhoTakeIt(): void
    {
        $selected = $this->planner()->select($this->classOf(), [10], []);

        self::assertSame(['marie-dupont', 'lea-bernard'], array_column($selected, 'login'));
    }

    public function testSeveralOptionsAreAUnionRatherThanAnIntersection(): void
    {
        // "The SISR students and the SLAM students" is what somebody ticking two boxes means.
        $selected = $this->planner()->select($this->classOf(), [10, 11], []);

        self::assertCount(3, $selected);
    }

    public function testAModalityFilterWorksTheSameWay(): void
    {
        $selected = $this->planner()->select($this->classOf(), [], [101]);

        self::assertSame(['lea-bernard'], array_column($selected, 'login'));
    }

    public function testBothFiltersTogetherNarrowRatherThanWiden(): void
    {
        // Option 10 gives Marie and Léa; modality 100 gives Marie and Jean. Both means Marie.
        $selected = $this->planner()->select($this->classOf(), [10], [100]);

        self::assertSame(['marie-dupont'], array_column($selected, 'login'));
    }

    public function testAFilterThatMatchesNobodyYieldsNobody(): void
    {
        // And it must not silently fall back to the whole class - deploying twenty-four machines
        // because a filter was wrong is the failure this whole targeting exists to prevent.
        self::assertSame([], $this->planner()->select($this->classOf(), [999], []));
    }

    public function testAStudentWithNoOptionAtAllIsExcludedByAnOptionFilter(): void
    {
        $selected = $this->planner()->select($this->classOf(), [10, 11], []);

        self::assertNotContains('paul-simon', array_column($selected, 'login'));
    }

    // --- naming -----------------------------------------------------------------------------

    public function testTheIndexIsSubstitutedAndPaddedSoNamesSortRight(): void
    {
        $plan = $this->planner()->plan($this->classOf(), 'tp-{index}', 200, 299, []);

        self::assertSame(['tp-01', 'tp-02', 'tp-03', 'tp-04'], array_column($plan, 'guestName'));
    }

    public function testTheLoginCanBeUsedInTheName(): void
    {
        $plan = $this->planner()->plan([$this->classOf()[0]], 'sio2-{login}', 200, 299, []);

        self::assertSame('sio2-marie-dupont', $plan[0]['guestName']);
    }

    public function testAPatternProducingAnInvalidHostnameIsCorrected(): void
    {
        // A QEMU machine's name *is* its hostname - Proxmox derives cloud-init's local-hostname
        // from it - so a name with a space or an accent boots a machine nobody can resolve.
        $plan = $this->planner()->plan([$this->classOf()[0]], 'TP Réseau {index}', 200, 299, []);

        self::assertSame('tp-reseau-01', $plan[0]['guestName']);
    }

    public function testNamesAreUniqueEvenWhenThePatternIsNot(): void
    {
        // Two Duponts, or a pattern with no {index} at all: the machines still need distinct names.
        $plan = $this->planner()->plan($this->classOf(), 'poste', 200, 299, []);
        $names = array_column($plan, 'guestName');

        self::assertSame($names, array_unique($names));
        self::assertCount(4, $names);
    }

    // --- VMIDs ------------------------------------------------------------------------------

    public function testVmidsComeFromTheDeclaredWindow(): void
    {
        $plan = $this->planner()->plan($this->classOf(), 'tp-{index}', 200, 299, []);

        self::assertSame([200, 201, 202, 203], array_column($plan, 'vmid'));
    }

    public function testVmidsAlreadyInUseAreSkipped(): void
    {
        $plan = $this->planner()->plan($this->classOf(), 'tp-{index}', 200, 299, [200, 201, 205]);

        self::assertSame([202, 203, 204, 206], array_column($plan, 'vmid'));
    }

    public function testAWindowTooSmallPlansWhatItCanAndSaysSo(): void
    {
        // Better than refusing outright: eighteen of twenty-four machines is a useful outcome, and
        // the screen can say what did not fit.
        $plan = $this->planner()->plan($this->classOf(), 'tp-{index}', 200, 201, []);

        self::assertCount(2, $plan);
    }

    public function testAnEmptySelectionPlansNothing(): void
    {
        self::assertSame([], $this->planner()->plan([], 'tp-{index}', 200, 299, []));
    }

    public function testEachEntryCarriesItsStudent(): void
    {
        $plan = $this->planner()->plan($this->classOf(), 'tp-{index}', 200, 299, []);

        self::assertSame(1, $plan[0]['userId']);
        self::assertSame('Marie Dupont', $plan[0]['studentLabel']);
        self::assertSame('marie-dupont', $plan[0]['login']);
    }
}
