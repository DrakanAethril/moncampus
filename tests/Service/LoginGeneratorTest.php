<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserLogin;
use App\Repository\LdapManageUserRepository;
use App\Repository\UserLoginRepository;
use App\Repository\UserRepository;
use App\Service\LoginGenerator;
use PHPUnit\Framework\TestCase;

class LoginGeneratorTest extends TestCase
{
    public function testFirstLetterOfFirstnamePlusLastname(): void
    {
        self::assertSame('mdupont', $this->generatorTaking()->generate('Martin', 'Dupont'));
    }

    public function testFoldsAccentsAndPunctuation(): void
    {
        self::assertSame('zbachirbey', $this->generatorTaking()->generate('Zoé', 'Bachir-Bey'));
    }

    public function testNumbersACollisionWithAnExistingAccount(): void
    {
        self::assertSame('mdupont01', $this->generatorTaking('mdupont')->generate('Martin', 'Dupont'));
    }

    public function testKeepsNumberingPastTheFirstNamesake(): void
    {
        self::assertSame('mdupont02', $this->generatorTaking('mdupont', 'mdupont01')->generate('Martin', 'Dupont'));
    }

    // Two students of the same class share a login base far more often than they share a name:
    // "Martin Dupont" and "Marie Dupont" both fold to mdupont. Nothing is persisted while an import
    // is being built, so the database cannot answer for the rows the same run has already handed
    // out - the caller passes them in.
    public function testHonoursLoginsTheSameRunHasAlreadyHandedOut(): void
    {
        self::assertSame('mdupont01', $this->generatorTaking()->generate('Marie', 'Dupont', ['mdupont']));
    }

    public function testCombinesReservedLoginsWithTheDatabase(): void
    {
        self::assertSame('mdupont02', $this->generatorTaking('mdupont')->generate('Marie', 'Dupont', ['mdupont01']));
    }

    public function testAnUnrelatedReservationChangesNothing(): void
    {
        self::assertSame('mdupont', $this->generatorTaking()->generate('Martin', 'Dupont', ['adurand']));
    }

    /**
     * The rule a rename turns on, and the reason loginTaken() takes an account at all: a login one
     * account was renamed away from is reserved against everybody else for ever, and against its
     * own holder not at all.
     */
    public function testAFormerLoginIsTakenForEverybodyButTheAccountThatHeldIt(): void
    {
        $holder = new User('cderoux');
        $somebodyElse = new User('adurand');
        $generator = $this->generatorKnowingFormerLogin('croux', $holder);

        self::assertTrue($generator->loginTaken('croux'), 'Nobody in particular is asking: taken.');
        self::assertTrue($generator->loginTaken('croux', [], $somebodyElse));
        self::assertFalse($generator->loginTaken('croux', [], $holder), 'Its own holder may take it back.');
    }

    private function generatorKnowingFormerLogin(string $login, User $holder): LoginGenerator
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn(null);

        $ldapRepository = $this->createStub(LdapManageUserRepository::class);
        $ldapRepository->method('loginExists')->willReturn(false);

        $userLoginRepository = $this->createStub(UserLoginRepository::class);
        $userLoginRepository->method('findOneByLogin')->willReturnCallback(
            static fn (string $asked): ?UserLogin => $asked === $login ? new UserLogin($holder, $asked) : null,
        );

        return new LoginGenerator($userRepository, $ldapRepository, $userLoginRepository);
    }

    private function generatorTaking(string ...$takenLogins): LoginGenerator
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?User => \in_array($criteria['username'] ?? null, $takenLogins, true) ? new User('taken') : null,
        );

        $ldapRepository = $this->createStub(LdapManageUserRepository::class);
        $ldapRepository->method('loginExists')->willReturn(false);

        // The history knows nobody here: these cases are about generating a login for an account
        // that does not exist yet, which is the one caller that passes no $for at all.
        $userLoginRepository = $this->createStub(UserLoginRepository::class);
        $userLoginRepository->method('findOneByLogin')->willReturn(null);

        return new LoginGenerator($userRepository, $ldapRepository, $userLoginRepository);
    }
}
