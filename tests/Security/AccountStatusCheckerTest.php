<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\AccountStatusChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The rule itself, away from any firewall: a date in inactive_date is a refusal, and nothing else
 * is.
 */
class AccountStatusCheckerTest extends TestCase
{
    public function testAnActiveAccountPassesThrough(): void
    {
        $checker = new AccountStatusChecker();
        $user = new User('active.user');

        self::assertFalse($checker->isRefused($user));

        $checker->checkPreAuth($user);
        $checker->checkPostAuth($user);

        // Reaching this line without an exception is the assertion; PHPUnit wants one anyway.
        self::assertNull($user->getInactiveDate());
    }

    public function testADeactivatedAccountIsRefused(): void
    {
        $checker = new AccountStatusChecker();
        $user = new User('inactive.user');
        $user->setInactiveDate(new \DateTimeImmutable('2026-08-24 09:00:00'));

        self::assertTrue($checker->isRefused($user));

        $this->expectException(CustomUserMessageAccountStatusException::class);

        $checker->checkPreAuth($user);
    }

    /**
     * The message is a key, resolved against the "security" domain by the login screen - and it
     * says nothing about the password, which has not been looked at when this is raised.
     */
    public function testTheRefusalCarriesTheTranslationKeyAndNothingAboutCredentials(): void
    {
        $checker = new AccountStatusChecker();
        $user = new User('inactive.user');
        $user->setInactiveDate(new \DateTimeImmutable());

        try {
            $checker->checkPreAuth($user);
            self::fail('A deactivated account must not pass checkPreAuth().');
        } catch (CustomUserMessageAccountStatusException $exception) {
            self::assertSame(AccountStatusChecker::DEACTIVATED_MESSAGE_KEY, $exception->getMessageKey());
            self::assertSame([], $exception->getMessageData());
        }
    }

    /**
     * The checker is declared on firewalls whose provider is this app's User entity, but a
     * UserCheckerInterface is handed whatever the firewall loaded - an in-memory user in a test
     * fixture, another provider's class tomorrow. Anything that is not our own entity carries no
     * inactive_date, so it is not this rule's business.
     */
    public function testAUserOfAnotherClassIsNoneOfItsBusiness(): void
    {
        $checker = new AccountStatusChecker();
        $user = new InMemoryUser('somebody', null);

        self::assertFalse($checker->isRefused($user));

        $checker->checkPreAuth($user);

        self::assertSame('somebody', $user->getUserIdentifier());
    }
}
