<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentChallenge;
use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentPlayer;
use App\Entity\User;
use App\Enum\QuizTournamentDuelOrigin;
use App\Enum\QuizTournamentPalette;
use App\Tests\Functional\SeriousGame\SeriousGameTestTrait;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Jouer », the student's entry and page of games (design/validated/quiz-tournament.md §15): in a
 * pure student's bar only when a tournament reaches them or a serious game is open to them, never
 * with a counter; the page lists the tournaments (place, rating, pending challenges) and the games
 * (progress), and does not exist when there is nothing on it. Adults find the tournaments under
 * Outils › Animer la classe › « Tournois ».
 */
class PlayHubTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private EntityManagerInterface $em;
    private User $admin;
    private User $alice;
    private User $bruno;
    private User $teacher;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'play.admin');
        $this->alice = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'play.alice');
        $this->bruno = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'play.bruno');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'play.teacher');
        $this->program = $this->createProgram([$this->alice, $this->bruno], [$this->teacher], $this->admin);
    }

    public function testNothingToPlayMeansNoEntryAndNoPage(): void
    {
        $this->tournament('Tournoi fermé', opened: false);
        $this->tournament('Tournoi d’antan', opened: true, start: '-1 year', end: '-40 days');

        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('href="/play"', $this->content());
        $this->client->request('GET', '/play');
        self::assertResponseStatusCodeSame(404);
    }

    public function testATournamentAloneOpensTheEntryAndItsCard(): void
    {
        $tournament = $this->tournament('Tournoi RGPD', opened: true);
        $outsider = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'play.outsider');
        $this->createProgram([$outsider], [], $this->admin);

        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/');
        self::assertSelectorTextSame('.navbar-nav a[href="/play"] .nav-link-title', 'Jouer', 'the entry carries no counter');

        $crawler = $this->client->request('GET', '/play');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.qt-card--play[data-tournament-palette="lavender"]', 'Tournoi RGPD');
        self::assertSelectorTextContains('.qt-card--play', 'Pas encore joué');
        self::assertCount(1, $crawler->filter('.qt-card--play a[href="/tournaments/'.$tournament->getId().'"]'));
        self::assertSelectorNotExists('.cm-sg-card', 'no game: the section is not drawn');
        self::assertSelectorExists('.navbar-nav .nav-item.active a[href="/play"]');

        // The standings page lights the same entry.
        $this->client->request('GET', '/tournaments/'.$tournament->getId());
        self::assertSelectorExists('.navbar-nav .nav-item.active a[href="/play"]');

        // A student the targeting does not reach has neither.
        $this->assertScreens($outsider, ['/play' => 404]);
        $this->client->request('GET', '/');
        self::assertStringNotContainsString('href="/play"', $this->content());
    }

    public function testTheCardShowsThePlaceTheRatingAndThePendingChallenges(): void
    {
        $tournament = $this->tournament('Tournoi RGPD', opened: true);
        $duel = new QuizTournamentDuel($tournament, $this->alice, $this->bruno, QuizTournamentDuelOrigin::Challenge, 7, new \DateTimeImmutable('-2 days'), 1000, 1000, false, false);
        $duel->finish(new \DateTimeImmutable('-2 days +10 minutes'), 15.0, 10.0, 1020, 980);
        $this->em->persist($duel);
        foreach ([[$this->alice, 1020, 1.0], [$this->bruno, 980, 0.0]] as [$user, $rating, $outcome]) {
            $player = new QuizTournamentPlayer($tournament, $user);
            $player->recordDuel($rating, $outcome);
            $this->em->persist($player);
        }
        $this->em->persist(new QuizTournamentChallenge($tournament, $this->bruno, $this->alice, false, new \DateTimeImmutable('-1 hour')));
        $this->em->flush();

        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/play');
        self::assertSelectorTextContains('.qt-card--play .qt-card__block', 'En placement');
        self::assertSelectorTextContains('.qt-card--play .qt-card__rating', 'Cote 1020');
        self::assertSelectorTextContains('.qt-card--play .qt-sticker--alert', '1 défi en attente');

        // Still no counter in the bar: the count is the page's, not an alert (§7).
        self::assertSelectorTextSame('.navbar-nav a[href="/play"] .nav-link-title', 'Jouer');
    }

    public function testAGameAloneOpensTheEntryAndItsCard(): void
    {
        $game = $this->installObservatoire(['ROLE_TEACHER'], ['taux']);
        $game->addTargetProgram($this->program);
        $this->em->flush();

        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/');
        self::assertStringContainsString('href="/play"', $this->content());

        $this->client->request('GET', '/play');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cm-sg-card', 'Observatoire');
        self::assertSelectorExists('.cm-sg-card a[href="/serious-games/play/observatoire"]');
        self::assertSelectorNotExists('.qt-card--play', 'no tournament: the section is not drawn');

        // The game's own screens light « Jouer ».
        $this->client->request('GET', '/serious-games');
        self::assertSelectorExists('.navbar-nav .nav-item.active a[href="/play"]');
    }

    public function testBothSectionsWhenBothAreOpen(): void
    {
        $this->tournament('Tournoi RGPD', opened: true);
        $this->installObservatoire(['ROLE_TEACHER'], ['taux'])->addTargetProgram($this->program);
        $this->em->flush();

        $this->client->loginUser($this->alice);
        $this->client->request('GET', '/play');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.qt-card--play');
        self::assertSelectorExists('.cm-sg-card');
    }

    public function testAnAdultHasNoEntryAndNoPage(): void
    {
        $this->tournament('Tournoi RGPD', opened: true);
        $this->installObservatoire(['ROLE_TEACHER'], ['taux'])->addTargetProgram($this->program);
        $this->em->flush();

        $this->assertScreens($this->teacher, ['/play' => 404]);
        self::assertStringNotContainsString('href="/play"', $this->content());
        $this->assertScreens($this->admin, ['/play' => 404]);
    }

    public function testATeacherOfATargetedClassFindsTheTournamentsUnderOutils(): void
    {
        $other = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'play.other.teacher');
        $this->createProgram([], [$other], $this->admin);

        // Closed: nobody but the administrators sees it, so it gives no teacher the entry.
        $tournament = $this->tournament('Tournoi RGPD', opened: false);
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/');
        self::assertStringNotContainsString('href="/tournaments"', $this->content());
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/settings');
        self::assertStringContainsString('href="/tournaments"', $this->content(), 'an administrator follows every tournament');

        // The requests have reset the entity manager: the tournament is read again before it is opened.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->find(QuizTournament::class, $tournament->getId())?->open($em->find(User::class, $this->admin->getId()) ?? $this->admin, new \DateTimeImmutable('-1 week'));
        $em->flush();
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.cm-toolsmenu a[href="/tournaments"]', 'Tournois');
        $this->client->request('GET', '/tournaments');
        self::assertSelectorExists('.cm-toolsmenu a.active[href="/tournaments"]');

        $this->client->loginUser($other);
        $this->client->request('GET', '/');
        self::assertStringNotContainsString('href="/tournaments"', $this->content(), 'a class they do not teach');
    }

    private function tournament(string $name, bool $opened, string $start = '-1 week', string $end = '+2 months'): QuizTournament
    {
        $tournament = new QuizTournament($name, new \DateTimeImmutable($start), new \DateTimeImmutable($end), QuizTournamentPalette::Lavender, $this->admin);
        $tournament->addTargetProgram($this->program);
        if ($opened) {
            $tournament->open($this->admin, new \DateTimeImmutable($start));
        }
        $this->em->persist($tournament);
        $this->em->flush();

        return $tournament;
    }

    private function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
