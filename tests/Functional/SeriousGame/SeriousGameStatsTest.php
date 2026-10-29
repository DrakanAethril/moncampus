<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\Program;
use App\Entity\SeriousGame;
use App\Entity\User;
use App\Repository\SeriousGameRepository;
use App\SeriousGame\SeriousGameScenarioPlay;
use App\SeriousGame\Stats\SeriousGameStatistics;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The statistics of the serious games (design/validated/serious-games-neko-con.md §6): read by every
 * teacher with the names, the spread of the options chosen first, students only - an adult who
 * plays is not counted -, and a series game read the same way.
 */
class SeriousGameStatsTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private User $teacher;
    private Program $program;
    private SeriousGame $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'stats.student');
        $this->student->setFirstname('Léa')->setLastname('Martin');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'stats.teacher');
        $this->program = $this->createProgram([$this->student], [$this->teacher]);
        $this->game = $this->installGame('neko-con');
        $this->installObservatoire(units: ['taux']);
        $this->closeT01($this->student, 0);
        $this->closeT01($this->teacher, 1);
    }

    public function testEveryTeacherReadsTheStatisticsWithTheNames(): void
    {
        $other = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'stats.other');
        $page = '/serious-games/stats?game=neko-con&program='.$this->program->getId().'&order=activity';
        $this->assertScreens($other, [$page => 200, '/serious-games/stats?game=observatoire' => 200, '/serious-games/stats' => 200]);

        $this->client->request('GET', $page);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Martin Léa', $html);
        self::assertStringContainsString('Premier essai', $html);
        self::assertStringContainsString('plus choisie que la bonne', $html, 'the only student chose a wrong explanation first');
    }

    public function testOnlyStudentsAreCounted(): void
    {
        $game = static::getContainer()->get(SeriousGameRepository::class)->find($this->game->getId()) ?? self::fail('game');
        $stats = static::getContainer()->get(SeriousGameStatistics::class)->forGame($game, null, null);

        self::assertSame(1, $stats['population'], 'the teacher played, uncounted');
        $usage = $stats['usage'];
        self::assertIsArray($usage);
        self::assertSame(1, $usage['players']);
        $t01 = array_values(array_filter($stats['units'], static fn (array $u): bool => 'T01' === $u['code']))[0] ?? self::fail('T01');
        self::assertSame(1, $t01['closed']);
        self::assertSame(0.0, $t01['firstTry']);
        $explain = array_values(array_filter($t01['questions'], static fn (array $q): bool => 'expliquer' === $q['id']))[0] ?? self::fail('explain');
        self::assertSame([1, 0, 0, 0], array_column($explain['options'], 'count'));
        $progression = $stats['progression'];
        self::assertIsArray($progression);
        self::assertIsArray($progression['funnel']);
        self::assertSame(['label' => 'seriousGameFunnelStarted', 'params' => [], 'players' => 1], $progression['funnel'][0]);
    }

    /** Closes T01, the first explanation chosen being the given one, then the right one. */
    private function closeT01(User $user, int $firstChoice): void
    {
        $play = static::getContainer()->get(SeriousGameScenarioPlay::class);
        $player = static::getContainer()->get(EntityManagerInterface::class)->getReference(User::class, $user->getId());
        $game = static::getContainer()->get(SeriousGameRepository::class)->find($this->game->getId()) ?? self::fail('game');

        $play->board($player, $game);
        $started = $play->startTicket($player, $game, 'T01');
        $session = $play->ownedTicketSession($player, $game, \is_int($started['session']) ? $started['session'] : 0);
        $play->submitStep($player, $session, 'corriger', ['passed' => true, 'failed' => []]);
        $play->submitStep($player, $session, 'expliquer', $firstChoice);
        if (1 !== $firstChoice) {
            $play->submitStep($player, $session, 'expliquer', 1);
        }
        $play->endTicket($player, $session);
    }
}
