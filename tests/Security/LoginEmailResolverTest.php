<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\LoginEmailResolver;
use PHPUnit\Framework\TestCase;

class LoginEmailResolverTest extends TestCase
{
    public function testResolvesTheSchoolAddressMirroredFromLdap(): void
    {
        $user = $this->user('mdupont', email: 'mdupont@example.org');

        self::assertSame($user, $this->resolver([$user])->resolve('mdupont@example.org'));
    }

    public function testResolvesAConfirmedContactAddress(): void
    {
        $user = $this->user('mdupont', contactEmail: 'marie@perso.example', contactEmailVerified: true);

        self::assertSame($user, $this->resolver([], $user)->resolve('marie@perso.example'));
    }

    // The unconfirmed address is the one somebody could have typed by mistake - or on purpose,
    // to reach an account that isn't theirs. Only the mailed link makes it usable.
    public function testRefusesAnUnconfirmedContactAddress(): void
    {
        $user = $this->user('mdupont', contactEmail: 'marie@perso.example');

        self::assertNull($this->resolver([], $user)->resolve('marie@perso.example'));
    }

    // The LDAP `mail` attribute has no uniqueness constraint, so two accounts really can share
    // one - and picking either of them would log somebody into the wrong account.
    public function testRefusesASchoolAddressSharedByTwoAccounts(): void
    {
        $first = $this->user('mdupont', email: 'contact@example.org');
        $second = $this->user('pmartin', email: 'contact@example.org');

        self::assertNull($this->resolver([$first, $second])->resolve('contact@example.org'));
    }

    public function testIgnoresAnInactivatedAccountOnBothAddresses(): void
    {
        $onLdapMail = $this->user('mdupont', email: 'mdupont@example.org', inactive: true);
        self::assertNull($this->resolver([$onLdapMail])->resolve('mdupont@example.org'));

        $onContact = $this->user('pmartin', contactEmail: 'paul@perso.example', contactEmailVerified: true, inactive: true);
        self::assertNull($this->resolver([], $onContact)->resolve('paul@perso.example'));
    }

    // An administrator is not excluded here, unlike on the magic-link path: this only decides
    // which uid the LDAP bind runs against, and the password is still required afterwards.
    public function testResolvesAnAdministrator(): void
    {
        $admin = $this->user('root', email: 'root@example.org');
        $admin->setRoles(['ROLE_ADMIN']);

        self::assertSame($admin, $this->resolver([$admin])->resolve('root@example.org'));
    }

    public function testRefusesAnythingThatIsNotAnAddress(): void
    {
        self::assertNull($this->resolver()->resolve('mdupont'));
        self::assertNull($this->resolver()->resolve(''));
    }

    /**
     * @param list<User> $byLdapEmail accounts the repository returns for the school address
     * @param ?User      $byContact   the account the repository returns for the contact address
     */
    private function resolver(array $byLdapEmail = [], ?User $byContact = null): LoginEmailResolver
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findByLdapEmail')->willReturn($byLdapEmail);
        $repository->method('findOneBy')->willReturn($byContact);

        return new LoginEmailResolver($repository);
    }

    private function user(
        string $username,
        ?string $email = null,
        ?string $contactEmail = null,
        bool $contactEmailVerified = false,
        bool $inactive = false,
    ): User {
        $user = new User($username);
        $user->setEmail($email);
        $user->setContactEmail($contactEmail);

        if ($contactEmailVerified) {
            $user->setContactEmailVerifiedAt(new \DateTimeImmutable());
        }

        if ($inactive) {
            $user->setInactiveDate(new \DateTimeImmutable());
        }

        return $user;
    }
}
