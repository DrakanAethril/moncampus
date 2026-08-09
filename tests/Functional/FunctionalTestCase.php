<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base for tests that drive the app through a real HTTP request.
 *
 * Runs against the dedicated `<database>_test` schema (config/packages/doctrine.yaml's
 * dbname_suffix), which is empty: every test creates the rows it needs and they are rolled back
 * afterwards, so tests never depend on - or damage - the development data. See tests/README.md
 * for how to create that schema.
 *
 * Empty-database screens are deliberately in scope: a dashboard that only works because the
 * developer's database happens to have data is exactly the kind of breakage this catches.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Without this the browser reboots the kernel before every request, which hands each one a
        // fresh container - and therefore a fresh database connection that cannot see the fixtures
        // this test writes inside its transaction. Sharing one kernel is what makes the two halves
        // agree.
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        // One transaction per test, never committed: the requests run in this same process on this
        // same connection, so everything the test and the app write is rolled back together.
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * @param list<string> $roles LDAP-derived roles, exactly as LdapUserMapper would have set them
     */
    protected function createUser(array $roles, string $username = 'test.user'): User
    {
        $user = new User($username);
        $user->setFirstname(ucfirst($username));
        $user->setLastname('Test');
        $user->setRoles($roles);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * The smallest structure a role screen needs to render something rather than redirect: one
     * Section > Track > Cohort, one SchoolYear covering today, and a Program pairing the two, with
     * the given users enrolled.
     *
     * Without it most screens answer 302 (nothing to show) and the smoke test would prove nothing.
     *
     * @param list<User> $students
     * @param list<User> $teachers
     */
    protected function createProgram(array $students = [], array $teachers = [], ?User $author = null): Program
    {
        $author ??= $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'fixture.author');

        $section = new Section('Section de test');
        $track = new Track('Filière de test', $section);
        $cohort = new Cohort('Classe de test', $track);
        foreach ([$section, $track, $cohort] as $node) {
            $node->setCreatedBy($author);
            $this->entityManager->persist($node);
        }

        $today = new \DateTimeImmutable('today');
        $schoolYear = new SchoolYear($today->modify('-3 months'), $today->modify('+6 months'));
        $schoolYear->setCreatedBy($author);
        $this->entityManager->persist($schoolYear);

        $program = new Program('Formation de test', 'TEST-1', $cohort, $schoolYear);
        $program->setCreatedBy($author);
        foreach ($students as $student) {
            $program->addStudent($student);
        }
        foreach ($teachers as $teacher) {
            $program->addTeacher($teacher);
        }
        $this->entityManager->persist($program);
        $this->entityManager->flush();

        return $program;
    }
}
