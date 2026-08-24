<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EmailAlias;
use App\Entity\LdapManageAccount;
use App\Entity\User;
use App\Enum\EmailAliasOrigin;
use App\Enum\LdapAccountAction;
use App\Repository\LdapManageAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The rename, end to end - and above all the moment it does *not* happen.
 *
 * A deactivation switches at the click; a rename switches only once the directory has confirmed and
 * this application has read that confirmation back. That is not a preference:
 * App\Security\LdapCredentialsVerifier looks the LDAP entry up by the local name, so a username
 * written ahead of the directory would make the account unreachable on both sides at once.
 *
 * These tests never reach LDAP. The verifier's own verdict is covered attribute by attribute in
 * App\Tests\Service\LdapAccountVerifierTest; what is under test here is what the application does
 * with that verdict, which is why verification_date is stamped by hand.
 */
class AccountLoginChangeFlowTest extends FunctionalTestCase
{
    private User $admin;
    private User $target;
    private int $targetId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'rename.admin');
        $this->target = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'croux');
        $this->target->setFirstname('Camille')->setLastname('Roux');
        $this->entityManager()->flush();
        $this->targetId = $this->target->getId() ?? 0;

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/directory/users');
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function requests(): LdapManageAccountRepository
    {
        return static::getContainer()->get(LdapManageAccountRepository::class);
    }

    private function reloadTarget(): User
    {
        $user = $this->entityManager()->getRepository(User::class)->find($this->targetId);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function requestRename(string $newLogin): void
    {
        $this->client->request('POST', '/directory/users/'.$this->targetId.'/change-login', [
            '_token' => $this->csrfToken('directory_account_change_login'),
            'new_login' => $newLogin,
        ]);
    }

    public function testRequestingARenameQueuesItAndTouchesNothing(): void
    {
        $this->requestRename('cderoux');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('croux', $this->reloadTarget()->getUsername(), 'The username waits on the directory.');

        $queued = $this->requests()->findPendingForUser($this->reloadTarget());
        self::assertNotNull($queued);
        self::assertSame(LdapAccountAction::LoginChange, $queued->getActionType());
        self::assertSame('croux', $queued->getLogin());
        self::assertSame('cderoux', $queued->getNewLogin());
    }

    /**
     * The state that must never be green, on the action where it costs something: the script says it
     * renamed, the directory does not confirm, and the login stays what it was.
     */
    public function testASucceededButUnverifiedRenameLeavesTheLoginAlone(): void
    {
        $this->requestRename('cderoux');

        $row = $this->requests()->findMostRecentForUser($this->reloadTarget());
        self::assertNotNull($row);
        $row->setState(2)->setEndedAt(new \DateTimeImmutable());
        $this->entityManager()->flush();

        $this->client->request('GET', '/directory/users/'.$this->targetId.'/account-status');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertIsArray($payload['status']);
        self::assertSame('warn', $payload['status']['level']);
        self::assertSame('croux', $this->reloadTarget()->getUsername(), 'No verification, no rename.');

        $settled = $this->requests()->find($row->getId());
        self::assertInstanceOf(LdapManageAccount::class, $settled);
        self::assertNull($settled->getAppliedAt());
    }

    public function testAVerifiedRenameSwitchesTheUsername(): void
    {
        $this->requestRename('cderoux');

        $row = $this->requests()->findMostRecentForUser($this->reloadTarget());
        self::assertNotNull($row);
        // As if App\Service\LdapAccountVerifier had found (uid=cderoux) and no (uid=croux).
        $row->setState(2)->setEndedAt(new \DateTimeImmutable())->setVerificationDate(new \DateTimeImmutable());
        $this->entityManager()->flush();

        $this->client->request('GET', '/directory/users/'.$this->targetId.'/account-status');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertIsArray($payload['status']);
        self::assertSame('ok', $payload['status']['level']);
        self::assertSame('cderoux', $this->reloadTarget()->getUsername());

        $settled = $this->requests()->find($row->getId());
        self::assertInstanceOf(LdapManageAccount::class, $settled);
        self::assertNotNull($settled->getAppliedAt());
        self::assertSame('croux', $settled->getLogin(), 'The queue row keeps saying what the login was.');
    }

    /**
     * The old address stays in reception and the new one is added beside it. Reception is a
     * catch-all: mail has already gone out to the old address, and the local part is taken for the
     * whole school either way, so removing it would lose letters without freeing anything.
     */
    public function testAVerifiedRenameAddsTheNewMailAddressAndKeepsTheOld(): void
    {
        $entityManager = $this->entityManager();

        $primary = (new EmailAlias())->setLocalPart('camille.roux')->setOrigin(EmailAliasOrigin::Generated);
        $fromLogin = (new EmailAlias())->setLocalPart('croux')->setOrigin(EmailAliasOrigin::Login);
        foreach ([$primary, $fromLogin] as $alias) {
            $this->target->addEmailAlias($alias);
            $entityManager->persist($alias);
        }
        $this->target->setPrimaryAlias($primary);
        $entityManager->flush();

        $this->requestRename('cderoux');

        $row = $this->requests()->findMostRecentForUser($this->reloadTarget());
        self::assertNotNull($row);
        $row->setState(2)->setEndedAt(new \DateTimeImmutable())->setVerificationDate(new \DateTimeImmutable());
        $entityManager->flush();

        $this->client->request('GET', '/directory/users/'.$this->targetId.'/account-status');

        $localParts = array_map(
            static fn (EmailAlias $alias): string => (string) $alias->getLocalPart(),
            $this->reloadTarget()->getEmailAliases()->toArray(),
        );
        sort($localParts);

        self::assertSame(['camille.roux', 'cderoux', 'croux'], $localParts);
        self::assertSame('camille.roux', $this->reloadTarget()->getPrimaryAlias()?->getLocalPart(), 'The primary address, built from civil status, does not move.');
    }

    /** Applying twice must be the same as applying once - the polling and the cron can cross. */
    public function testApplyingIsIdempotent(): void
    {
        $this->requestRename('cderoux');

        $row = $this->requests()->findMostRecentForUser($this->reloadTarget());
        self::assertNotNull($row);
        $row->setState(2)->setEndedAt(new \DateTimeImmutable())->setVerificationDate(new \DateTimeImmutable());
        $this->entityManager()->flush();

        $this->client->request('GET', '/directory/users/'.$this->targetId.'/account-status');
        // Compared to the second, not to the microsecond: the first read comes from the object still
        // in memory, the second from the DATETIME column, which does not keep them.
        $firstApplied = $this->requests()->find($row->getId())?->getAppliedAt()?->format('Y-m-d H:i:s');

        $this->client->request('GET', '/directory/users/'.$this->targetId.'/account-status');

        self::assertNotNull($firstApplied);
        self::assertSame($firstApplied, $this->requests()->find($row->getId())?->getAppliedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('cderoux', $this->reloadTarget()->getUsername());
    }

    // --- The live availability check ---------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typedLogins(): iterable
    {
        yield 'a free one' => ['cderoux', 'available'];
        yield 'the current one' => ['croux', 'current'];
        yield 'the current one in capitals' => ['CROUX', 'current'];
        yield 'one somebody carries' => ['rename.admin', 'taken'];
        yield 'a malformed one' => ['c de roux', 'invalid'];
        yield 'nothing typed' => ['', 'empty'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('typedLogins')]
    public function testAvailabilityAnswersTheSameThingTheSubmissionWill(string $typed, string $expected): void
    {
        $this->client->request('GET', '/directory/users/'.$this->targetId.'/login-availability?login='.urlencode($typed));

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame($expected, $payload['state']);
    }

    /** A login reserved by a creation request that never went through is taken all the same. */
    public function testALoginReservedByAPendingCreationCountsAsTaken(): void
    {
        $entityManager = $this->entityManager();
        $creation = new \App\Entity\LdapManageUser('Cassandre', 'Deroux', 'student', 'account_create');
        $creation->setLogin('cderoux');
        $entityManager->persist($creation);
        $entityManager->flush();

        $this->client->request('GET', '/directory/users/'.$this->targetId.'/login-availability?login=cderoux');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame('taken', $payload['state']);

        // And the submission refuses it too, which is the point of asking the same question twice.
        $this->requestRename('cderoux');
        self::assertSame('croux', $this->reloadTarget()->getUsername());
        self::assertNull($this->requests()->findMostRecentForUser($this->reloadTarget()));
    }

    /** The modal is on the fiche, with the suggestion the name gives. */
    public function testTheFicheCarriesTheModalAndItsSuggestion(): void
    {
        $this->client->request('GET', '/directory/users/'.$this->targetId.'/edit');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('change-login-modal', $html);
        self::assertStringContainsString('Changer le login de Camille Roux', $html);
        self::assertStringContainsString('data-login-change-suggestion-value="croux"', $html);
        self::assertStringContainsString('Prévenez l’intéressé avant de valider', $html);
    }
}
