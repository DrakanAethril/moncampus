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
use Symfony\Component\HttpFoundation\Request;

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
        $this->enableEveryFeature();
    }

    /**
     * Opens the whole feature catalogue for every role, for the duration of this test.
     *
     * The two axes are orthogonal and are tested apart: **who** may reach a screen is the role, the
     * Voters and `access_control`, which is what these functional tests are about; **what the
     * establishment runs at all** is the feature matrix, and it has tests of its own
     * (tests/Security/FeatureResolverTest.php, tests/Functional/FeatureDefaultsTest.php, and the
     * three feature tables of RoleAccessSmokeTest).
     *
     * Without this, flipping one default in App\Enum\Feature would turn a dozen unrelated
     * assertions from "this role is refused" (403) into "this screen does not exist" (404) - and a
     * test that changes its meaning when a setting moves has stopped pinning what it was written for.
     *
     * One statement rather than 384 rows: the matrix is seeded whole by the migration the empty
     * `_test` schema replays, so there is nothing to insert. Rolled back with everything else.
     */
    private function enableEveryFeature(): void
    {
        $this->entityManager->createQuery('UPDATE App\Entity\FeatureRoleSetting s SET s.enabled = true')->execute();
        $this->entityManager->clear();
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
     * A session-bound CSRF token for a POST the test drives by hand, when there is no rendered form
     * to submit (an action guarded by a token but reached from a screen the test cannot open, or a
     * button rendered under a condition the test is precisely trying to break).
     *
     * Three things have to line up and none of them does on its own once a request has finished:
     * the token manager reads the session through the request stack, which is empty between
     * requests; the session comes from the browser's last request; and nothing writes it back to
     * disk afterwards, so the token would be minted into a session the next request never sees.
     *
     * Call it after at least one request has run under the logged-in user - that is what creates
     * the session this borrows.
     */
    protected function csrfToken(string $tokenId): string
    {
        $request = new Request();
        $request->setSession($this->client->getRequest()->getSession());

        $requestStack = static::getContainer()->get('request_stack');
        $requestStack->push($request);
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        $request->getSession()->save();
        $requestStack->pop();

        return $token;
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
