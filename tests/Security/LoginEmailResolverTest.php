<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\LoginEmailResolver;
use PHPUnit\Framework\TestCase;

class LoginEmailResolverTest extends TestCase
{
    public function testResolvesAConfirmedContactAddress(): void
    {
        $user = $this->user('mdupont', contactEmail: 'marie@perso.example', contactEmailVerified: true);

        self::assertSame($user, $this->resolver($user)->resolve('marie@perso.example'));
    }

    // The unconfirmed address is the one somebody could have typed by mistake - or on purpose,
    // to reach an account that isn't theirs. Only the mailed link makes it usable.
    public function testRefusesAnUnconfirmedContactAddress(): void
    {
        $user = $this->user('mdupont', contactEmail: 'marie@perso.example');

        self::assertNull($this->resolver($user)->resolve('marie@perso.example'));
    }

    // The school address mirrored from LDAP is never a way in, however well it identifies the
    // account: it is derived from the person's name rather than claimed by them.
    public function testRefusesTheSchoolAddressMirroredFromLdap(): void
    {
        $user = $this->user('mdupont', email: 'mdupont@example.org');

        self::assertNull($this->resolver(null)->resolve('mdupont@example.org'));
        self::assertNotNull($user->getEmail());
    }

    public function testIgnoresAnInactivatedAccount(): void
    {
        $user = $this->user('pmartin', contactEmail: 'paul@perso.example', contactEmailVerified: true, inactive: true);

        self::assertNull($this->resolver($user)->resolve('paul@perso.example'));
    }

    // An administrator is not excluded here, unlike on the magic-link path: this only decides
    // which uid the LDAP bind runs against, and the password is still required afterwards.
    public function testResolvesAnAdministrator(): void
    {
        $admin = $this->user('root', contactEmail: 'root@perso.example', contactEmailVerified: true);
        $admin->setRoles(['ROLE_ADMIN']);

        self::assertSame($admin, $this->resolver($admin)->resolve('root@perso.example'));
    }

    public function testRefusesAnythingThatIsNotAnAddress(): void
    {
        self::assertNull($this->resolver(null)->resolve('mdupont'));
        self::assertNull($this->resolver(null)->resolve(''));
    }

    private function resolver(?User $byContactEmail): LoginEmailResolver
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneBy')->willReturn($byContactEmail);

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
