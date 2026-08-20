<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\ProxmoxAction;
use PHPUnit\Framework\TestCase;

/**
 * The rule under test is `usesProvisioningAccount()`, and it is not a permission rule: it says
 * which of the two service accounts *issues* an action, and therefore which one owns the Proxmox
 * task it opens.
 *
 * It is worth pinning because getting it wrong is not a crash but a demand for a privilege. Proxmox
 * lets an account read the status of its own tasks with no privilege at all and answers HTTP 403
 * `(/nodes/<node>, Sys.Audit)` for anyone else's - so an action attributed to the wrong account
 * quietly forces `Sys.Audit` onto the everyday credentials this design keeps as small as it can.
 */
class ProxmoxActionTest extends TestCase
{
    public function testCreatingActionsAreIssuedByTheProvisioningAccount(): void
    {
        self::assertTrue(ProxmoxAction::Clone->usesProvisioningAccount());
        self::assertTrue(ProxmoxAction::Create->usesProvisioningAccount());
    }

    public function testPowerActionsAreIssuedByTheEverydayAccount(): void
    {
        foreach ([ProxmoxAction::Start, ProxmoxAction::Shutdown, ProxmoxAction::Stop, ProxmoxAction::Reboot] as $action) {
            self::assertFalse($action->usesProvisioningAccount(), $action->value.' is an everyday action.');
        }
    }

    /**
     * These two never reach the Proxmox API at all - they act inside a machine over SSH - so they
     * open no task and could answer either way. They answer "everyday" because that is the account
     * a host is guaranteed to have: `provision()` throws on a host that carries no second
     * credential set.
     */
    public function testActionsThatDoNotTouchTheHypervisorStayOnTheEverydayAccount(): void
    {
        self::assertFalse(ProxmoxAction::Provision->usesProvisioningAccount());
        self::assertFalse(ProxmoxAction::PostInstall->usesProvisioningAccount());
    }

    /**
     * No action escapes the rule, and none is classified by accident.
     *
     * Two things make this fail rather than drift: calling the method over every case raises
     * \UnhandledMatchError the moment someone adds an action the match does not name, and pinning
     * the provisioning set by value means a new *creating* action has to be added here on purpose.
     */
    public function testNoActionEscapesTheRule(): void
    {
        $provisioning = [];
        $everyday = [];

        foreach (ProxmoxAction::cases() as $action) {
            if ($action->usesProvisioningAccount()) {
                $provisioning[] = $action->value;
            } else {
                $everyday[] = $action->value;
            }
        }

        self::assertSame(['clone', 'create'], $provisioning);
        self::assertCount(\count(ProxmoxAction::cases()) - 2, $everyday);
    }
}
