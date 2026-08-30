<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EvaluationPeriod;
use App\Entity\EvaluationPeriodGroup;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\GameTrack;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The game's menu may only offer what the game's two switches actually open.
 *
 * `Feature::Game` says « does this establishment run a game, and for whom »; `Program::$gameEnabled`
 * says « does this formation play ». The three administration screens do **not** stand on the same
 * pair, and that is the trap this test exists for:
 *
 * - « Réglages » is where a formation declares itself, so it answers before the formation plays -
 *   GameSettingsController::openProgram() deliberately skips the conjunction, since a switch one can
 *   only reach once it is already on could never be thrown;
 * - « Observation » and « Récompenses » read a game that is running. Both ask GameAccess::isOpen(),
 *   which is `gameEnabled AND feature`, and answer **404** to a formation that has not switched
 *   itself on.
 *
 * The menu drew all three on the feature alone, so every class that does not play carried two dead
 * entries - reported in production on `/programs/2/game/observation` and `/programs/2/game/rewards`.
 * RoleAccessSmokeTest could not catch it: it opens the game on its fixture formation before asking
 * anything, so the state that breaks was the one state never exercised.
 */
class GameMenuTest extends FunctionalTestCase
{
    private User $admin;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'gamemenu.admin');
        $this->program = $this->createProgram(
            [$this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'gamemenu.student')],
            [$this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'gamemenu.teacher')],
            $this->admin,
        );
    }

    /**
     * A formation that has not switched its game on: one entry, the switch, and nothing that would
     * answer 404.
     */
    public function testAFormationThatDoesNotPlayIsOfferedTheSwitchAlone(): void
    {
        $links = $this->navHrefs();

        self::assertContains($this->path('/settings/game'), $links);
        self::assertNotContains($this->path('/game/observation'), $links);
        self::assertNotContains($this->path('/game/rewards'), $links);
    }

    /**
     * The other half of the same statement: those two are hidden **because they do not answer**, and
     * not out of taste. If either ever starts answering for a formation that does not play, this
     * fails and the menu is the thing to revisit - not this assertion.
     */
    public function testTheHiddenEntriesAreExactlyTheOnesThatWouldAnswer404(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('GET', $this->path('/game/observation'));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', $this->path('/game/rewards'));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        // The switch, on the same formation, in the same breath: this is what makes the game
        // reachable at all, so it must answer while the other two do not.
        $this->client->request('GET', $this->path('/settings/game'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** Once the formation plays, the three entries are offered and the three screens answer. */
    public function testAFormationThatPlaysIsOfferedAllThree(): void
    {
        $this->openTheGame();

        $links = $this->navHrefs();

        foreach (['/settings/game', '/game/observation', '/game/rewards'] as $suffix) {
            self::assertContains($this->path($suffix), $links, $suffix.' is missing from the menu of a formation that plays');

            $this->client->request('GET', $this->path($suffix));
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $suffix.' answers something other than 200 while the formation plays');
        }
    }

    private function path(string $suffix): string
    {
        return '/programs/'.$this->program->getId().$suffix;
    }

    /** @return list<string> */
    private function navHrefs(): array
    {
        $this->client->loginUser($this->admin);
        $this->client->followRedirects();
        $crawler = $this->client->request('GET', '/');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $hrefs = $crawler->filter('header.navbar a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        $this->client->followRedirects(false);

        return $hrefs;
    }

    /**
     * The formation switched on, with a calendar it can actually score - without the period group
     * the screens would answer « pas de période », which is a 200 but not the screen being pinned.
     */
    private function openTheGame(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $today = new \DateTimeImmutable('today');

        $group = new EvaluationPeriodGroup('Semestres du jeu');
        $group->setCreatedBy($this->admin);
        $entityManager->persist($group);

        $period = new EvaluationPeriod();
        $period->setName('Semestre du jeu');
        $period->setStartDate($today->modify('-2 months'));
        $period->setEndDate($today->modify('+2 months'));
        $period->setEvaluationPeriodGroup($group);
        $entityManager->persist($period);

        $this->program->setEvaluationPeriodGroup($group);
        $this->program->setGameEnabled(true);
        $this->program->setGameTrack(GameTrack::Sisr);

        $entityManager->flush();
    }
}
