<?php

declare(strict_types=1);

namespace App\Tests\Service\Network;

use App\Service\Network\GuestNetworkConfigurator;
use App\Service\Network\InvalidHostnameException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Building what gets written into a guest before its first boot - the mirror of
 * GuestAddressReaderTest, from the same inputs to the two shapes Proxmox accepts.
 *
 * The hostname rules are the sharp edge here. A QEMU virtual machine has **no hostname option at
 * all**: Proxmox derives cloud-init's `local-hostname` from the VM's *name*, so the single field on
 * screen has to satisfy hostname rules rather than VM-name rules - lowercase letters, digits and
 * hyphens, 63 characters, no hyphen at either end. Nothing enforces that on the Proxmox side, and
 * a machine named `Serveur Web (TP)` boots with a hostname nobody can resolve or ssh to.
 */
class GuestNetworkConfiguratorTest extends TestCase
{
    private function configurator(): GuestNetworkConfigurator
    {
        return new GuestNetworkConfigurator();
    }

    public function testQemuGetsIpconfig0AndAnInterface(): void
    {
        $parameters = $this->configurator()->qemuParameters(
            hostname: 'srv-web-07',
            ip: '10.30.20.57',
            prefixLength: 24,
            gateway: '10.30.20.1',
            bridge: 'vmbr0',
            vlan: 20,
        );

        self::assertSame('srv-web-07', $parameters['name']);
        self::assertSame('ip=10.30.20.57/24,gw=10.30.20.1', $parameters['ipconfig0']);
        self::assertSame('virtio,bridge=vmbr0,tag=20', $parameters['net0']);
    }

    public function testNoVlanMeansNoTag(): void
    {
        // `tag=` with an empty value is not the same as no tag: Proxmox refuses it.
        $parameters = $this->configurator()->qemuParameters('srv-web-07', '10.30.20.57', 24, '10.30.20.1', 'vmbr0', null);

        self::assertSame('virtio,bridge=vmbr0', $parameters['net0']);
        self::assertStringNotContainsString('tag', $parameters['net0']);
    }

    public function testLxcCarriesEverythingInNet0AndHasItsOwnHostname(): void
    {
        $parameters = $this->configurator()->lxcParameters('ct-web-01', '10.30.20.61', 24, '10.30.20.1', 'vmbr0', 20);

        self::assertSame('ct-web-01', $parameters['hostname']);
        self::assertSame('name=eth0,bridge=vmbr0,ip=10.30.20.61/24,gw=10.30.20.1,tag=20', $parameters['net0']);
        self::assertArrayNotHasKey('ipconfig0', $parameters, 'an LXC has no cloud-init drive');
    }

    public function testTheTwoShapesCarryTheSameFactsFromTheSameInputs(): void
    {
        $configurator = $this->configurator();
        $qemu = $configurator->qemuParameters('srv-tp-01', '10.30.20.60', 24, '10.30.20.1', 'vmbr0', 30);
        $lxc = $configurator->lxcParameters('srv-tp-01', '10.30.20.60', 24, '10.30.20.1', 'vmbr0', 30);

        foreach (['10.30.20.60/24', '10.30.20.1', 'vmbr0', '30'] as $fact) {
            self::assertStringContainsString($fact, implode(' ', $qemu));
            self::assertStringContainsString($fact, implode(' ', $lxc));
        }
    }

    public function testAnSshKeyIsUrlEncoded(): void
    {
        // A key pasted as-is fails opaquely: Proxmox wants the whole `sshkeys` value URL-encoded,
        // and says nothing useful when it is not.
        $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI+abc/def= moncampus@platform';
        $parameters = $this->configurator()->qemuParameters('srv-web-07', '10.30.20.57', 24, '10.30.20.1', 'vmbr0', null, sshKey: $key);

        self::assertArrayHasKey('sshkeys', $parameters);
        self::assertStringNotContainsString(' ', $parameters['sshkeys']);
        self::assertSame($key, urldecode($parameters['sshkeys']));
    }

    public function testACloudInitUserIsPassedWhenAskedFor(): void
    {
        $parameters = $this->configurator()->qemuParameters('srv-web-07', '10.30.20.57', 24, '10.30.20.1', 'vmbr0', null, cloudInitUser: 'admin');

        self::assertSame('admin', $parameters['ciuser']);
    }

    // --- the hostname rules -----------------------------------------------------------------

    /** @return iterable<string, array{string, bool}> */
    public static function hostnameProvider(): iterable
    {
        yield 'plain' => ['srv-web-07', true];
        yield 'digits only' => ['1234', true];
        yield 'single letter' => ['a', true];
        yield 'exactly 63 characters' => [str_repeat('a', 63), true];
        yield '64 characters' => [str_repeat('a', 64), false];
        yield 'empty' => ['', false];
        yield 'uppercase' => ['SRV-WEB', false];
        yield 'a space' => ['srv web', false];
        yield 'an accent' => ['réseau', false];
        yield 'a leading hyphen' => ['-srv', false];
        yield 'a trailing hyphen' => ['srv-', false];
        yield 'an underscore' => ['srv_web', false];
        yield 'a dot' => ['srv.web', false];
        yield 'parentheses, as a VM name would allow' => ['serveur (tp)', false];
    }

    #[DataProvider('hostnameProvider')]
    public function testHostnameValidity(string $hostname, bool $valid): void
    {
        self::assertSame($valid, $this->configurator()->isValidHostname($hostname));
    }

