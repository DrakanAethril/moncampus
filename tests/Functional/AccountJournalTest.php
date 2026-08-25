<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\LdapManageAccount;
use App\Entity\User;
use App\Enum\LdapAccountAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Annuaire > Opérations de comptes: the journal, its filters, and what each row says about itself.
 *
 * The counterpart of the fiche's banner - the fiche says where one account stands, this says what
 * has happened across the lot - and the only place a queue that has stopped draining shows itself,
 * as a pile of rows stuck at « En attente ».
 */
class AccountJournalTest extends FunctionalTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'journal.admin');
        $this->client->loginUser($this->admin);
    }

    private function seed(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $camille = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'croux');
        $camille->setFirstname('Camille')->setLastname('Roux');
        $malik = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'mbenali');
        $malik->setFirstname('Malik')->setLastname('Benali');

        $rename = new LdapManageAccount($camille, LdapAccountAction::LoginChange, 'cderoux');
        $rename->setAddedBy('sthar')->setState(2)
            ->setEndedAt(new \DateTimeImmutable())
            ->setVerificationDate(new \DateTimeImmutable())
            ->setAppliedAt(new \DateTimeImmutable());

        $disable = new LdapManageAccount($malik, LdapAccountAction::Disable);
        $disable->setAddedBy('sthar')->setState(2)->setEndedAt(new \DateTimeImmutable());

        $failed = new LdapManageAccount($malik, LdapAccountAction::Enable);
        $failed->setAddedBy('sthar')->setState(3)
            ->setEndedAt(new \DateTimeImmutable())
            ->setLog('ERROR: samba-tool user enable failed for mbenali');

        foreach ([$rename, $disable, $failed] as $row) {
            $entityManager->persist($row);
        }
        $entityManager->flush();
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $query = ''): array
    {
        $this->client->request('GET', '/directory/accounts/data'.$query);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertIsArray($payload['data']);

        /** @var array<int, array<string, mixed>> $data */
        $data = $payload['data'];

        return $data;
    }

    public function testTheScreenRendersOnAnEmptyQueue(): void
    {
        $this->client->request('GET', '/directory/accounts');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->rows());
    }

    public function testEachRowSaysWhatItIsAndWhatCanBeDoneAboutIt(): void
    {
        $this->seed();

        $rows = $this->rows();
        self::assertCount(3, $rows);

        // Most recent first, like the passwords journal next door.
        [$failed, $disable, $rename] = $rows;

        self::assertSame('Malik Benali', $failed['fullName']);
        self::assertSame('Réactivation', $failed['actionLabel']);
        self::assertSame('mbenali', $failed['detail'], 'A deactivation has nothing to say but which login.');
        self::assertSame('Échoué', $failed['statusLabel'], 'The queue contract\'s own label, shared with the three other queues.');
        self::assertTrue($failed['retryable']);
        self::assertIsString($failed['log']);
        self::assertStringContainsString('samba-tool user enable failed', $failed['log']);

        self::assertSame('Réussi, non vérifié', $disable['statusLabel'], 'Never green on the strength of state alone.');
        self::assertFalse($disable['retryable']);
        self::assertNotNull($disable['log'], 'And the row carries why it could not be confirmed.');

        self::assertSame('croux → cderoux', $rename['detail']);
        self::assertSame('Réussi & vérifié', $rename['statusLabel']);
        self::assertFalse($rename['retryable']);
    }

    public function testTheActionFilterNarrowsTheList(): void
    {
        $this->seed();

        self::assertCount(1, $this->rows('?action=login_change'));
        self::assertCount(1, $this->rows('?action=account_disable'));
        self::assertCount(3, $this->rows('?action=nonsense'), 'An unknown action filters nothing rather than emptying the screen.');
    }

    public function testTheStateFilterNarrowsTheList(): void
    {
        $this->seed();

        self::assertCount(1, $this->rows('?state=3'));
        self::assertCount(2, $this->rows('?state=2'));
        self::assertCount(0, $this->rows('?state=0'));
    }

    public function testTheSearchLooksAtBothLoginsAndTheName(): void
    {
        $this->seed();

        self::assertCount(2, $this->rows('?search[value]=benali'));
        self::assertCount(1, $this->rows('?search[value]=cderoux'), 'The new login of a rename is searchable too.');
        self::assertCount(1, $this->rows('?search[value]=croux'));
    }

    /**
     * Reached from a fiche, the journal filters on the **account**, not on a login. A rename makes
     * the same account appear under two of them, so a search on the current login would show one
     * row out of four - and the fiche's own « Voir les 4 opérations » would be a lie.
     */
    public function testTheJournalCanBeFilteredOnOneAccountAcrossItsLogins(): void
    {
        $this->seed();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $camille = $entityManager->getRepository(User::class)->findOneBy(['username' => 'croux']);
        self::assertInstanceOf(User::class, $camille);

        // As if the rename had gone through: the account now answers to the other login.
        $camille->setUsername('cderoux');
        $entityManager->flush();

        self::assertCount(1, $this->rows('?user='.$camille->getId()));
        self::assertCount(2, $this->rows('?user='.($camille->getId() + 1)), 'Malik keeps his own two rows.');
        self::assertCount(3, $this->rows('?user='), 'An empty filter filters nothing - and is not a 400.');
    }

    /**
     * The trap this repository has already been bitten by: a filter bar whose "Toutes" option is
     * value="" submits ?action=&state= on the very first touch, and InputBag::getInt() answers 400
     * to the empty string.
     */
    public function testEmptyFiltersAreNotABadRequest(): void
    {
        $this->seed();

        self::assertCount(3, $this->rows('?action=&state='));
    }

    /** Retrying from the journal answers JSON, so the table can redraw where it stands. */
    public function testRetryingFromTheJournalAnswersJson(): void
    {
        $this->seed();
        $this->client->request('GET', '/directory/accounts');

        $failedId = $this->rows()[0]['id'];
        self::assertIsInt($failedId);

        $this->client->request(
            'POST',
            '/directory/accounts/'.$failedId.'/retry',
            server: [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                'HTTP_X_CSRF_TOKEN' => $this->csrfToken('directory_account_retry'),
            ],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertTrue($payload['queued']);
        self::assertCount(4, $this->rows(), 'A new row, never the old one put back to zero.');
    }

    /** And a token that is not there is refused, whichever way it was meant to arrive. */
    public function testRetryingWithoutATokenIsRefused(): void
    {
        $this->seed();
        $this->client->request('GET', '/directory/accounts');
        $failedId = $this->rows()[0]['id'];
        self::assertIsInt($failedId);

        $this->client->request('POST', '/directory/accounts/'.$failedId.'/retry');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertCount(3, $this->rows());
    }
}
