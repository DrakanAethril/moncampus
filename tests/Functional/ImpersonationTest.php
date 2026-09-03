<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;

/**
 * « Se connecter en tant que » - the four answers that make the gesture safe, pinned by request
 * rather than read off the configuration.
 *
 * The switch is a query parameter on any URL of the application, so none of these rules can be
 * verified by opening the picker: what has to hold is what the firewall does when
 * `?_switch_user=` arrives, whoever sends it and whatever it names.
 */
class ImpersonationTest extends FunctionalTestCase
{
    private User $admin;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'impersonation.admin');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'impersonation.student');
    }

    public function testAdministratorBecomesTheOtherAccountAndComesBack(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/?_switch_user=impersonation.student');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('impersonation.student', $this->currentUsername());

        // The way back the banner offers: the same parameter, with the value SwitchUserListener
        // reserves for leaving.
        $this->client->request('GET', '/?_switch_user=_exit');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('impersonation.admin', $this->currentUsername());
    }

    /**
     * The rule config/packages/security.yaml cannot express, and the reason
     * App\Security\ImpersonationSubscriber exists: `switch_user` decides on the person asking, not
     * on the account being asked for.
     */
    public function testAnAdministratorIsNeverImpersonated(): void
    {
        $other = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'impersonation.other-admin');

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/?_switch_user='.$other->getUsername());

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('impersonation.admin', $this->currentUsername());
    }

    public function testNobodyBelowAdministratorMaySwitch(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'impersonation.teacher');

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/?_switch_user=impersonation.student');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('impersonation.teacher', $this->currentUsername());
    }

    /** The picker is a roster: it is admin-only, and it never names an account the switch refuses. */
    public function testThePickerIsAdminOnlyAndOffersNoAdministrator(): void
    {
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/impersonate/search?q=impersonation');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/impersonate/search?q=impersonation');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $labels = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['results'], 'text');
        self::assertNotEmpty(preg_grep('/impersonation\.student/', $labels));
        // Neither another administrator nor the administrator asking.
        self::assertEmpty(preg_grep('/impersonation\.admin/', $labels));
    }

    private function currentUsername(): ?string
    {
        $user = static::getContainer()->get('security.token_storage')->getToken()?->getUser();

        return $user instanceof User ? $user->getUsername() : null;
    }
}
