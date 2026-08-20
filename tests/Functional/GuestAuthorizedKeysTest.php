<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\UserSshKey;
use App\Service\Guest\GuestAuthorizedKeys;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The seam between the rows and the rule, which no unit test can reach.
 *
 * App\Tests\Service\Guest\GuestAuthorizedKeysTest settles the rule on primitives; what it cannot
 * settle is that the rows arrive at all - the DQL, the join to the owner, and above all that the
 * roles being tested are the ones User::getRoles() merges rather than the raw LDAP column. That
 * last one is the reason this file exists: the filtering deliberately happens in PHP because a
 * WHERE on the roles column would silently miss an administrator made one here rather than in the
 * directory, and only a real query proves the loaded entity answers the way the rule expects.
 */
class GuestAuthorizedKeysTest extends FunctionalTestCase
{
    public function testAnAdministratorsStoredKeyIsHandedToANewMachine(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createUser(['ROLE_ADMIN'], 'admin.keys');
        $teacher = $this->createUser(['ROLE_TEACHER'], 'teacher.keys');

        $entityManager->persist(new UserSshKey($admin, 'Portable', 'ssh-ed25519 AAAAadmin', 'SHA256:aaa'));
        $entityManager->persist(new UserSshKey($teacher, 'Portable', 'ssh-ed25519 AAAAteacher', 'SHA256:bbb'));
        $entityManager->flush();

        $keys = static::getContainer()->get(GuestAuthorizedKeys::class)->forNewGuest();

        self::assertNotNull($keys->material);
        self::assertStringContainsString('ssh-ed25519 AAAAadmin', $keys->material, "the administrator's key must reach the machine");
        self::assertStringNotContainsString('ssh-ed25519 AAAAteacher', $keys->material, 'only administrators hand out access');
        // The log of a machine names its keys one by one, and that is what makes it worth reading.
        self::assertNotEmpty(array_filter(
            $keys->descriptors,
            static fn (string $descriptor): bool => str_contains($descriptor, 'Portable'),
        ), 'the key must be named by its owner and its label');
        // No platform key is asserted here: the test database is empty and none has been generated,
        // which is itself the right answer - the set is what exists, not what ought to.
    }

    /** Deactivated, so the next machine created stops carrying the key - see the unit test. */
    public function testADeactivatedAdministratorsKeyIsNotHandedOver(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $gone = $this->createUser(['ROLE_ADMIN'], 'gone.keys');
        $gone->setInactiveDate(new \DateTimeImmutable());
        $entityManager->persist(new UserSshKey($gone, 'Portable', 'ssh-ed25519 AAAAgone', 'SHA256:ccc'));
        $entityManager->flush();

        $keys = static::getContainer()->get(GuestAuthorizedKeys::class)->forNewGuest();

        self::assertStringNotContainsString('ssh-ed25519 AAAAgone', $keys->material ?? '');
    }
}
