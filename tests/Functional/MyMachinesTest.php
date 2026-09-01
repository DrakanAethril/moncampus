<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GuestAccount;
use App\Entity\IpRange;
use App\Entity\ProxmoxHost;
use App\Entity\User;
use App\Entity\VmBatch;
use App\Entity\VmBatchItem;
use App\Enum\VmBatchItemStatus;
use App\Service\Guest\GuestMachineIndex;
use App\Service\Guest\GuestMachineLocator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Mes machines virtuelles » - the screen a student or a teacher who is not an administrator reads.
 *
 * What is worth a real request rather than a unit test is the *door*: this is the first screen of
 * the Proxmox area that is not behind `access_control: ^/infrastructure, ROLE_ADMIN`, so what keeps
 * one student out of another's machine is a voter and nothing else. A route that quietly answered
 * 200 to a classmate would hand them a password field on somebody else's account.
 */
class MyMachinesTest extends FunctionalTestCase
{
    public function testAStudentSeesTheMachineTheyHoldAnAccountOn(): void
    {
        [$student, $account] = $this->machineFor('celia.l');
        $this->client->loginUser($student);

        $crawler = $this->client->request('GET', '/my/machines');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('poste-01', $crawler->text());
        // The login they will type, and the password field that is the whole reason for the screen.
        self::assertStringContainsString($account->getLogin(), $crawler->text());
        self::assertCount(1, $crawler->filter('input[type="password"][name="password"]'));
    }

    /**
     * The machine was destroyed in Proxmox, and the card goes with it.
     *
     * This is the rule /infrastructure has always applied - nothing about a machine is stored, so
     * one destroyed there simply stops being listed - and this screen used to be the exception,
     * because it is built from account rows rather than from the hypervisor. The batch is deleted
     * here too, which is what actually happens: an administrator removes the plan of a practical
     * that is over, and until this the students kept a card for a machine that existed nowhere.
     *
     * The host answers here (an empty inventory), which is the whole difference with the test
     * above: this one asserts on *gone*, that one runs against a host that says nothing at all.
     */
    public function testAMachineTheHypervisorNoLongerHoldsIsNotListed(): void
    {
        [$student] = $this->machineFor('celia.l');
        $this->hypervisorHoldsNothing();
        $this->client->loginUser($student);

        $crawler = $this->client->request('GET', '/my/machines');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('poste-01', $crawler->text());
        self::assertCount(0, $crawler->filter('input[type="password"][name="password"]'));
    }

    public function testSomebodyWithNoMachineIsToldSoRatherThanRefused(): void
    {
        $this->client->loginUser($this->createUser(['ROLE_STUDENT'], 'ana.r'));

        $this->client->request('GET', '/my/machines');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->client->getCrawler()->filter('input[type="password"][name="password"]'));
    }

    /**
     * The owner, with the token their own page carries, gets through both guards - the answer is a
     * refusal from the hypervisor, which is a fiction in this test, and that is exactly the point:
     * the request reached it.
     */
    public function testTheOwnerReachesTheActionThroughBothGuards(): void
    {
        [$student, $account] = $this->machineFor('celia.l');
        $this->client->loginUser($student);

        $this->client->request('POST', '/my/machines/'.$account->getId().'/start', [], [], [
            'HTTP_X-CSRF-Token' => $this->tokenFromTheirOwnPage(),
        ]);

        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertIsArray($answer);
        // ok:false - there is no hypervisor behind 192.0.2.10, and nothing else could be true here.
        self::assertFalse($answer['ok'] ?? true);
    }

    /**
     * A classmate gets no way in at all. The refusal may come from either guard - they cannot hold
     * a valid token for a page that never shows them one - and that is the honest shape of it: the
     * ownership rule itself is settled one assertion at a time in GuestAccountVoterTest.
     */
    public function testAClassmateCannotActOnAMachineThatIsNotTheirs(): void
    {
        [, $account] = $this->machineFor('celia.l');
        $this->client->loginUser($this->createUser(['ROLE_STUDENT'], 'ana.r'));

        foreach (['/start', '/shutdown', '/password'] as $action) {
            $this->client->request('POST', '/my/machines/'.$account->getId().$action);

            self::assertResponseStatusCodeSame(403, $action.' must be refused to somebody else');
        }
    }

    /** A POST without the token is a POST from somewhere else. */
    public function testAnActionWithoutItsTokenIsRefused(): void
    {
        [$student, $account] = $this->machineFor('celia.l');
        $this->client->loginUser($student);

        $this->client->request('POST', '/my/machines/'.$account->getId().'/start');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A hypervisor that answers, and holds none of these machines - the state a destroyed machine
     * leaves behind. Stubbed at App\Service\Guest\GuestMachineLocator rather than at the HTTP
     * client, because what this test is about is the *decision* the screen makes on that answer.
     */
    private function hypervisorHoldsNothing(): void
    {
        $locator = $this->createStub(GuestMachineLocator::class);
        $locator->method('index')->willReturn(new GuestMachineIndex());
        static::getContainer()->set(GuestMachineLocator::class, $locator);
    }

    /** The token the screen renders, read back from the screen - the only one their session holds. */
    private function tokenFromTheirOwnPage(): string
    {
        $crawler = $this->client->request('GET', '/my/machines');

        return (string) $crawler->filter('[data-controller="my-machines"]')->attr('data-my-machines-token-value');
    }

    /**
     * @return array{User, GuestAccount}
     */
    private function machineFor(string $username): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $author = $this->createUser(['ROLE_ADMIN'], 'machines.author');
        $student = $this->createUser(['ROLE_STUDENT'], $username);

        $host = new ProxmoxHost('campus', '192.0.2.10', 'svc');
        $host->setPort(8006)->setSecretCipher('sealed')->setCreatedBy($author);
        $range = new IpRange('salle', $host, '10.30.0.0/24', '10.30.0.254', '10.30.0.1', '10.30.0.253');
        $range->setCreatedBy($author);
        $entityManager->persist($host);
        $entityManager->persist($range);

        $batch = new VmBatch('TP réseau', $this->createProgram([], [], $author), $host, $range, 9001, 'pve');
        $batch->setCreatedBy($author);
        $entityManager->persist($batch);

        $item = new VmBatchItem($batch, 'Célia L.', 'poste-01', $username, 1);
        $item->setStatus(VmBatchItemStatus::Provisioned);
        $item->setVmid(210);
        $item->setNode('pve');
        $entityManager->persist($item);
        $batch->addItem($item);

        $account = new GuestAccount($host, 'pve', 210, $username);
        $account->setUser($student);
        $account->setBatch($batch);
        $entityManager->persist($account);

        $entityManager->flush();

        return [$student, $account];
    }
}
