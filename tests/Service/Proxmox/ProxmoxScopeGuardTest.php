<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxmox;

use App\Enum\ProxmoxAction;
use App\Service\Proxmox\ProxmoxGuest;
use App\Service\Proxmox\ProxmoxScope;
use App\Service\Proxmox\ProxmoxScopeGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The application-side half of the perimeter, written before the service it tests because it is
 * pure arithmetic over primitives and has no business needing a database to be judged.
 *
 * The perimeter is declared twice on purpose - once as Proxmox ACLs on a pool, once here - so this
 * guard's job is to refuse things the hypervisor's own account might well permit. It has to answer
 * three separate questions and never conflate them:
 *
 *   1. is this machine inside the perimeter at all (pool AND vmid range)?
 *   2. does the host allow this kind of action?
 *   3. does the request fit the quotas?
 *
 * The pairing that matters most is the first: a machine outside the perimeter stays *visible and
 * counted*, it simply cannot be acted upon. Conflating "out of scope" with "hidden" would make an
 * administrator think a machine had disappeared.
 */
class ProxmoxScopeGuardTest extends TestCase
{
    private function guard(): ProxmoxScopeGuard
    {
        return new ProxmoxScopeGuard();
    }

    private function scope(
        ?string $pool = 'moncampus',
        ?int $min = 200,
        ?int $max = 299,
        bool $allowStart = true,
        bool $allowStop = true,
        bool $allowCreate = true,
        ?int $maxGuests = null,
        ?int $maxCores = null,
        ?int $maxMemoryMib = null,
        ?int $maxDiskGib = null,
    ): ProxmoxScope {
        return new ProxmoxScope($pool, $min, $max, $allowStart, $allowStop, $allowCreate, $maxGuests, $maxCores, $maxMemoryMib, $maxDiskGib);
    }

    // --- the perimeter itself ---------------------------------------------------------------

    public function testAMachineInThePoolAndInTheRangeIsInScope(): void
    {
        self::assertTrue($this->guard()->covers($this->scope(), 204, 'moncampus'));
    }

    public function testTheRightVmidInTheWrongPoolIsOutOfScope(): void
    {
        // The trap this catches: a VMID inside the declared range says nothing on its own, because
        // the range is only half the declaration.
        self::assertFalse($this->guard()->covers($this->scope(), 204, 'infra'));
    }

    public function testTheRightPoolOutsideTheRangeIsOutOfScope(): void
    {
        self::assertFalse($this->guard()->covers($this->scope(), 401, 'moncampus'));
    }

    public function testAMachineInNoPoolAtAllIsOutOfScopeWhenAPoolIsDeclared(): void
    {
        self::assertFalse($this->guard()->covers($this->scope(), 204, null));
    }

    public function testTheBoundsAreInclusive(): void
    {
        $guard = $this->guard();

        self::assertTrue($guard->covers($this->scope(), 200, 'moncampus'));
        self::assertTrue($guard->covers($this->scope(), 299, 'moncampus'));
        self::assertFalse($guard->covers($this->scope(), 199, 'moncampus'));
        self::assertFalse($guard->covers($this->scope(), 300, 'moncampus'));
    }

    public function testAnUndeclaredPoolLeavesOnlyTheRangeGuarding(): void
    {
        $scope = $this->scope(pool: null);

        self::assertTrue($this->guard()->covers($scope, 204, 'anything'));
        self::assertTrue($this->guard()->covers($scope, 204, null));
        self::assertFalse($this->guard()->covers($scope, 401, 'anything'));
    }

    public function testAnUndeclaredRangeLeavesOnlyThePoolGuarding(): void
    {
        $scope = $this->scope(min: null, max: null);

        self::assertTrue($this->guard()->covers($scope, 999999, 'moncampus'));
        self::assertFalse($this->guard()->covers($scope, 204, 'infra'));
    }

    public function testAHalfDeclaredRangeStillGuardsTheHalfThatIsDeclared(): void
    {
        // A floor with no ceiling is a legitimate declaration ("everything from 200 up"), not a
        // broken one, and must not silently degrade into "everything".
        self::assertTrue($this->guard()->covers($this->scope(min: 200, max: null), 500, 'moncampus'));
        self::assertFalse($this->guard()->covers($this->scope(min: 200, max: null), 199, 'moncampus'));
        self::assertTrue($this->guard()->covers($this->scope(min: null, max: 299), 12, 'moncampus'));
        self::assertFalse($this->guard()->covers($this->scope(min: null, max: 299), 300, 'moncampus'));
    }

    public function testAScopeThatDeclaresNothingCoversEverything(): void
    {
        // The state of a freshly declared host: not yet narrowed. The Proxmox ACL is then the only
        // perimeter, which is exactly what the form warns about.
        self::assertTrue($this->guard()->covers($this->scope(pool: null, min: null, max: null), 7, null));
    }

    // --- the permissions --------------------------------------------------------------------

    /** @return iterable<string, array{ProxmoxAction, bool, bool, bool, bool}> */
    public static function permissionProvider(): iterable
    {
        // action, allowStart, allowStop, allowCreate, expected
        yield 'start needs start' => [ProxmoxAction::Start, true, false, false, true];
        yield 'start refused without it' => [ProxmoxAction::Start, false, true, true, false];
        yield 'reboot counts as start' => [ProxmoxAction::Reboot, true, false, false, true];
        yield 'reboot refused without start' => [ProxmoxAction::Reboot, false, true, true, false];
        yield 'shutdown needs stop' => [ProxmoxAction::Shutdown, false, true, false, true];
        yield 'stop needs stop' => [ProxmoxAction::Stop, false, true, false, true];
        yield 'stop refused without it' => [ProxmoxAction::Stop, true, false, true, false];
        yield 'clone needs create' => [ProxmoxAction::Clone, false, false, true, true];
        yield 'create refused without it' => [ProxmoxAction::Create, true, true, false, false];
    }

