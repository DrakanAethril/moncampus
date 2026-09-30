<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The e-CO entry of the navbar is drawn for the people the e-CO screens let in.
 *
 * The link used to be gated on ROLE_ADMIN alone while EcoParcoursController and EcoCourseController
 * admit ROLE_ECO: reported in production, an account carrying the one role e-CO exists for could
 * open `/eco/parcours` by its URL and found nothing in the menu leading there.
 */
class EcoMenuTest extends FunctionalTestCase
{
    public function testTheEcoRoleIsOfferedTheEcoEntry(): void
    {
        self::assertContains('/eco/parcours', $this->navHrefs($this->createUser(['ROLE_USER', 'ROLE_ECO'], 'ecomenu.eco')));
    }

    public function testATeacherWhoAlsoRunsRacesIsOfferedTheEcoEntry(): void
    {
        self::assertContains('/eco/parcours', $this->navHrefs($this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_ECO'], 'ecomenu.teacher')));
    }

    public function testATeacherWithoutTheEcoRoleIsNotOfferedIt(): void
    {
        self::assertNotContains('/eco/parcours', $this->navHrefs($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'ecomenu.plain')));
    }

    /**
     * @return list<string>
     */
    private function navHrefs(User $user): array
    {
        $this->client->loginUser($user);
        $this->client->followRedirects();
        $crawler = $this->client->request('GET', '/');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $hrefs = $crawler->filter('header.navbar a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        $this->client->followRedirects(false);

        return $hrefs;
    }
}