    public function testARefusedHostnameNeverReachesProxmox(): void
    {
        $this->expectException(InvalidHostnameException::class);
        $this->configurator()->qemuParameters('Serveur Web (TP)', '10.30.20.57', 24, '10.30.20.1', 'vmbr0', null);
    }

    public function testARefusedHostnameIsRefusedForLxcToo(): void
    {
        $this->expectException(InvalidHostnameException::class);
        $this->configurator()->lxcParameters('CT_Web', '10.30.20.61', 24, '10.30.20.1', 'vmbr0', null);
    }

    public function testAnInvalidAddressIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->configurator()->qemuParameters('srv-web-07', 'not-an-ip', 24, '10.30.20.1', 'vmbr0', null);
    }

    // --- merging into the card the machine already has -----------------------------------------

    public function testTheTemplatesOwnCardOptionsSurviveTheConfiguration(): void
    {
        // The card a clone inherits from template 9001, as `qm config` prints it. Only the bridge
        // and the VLAN are the range's business; the MAC and the firewall flag are the template's,
        // and rewriting net0 from scratch is what used to drop them - silently, on a machine that
        // boots and has network, so nothing ever pointed at it.
        $parameters = $this->configurator()->qemuParameters(
            hostname: 'poste-01',
            ip: '10.30.20.57',
            prefixLength: 24,
            gateway: '10.30.20.1',
            bridge: 'vmbr1',
            vlan: 40,
            existingNet0: 'virtio=BC:24:11:66:51:BD,bridge=vmbr0,firewall=1,tag=300',
        );

        self::assertSame('virtio=BC:24:11:66:51:BD,bridge=vmbr1,firewall=1,tag=40', $parameters['net0']);
    }

    public function testAnOptionThisApplicationHasNeverHeardOfIsKept(): void
    {
        // The point is not the firewall flag in particular: anything the template carries and this
        // code does not model has to come through untouched.
        $parameters = $this->configurator()->qemuParameters(
            'poste-01', '10.30.20.57', 24, '10.30.20.1', 'vmbr0', 10,
            existingNet0: 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0,mtu=9000,queues=4,rate=125,link_down=1',
        );

        foreach (['mtu=9000', 'queues=4', 'rate=125', 'link_down=1', 'AA:BB:CC:DD:EE:FF'] as $kept) {
            self::assertStringContainsString($kept, $parameters['net0']);
        }
    }

    public function testTheNicModelOfTheTemplateIsNotSwappedForVirtio(): void
    {
        // A template deliberately built on e1000 has a guest with that driver and possibly not the
        // other one. Forcing virtio here would leave it without a network card at all.
        $parameters = $this->configurator()->qemuParameters(
            'poste-01', '10.30.20.57', 24, '10.30.20.1', 'vmbr0', null,
            existingNet0: 'e1000=AA:BB:CC:DD:EE:FF,bridge=vmbr9',
        );

        self::assertStringStartsWith('e1000=AA:BB:CC:DD:EE:FF', $parameters['net0']);
    }

    public function testARangeWithNoVlanStripsTheTagTheTemplateCarried(): void
    {
        // Not "leave the template's tag alone": that would put the machine on a VLAN the range
        // never declared. And not `tag=` either, which Proxmox refuses outright.
        $parameters = $this->configurator()->qemuParameters(
            'poste-01', '10.30.20.57', 24, '10.30.20.1', 'vmbr0', null,
            existingNet0: 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0,tag=300,firewall=1',
        );

        self::assertSame('virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0,firewall=1', $parameters['net0']);
    }

    public function testACardWithNoBridgeAtAllGetsOne(): void
    {
        $parameters = $this->configurator()->qemuParameters(
            'poste-01', '10.30.20.57', 24, '10.30.20.1', 'vmbr2', 7,
            existingNet0: 'virtio=AA:BB:CC:DD:EE:FF,firewall=1',
        );

        self::assertSame('virtio=AA:BB:CC:DD:EE:FF,firewall=1,bridge=vmbr2,tag=7', $parameters['net0']);
    }

    public function testNothingToMergeIntoStillBuildsACardFromScratch(): void
    {
        // The ISO path and any caller that has no card to read keep the previous behaviour exactly,
        // which is what makes this change safe to apply everywhere.
        $configurator = $this->configurator();

        self::assertSame('virtio,bridge=vmbr0,tag=20', $configurator->qemuParameters('poste-01', '10.30.20.57', 24, '10.30.20.1', 'vmbr0', 20, existingNet0: null)['net0']);
        self::assertSame('virtio,bridge=vmbr0,tag=20', $configurator->qemuParameters('poste-01', '10.30.20.57', 24, '10.30.20.1', 'vmbr0', 20, existingNet0: '   ')['net0']);
    }

    public function testASuggestionTurnsAHumanNameIntoAUsableHostname(): void
    {
        $configurator = $this->configurator();

        self::assertSame('serveur-web-tp', $configurator->suggestHostname('Serveur Web (TP)'));
        self::assertSame('reseau-sio2', $configurator->suggestHostname('Réseau SIO2'));
        self::assertSame('srv-web', $configurator->suggestHostname('--srv--web--'));
        self::assertSame('vm', $configurator->suggestHostname('!!!'), 'something unusable still yields something valid');
    }
}