    #[DataProvider('permissionProvider')]
    public function testEachActionAsksTheRightSwitch(ProxmoxAction $action, bool $start, bool $stop, bool $create, bool $expected): void
    {
        $scope = $this->scope(allowStart: $start, allowStop: $stop, allowCreate: $create);

        self::assertSame($expected, $this->guard()->allows($scope, $action, 204, 'moncampus'));
    }

    public function testAPermittedActionOnAnOutOfScopeMachineIsStillRefused(): void
    {
        // The pairing that makes the guard worth having: every switch is on, and the machine is
        // still untouchable because it belongs to somebody else's pool.
        self::assertFalse($this->guard()->allows($this->scope(), ProxmoxAction::Start, 204, 'infra'));
    }

    public function testTheRefusalSaysWhichOfTheTwoReasonsApplies(): void
    {
        $guard = $this->guard();

        self::assertSame('proxmoxRefusalOutOfScope', $guard->refusal($this->scope(), ProxmoxAction::Start, 401, 'moncampus'));
        self::assertSame('proxmoxRefusalActionNotAllowed', $guard->refusal($this->scope(allowStart: false), ProxmoxAction::Start, 204, 'moncampus'));
        self::assertNull($guard->refusal($this->scope(), ProxmoxAction::Start, 204, 'moncampus'));
    }

    public function testOutOfScopeOutranksAForbiddenAction(): void
    {
        // Both are true; the perimeter is the one worth reporting, because turning the switch on
        // would not help.
        self::assertSame(
            'proxmoxRefusalOutOfScope',
            $this->guard()->refusal($this->scope(allowStart: false), ProxmoxAction::Start, 401, 'infra'),
        );
    }

    // --- the quotas -------------------------------------------------------------------------

    public function testAnUndeclaredQuotaNeverRefuses(): void
    {
        self::assertNull($this->guard()->quotaRefusal($this->scope(), cores: 64, memoryMib: 262144, diskGib: 4000, currentGuestCount: 900));
    }

    public function testEachQuotaRefusesOnItsOwn(): void
    {
        $guard = $this->guard();

        self::assertSame('proxmoxRefusalTooManyCores', $guard->quotaRefusal($this->scope(maxCores: 4), 8, 1024, 10, 0));
        self::assertSame('proxmoxRefusalTooMuchMemory', $guard->quotaRefusal($this->scope(maxMemoryMib: 4096), 2, 8192, 10, 0));
        self::assertSame('proxmoxRefusalTooMuchDisk', $guard->quotaRefusal($this->scope(maxDiskGib: 50), 2, 1024, 100, 0));
        self::assertSame('proxmoxRefusalTooManyGuests', $guard->quotaRefusal($this->scope(maxGuests: 24), 2, 1024, 10, 24));
    }

    public function testAQuotaIsAnInclusiveCeiling(): void
    {
        $guard = $this->guard();

        self::assertNull($guard->quotaRefusal($this->scope(maxCores: 4), 4, 1024, 10, 0), 'exactly the ceiling is allowed');
        self::assertNotNull($guard->quotaRefusal($this->scope(maxCores: 4), 5, 1024, 10, 0));
    }

    public function testTheGuestCeilingCountsTheMachineAboutToBeCreated(): void
    {
        $guard = $this->guard();

        // 23 existing + the one being asked for = 24, which is the ceiling: allowed.
        self::assertNull($guard->quotaRefusal($this->scope(maxGuests: 24), 2, 1024, 10, 23));
        self::assertNotNull($guard->quotaRefusal($this->scope(maxGuests: 24), 2, 1024, 10, 24));
    }

    public function testABatchOfSeveralMachinesIsWeighedAsAWhole(): void
    {
        // Deploying a batch is the case the per-machine check would miss: twenty-four requests
        // that each fit, and together do not.
        $guard = $this->guard();

        self::assertNull($guard->quotaRefusal($this->scope(maxGuests: 24), 2, 1024, 10, 0, requested: 24));
        self::assertSame('proxmoxRefusalTooManyGuests', $guard->quotaRefusal($this->scope(maxGuests: 24), 2, 1024, 10, 0, requested: 25));
    }

    // --- counting what the ceiling weighs ------------------------------------------------------

    private function guest(int $vmid, ?string $pool): ProxmoxGuest
    {
        return new ProxmoxGuest($vmid, 'vm-'.$vmid, 'pve', ProxmoxGuest::TYPE_QEMU, 'running', false, $pool, 2, 0.0, 2048, 512, 34359738368, 3600, null);
    }

    public function testTheCountWeighedAgainstTheCeilingIsTheOneInsideThePerimeter(): void
    {
        // The same three rules as covers(), applied to a list: a machine in another pool and a
        // machine outside the range are both real machines on the hypervisor, and neither of them
        // consumes this host's ceiling.
        $guests = [
            $this->guest(204, 'moncampus'),
            $this->guest(205, 'moncampus'),
            $this->guest(206, 'infra'),
            $this->guest(401, 'moncampus'),
            $this->guest(207, null),
        ];

        self::assertSame(2, $this->guard()->countCovered($this->scope(), $guests));
    }

    public function testAnUndeclaredPerimeterCountsEverything(): void
    {
        // "No pool and no range" is not "nothing is in scope" - it is a host whose perimeter is the
        // whole hypervisor, and the ceiling then weighs every machine on it.
        $guests = [$this->guest(204, 'moncampus'), $this->guest(9001, 'infra'), $this->guest(3, null)];

        self::assertSame(3, $this->guard()->countCovered($this->scope(pool: null, min: null, max: null), $guests));
    }
}
