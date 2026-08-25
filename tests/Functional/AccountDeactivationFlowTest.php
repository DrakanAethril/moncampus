<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\LdapManageAccount;
use App\Entity\User;
use App\Enum\LdapAccountAction;
use App\Repository\LdapManageAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deactivation end to end, through real requests: the click, the queue row, the banner, the retry.
 *
 * The asymmetry is what these tests are about. Deactivating switches inactive_date **at the click**
 * and only then queues the request, so a script that never runs leaves the safe state - MonCampus
 * closed, the directory not yet - rather than an open platform waiting on a cron.
 */
class AccountDeactivationFlowTest extends FunctionalTestCase
{
    private User $admin;
    private User $target;
    private int $targetId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'flow.admin');
        $this->target = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'flow.target');
        $this->targetId = $this->target->getId() ?? 0;

        $this->client->loginUser($this->admin);
        // Opens the session csrfToken() borrows.
        $this->client->request('GET', '/directory/users');
    }

    private function post(string $action, string $tokenId): void
    {
        $this->client->request('POST', '/directory/users/'.$this->targetId.'/'.$action, [
            '_token' => $this->csrfToken($tokenId),
        ]);
    }

    private function requests(): LdapManageAccountRepository
    {
        return static::getContainer()->get(LdapManageAccountRepository::class);
    }

    public function testDeactivatingClosesThePlatformAtOnceAndQueuesTheDirectory(): void
    {
        $this->post('deactivate', 'directory_user_deactivate');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $reloaded = static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->find($this->targetId);
        self::assertNotNull($reloaded?->getInactiveDate(), 'The platform closes at the click, without waiting for anything.');

        $queued = $this->requests()->findPendingForUser($reloaded);
        self::assertNotNull($queued, 'And the directory is told, in the same write.');
        self::assertSame(LdapAccountAction::Disable, $queued->getActionType());
        self::assertSame('flow.target', $queued->getLogin());
        self::assertSame('flow.admin', $queued->getAddedBy());
        self::assertSame(0, $queued->getState());
    }

    public function testReactivatingReopensThePlatformAndQueuesTheWayBack(): void
    {
        $this->post('deactivate', 'directory_user_deactivate');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        // The first request has to be out of the queue before a second may be posted.
        $first = $this->requests()->findMostRecentForUser($entityManager->getRepository(User::class)->find($this->targetId));
        self::assertNotNull($first);
        $first->setState(2);
        $entityManager->flush();

        $this->post('reactivate', 'directory_user_reactivate');

        $reloaded = $entityManager->getRepository(User::class)->find($this->targetId);
        self::assertNull($reloaded?->getInactiveDate());
        self::assertSame(LdapAccountAction::Enable, $this->requests()->findMostRecentForUser($reloaded)?->getActionType());
    }

    /** One gesture at a time: the second click is refused, and nothing is half-written. */
    public function testASecondGestureIsRefusedWhileTheFirstIsStillQueued(): void
    {
        $this->post('deactivate', 'directory_user_deactivate');
        $this->post('reactivate', 'directory_user_reactivate');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $reloaded = $entityManager->getRepository(User::class)->find($this->targetId);

        self::assertNotNull($reloaded?->getInactiveDate(), 'The refused reactivation must not have reopened anything.');
        self::assertSame(1, $this->requests()->countForUser($reloaded), 'And must not have queued a second row.');
    }

    /** The banner is on the fiche, rendered by the server - not something the polling brings. */
    public function testTheFicheShowsTheBannerWithoutAnyPolling(): void
    {
        $this->post('deactivate', 'directory_user_deactivate');

        $this->client->request('GET', '/directory/users/'.$this->targetId.'/edit');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('cm-accountband', $html);
        self::assertStringContainsString('Désactivation du compte de l’annuaire en cours', $html);
        self::assertStringContainsString('Dernière opération de compte', $html);
    }

    /**
     * The state that must never be green. The dev directory has no account-status attribute, so a
     * succeeded row stays « réussi, non vérifié » with the reason - which is the honest answer, not
     * a bug to work around.
     */
    public function testASucceededRowTheDirectoryCannotConfirmShowsOrangeWithTheReason(): void
    {
        $this->post('deactivate', 'directory_user_deactivate');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $reloaded = $entityManager->getRepository(User::class)->find($this->targetId);
        $row = $this->requests()->findMostRecentForUser($reloaded);
        self::assertNotNull($row);
        $row->setState(2)->setEndedAt(new \DateTimeImmutable());
        $entityManager->flush();

        $this->client->request('GET', '/directory/users/'.$this->targetId.'/edit');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('cm-accountband--warn', $html);
        self::assertStringContainsString('Réussi, non vérifié', $html);
        self::assertStringNotContainsString('cm-accountband--ok', $html);

        // Re-read: the request that just ran may well have cleared the manager, so the object this
        // test still holds is not necessarily the row the verifier wrote.
        $stamped = $this->requests()->find($row->getId());
        self::assertInstanceOf(LdapManageAccount::class, $stamped);
        self::assertNull($stamped->getVerificationDate());
        self::assertNotNull($stamped->getVerificationNote(), 'And it says why.');
        self::assertNull($stamped->getAppliedAt(), 'No verification, no consequence.');
    }

    /** Retrying inserts a new row; the failed one keeps saying it failed. */
    public function testRetryingAFailedRequestQueuesANewRow(): void
    {
        $this->post('deactivate', 'directory_user_deactivate');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $reloaded = $entityManager->getRepository(User::class)->find($this->targetId);
        $failed = $this->requests()->findMostRecentForUser($reloaded);
        self::assertNotNull($failed);
        $failed->setState(3)->setLog('ERROR: samba-tool user disable failed for flow.target');
        $entityManager->flush();

        $this->client->request('GET', '/directory/users/'.$this->targetId.'/edit');
        self::assertStringContainsString('Réessayer', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/directory/accounts/'.$failed->getId().'/retry', [
            '_token' => $this->csrfToken('directory_account_retry'),
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->requests()->countForUser($reloaded));
        self::assertSame(3, $failed->getState(), 'The attempt that failed goes on saying so.');

        $latest = $this->requests()->findMostRecentForUser($reloaded);
        self::assertInstanceOf(LdapManageAccount::class, $latest);
        self::assertSame(0, $latest->getState());
        self::assertSame(LdapAccountAction::Disable, $latest->getActionType());
    }

    /** The polling endpoint answers the same banner the fiche renders. */
    public function testThePollingEndpointReturnsTheSameBanner(): void
    {
        $this->post('deactivate', 'directory_user_deactivate');

        $this->client->request('GET', '/directory/users/'.$this->targetId.'/account-status');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertIsArray($payload['status']);
        self::assertSame('account_disable', $payload['status']['action']);
        self::assertFalse($payload['status']['done']);
        self::assertIsString($payload['html']);
        self::assertStringContainsString('cm-accountband', $payload['html']);
    }
}
