<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserLogin;
use App\Repository\UserLoginRepository;
use App\Service\UserLoginHistory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The ledger's two halves: a login is nobody else's, for ever, and it is still its own holder's.
 *
 * Written against the in-memory collection rather than a database, because that is where record()
 * does its work: the row it has just persisted is not flushed, so a repository cannot see it, and
 * the method that forgot this would lose exactly the login it was called to displace.
 */
class UserLoginHistoryTest extends TestCase
{
    /** @param list<UserLogin> $known rows the repository already holds, indexed by their login */
    private function history(array $known = []): UserLoginHistory
    {
        $byLogin = [];
        foreach ($known as $entry) {
            $byLogin[$entry->getLogin()] = $entry;
        }

        $repository = $this->createStub(UserLoginRepository::class);
        $repository->method('findOneByLogin')->willReturnCallback(
            static fn (string $login): ?UserLogin => $byLogin[$login] ?? null,
        );

        return new UserLoginHistory($repository, $this->createStub(EntityManagerInterface::class));
    }

    /** @return array<string, UserLogin> */
    private function byLogin(User $user): array
    {
        $entries = [];
        foreach ($user->getLoginHistory() as $entry) {
            $entries[$entry->getLogin()] = $entry;
        }

        return $entries;
    }

    public function testTheFirstLoginOfAnAccountIsRecordedAsCurrent(): void
    {
        $user = new User('croux');

        $this->history()->record($user, 'croux');

        $entries = $this->byLogin($user);
        self::assertCount(1, $entries);
        self::assertTrue($entries['croux']->isCurrent());
    }

    /**
     * The case the whole class exists for: an account that predates the table has no row at all, so
     * "release whatever is open" would close nothing and the outgoing login would vanish - which is
     * precisely the bug being fixed.
     */
    public function testTheOutgoingLoginIsWrittenDownEvenWhenNothingWasRecordedBefore(): void
    {
        $user = new User('croux');

        $this->history()->record($user, 'cderoux');

        $entries = $this->byLogin($user);
        self::assertArrayHasKey('croux', $entries);
        self::assertNotNull($entries['croux']->getReleasedAt());
        self::assertNull($entries['croux']->getAssignedAt(), 'Undated: nobody wrote down when it was taken.');
        self::assertTrue($entries['cderoux']->isCurrent());
    }

    public function testOnlyOneLoginIsEverCurrent(): void
    {
        $user = new User('croux');
        $history = $this->history();

        $history->record($user, 'cderoux');
        $history->record($user, 'charlesroux');

        $current = array_filter($this->byLogin($user), static fn (UserLogin $e) => $e->isCurrent());
        self::assertSame(['charlesroux'], array_keys($current));
        self::assertCount(3, $this->byLogin($user), 'The one in between survives too.');
    }

    /** Coming back revives the row rather than inserting a second one - the unique index depends on it. */
    public function testTakingBackAnOwnLoginRevivesItsRowInsteadOfDuplicating(): void
    {
        $user = new User('croux');
        $history = $this->history();

        $history->record($user, 'cderoux');
        $history->record($user, 'croux');

        $entries = $this->byLogin($user);
        self::assertCount(2, $entries);
        self::assertTrue($entries['croux']->isCurrent());
        self::assertNotNull($entries['cderoux']->getReleasedAt());
    }

    /** Idempotent: the fiche's polling and the cron cross each other at a minute's distance. */
    public function testRecordingTheSameLoginTwiceChangesNothing(): void
    {
        $user = new User('croux');
        $history = $this->history();

        $history->record($user, 'croux');
        $history->record($user, 'croux');

        self::assertCount(1, $this->byLogin($user));
    }

    public function testALoginBelongingToAnotherAccountIsRefused(): void
    {
        $somebodyElse = new User('cderoux');
        $user = new User('croux');

        $this->expectException(\LogicException::class);

        $this->history([new UserLogin($somebodyElse, 'cderoux')])->record($user, 'cderoux');
    }
}
