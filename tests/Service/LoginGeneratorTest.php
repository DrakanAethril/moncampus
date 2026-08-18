<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\LdapManageUserRepository;
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

    private function generatorTaking(string ...$takenLogins): LoginGenerator
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?User => \in_array($criteria['username'] ?? null, $takenLogins, true) ? new User('taken') : null,
        );

        $ldapRepository = $this->createStub(LdapManageUserRepository::class);
        $ldapRepository->method('loginExists')->willReturn(false);

        return new LoginGenerator($userRepository, $ldapRepository);
    }
}
