<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\Feature;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * No menu in the top bar may open on nothing.
 *
 * A dropdown whose entries are all switched off still renders its toggle: it looks like a door, it
 * answers a click, and it shows an empty panel. That is worse than the feature being off, because
 * the reader concludes the application is broken rather than that the establishment does not run
 * this.
 *
 * The state that matters here is **the delivered one**, not the one FunctionalTestCase opens for
 * its own assertions: the empty menus only appear once the matrix holds the real defaults - a
 * teacher's « Ressources » is empty the day the wiki leaves their role. So this test undoes the
 * base class's enableEveryFeature() and puts the catalogue's own defaults back, role by role.
 */
class NavigationEmptyMenusTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->restoreCatalogueDefaults();
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function roles(): iterable
    {
        yield 'student' => [['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS']];
        yield 'teacher' => [['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS']];
        yield 'staff' => [['ROLE_USER', 'ROLE_STAFF']];
        yield 'tutor' => [['ROLE_USER', 'ROLE_TUTOR']];
        yield 'admin' => [['ROLE_USER', 'ROLE_ADMIN']];
    }

    /**
     * @param list<string> $roles
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function testNoNavbarMenuOpensOnAnEmptyPanel(array $roles): void
    {
        $user = $this->createUser($roles, 'nav.'.strtolower(str_replace('ROLE_', '', $roles[1])));
        $this->createProgram([], [], $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'nav.author'));

        $this->client->loginUser($user);
        // Redirects followed: a tutor has no dashboard and is sent to their own landing screen. It
        // carries the same top bar, which is what this test reads.
        $this->client->followRedirects();
        $crawler = $this->client->request('GET', '/');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $empty = [];
        // Every panel, at any depth: a `.dropend` inside a dropdown is a menu of its own and fails
        // the same way. A panel counts as filled when it holds at least one link - the toggle of a
        // nested submenu is itself an `<a>`, so a dropdown holding nothing but submenus passes,
        // which is right: it leads somewhere.
        $crawler->filter('header.navbar .dropdown-menu')->each(static function (Crawler $menu) use (&$empty): void {
            if (0 === $menu->filter('a')->count()) {
                $empty[] = $menu->ancestors()->first()->filter('a')->count() > 0
                    ? trim($menu->ancestors()->first()->filter('a')->first()->text())
                    : '(unnamed panel)';
            }
        });

        self::assertSame([], $empty, sprintf(
            "%d menu(s) of the top bar open on an empty panel for %s.\n\n".
            "A dropdown whose entries are all switched off must not be rendered at all: gate the\n".
            "toggle on the same conditions as its entries, the way layout/_nav_resources.html.twig\n".
            "does.\n\nEmpty: %s",
            \count($empty),
            implode('/', $roles),
            implode(', ', $empty),
        ));
    }

    /**
     * Puts the matrix back to what App\Enum\Feature says an establishment is delivered with - the
     * exact opposite of the base class's setUp, and the only state in which this test means
     * anything.
     */
    private function restoreCatalogueDefaults(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        foreach (Feature::cases() as $feature) {
            foreach (Feature::managedRoles() as $role) {
                $entityManager->createQuery(
                    'UPDATE App\Entity\FeatureRoleSetting s SET s.enabled = :enabled WHERE s.feature = :feature AND s.role = :role'
                )
                    ->setParameter('enabled', $feature->defaultForRole($role))
                    ->setParameter('feature', $feature)
                    ->setParameter('role', $role)
                    ->execute();
            }
        }

        $entityManager->clear();
    }
}
