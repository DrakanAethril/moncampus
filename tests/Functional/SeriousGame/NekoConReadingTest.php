<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\Program;
use App\Entity\SeriousGame;
use App\Entity\User;
use App\Repository\SeriousGameRepository;
use App\SeriousGame\SeriousGameScenarioPlay;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The teachers' reading of Panique à la Neko-Con (design/validated/serious-games-neko-con.md §5):
 * the class map with « Bloqués » and « Beaucoup d'aide », the notions to revisit with the share of
 * the class holding each misconception, the student's sheet with the criteria their deliveries
 * failed - never their code -, and nothing for a teacher who does not teach the class.
 */
class NekoConReadingTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private User $teacher;
    private Program $program;
    private SeriousGame $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'neko.reading.student');
        $this->student->setFirstname('Léa')->setLastname('Martin');
        $other = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'neko.reading.other');
        $other->setFirstname('Hugo')->setLastname('Bernard');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'neko.reading.teacher');
        $this->program = $this->createProgram([$this->student, $other], [$this->teacher]);
        $this->game = $this->installGame('neko-con');
        $this->play();
    }

    public function testTheClassMapShowsTheTicketsAndTheStudentsStuck(): void
    {
        $class = '/serious-games/results/'.$this->program->getId().'/neko-con';
        $this->assertScreens($this->teacher, [$class => 200, $class.'?act=1&order=activity' => 200, $class.'?students=help' => 200]);

        $this->client->request('GET', $class.'?students=blocked');
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Martin', $page, 'two refused deliveries on T02 and nothing since');
        self::assertStringNotContainsString('Bernard', $page);
        self::assertMatchesRegularExpression('/cm-sg-tk--done is-help\s*"\s+title="T01 /', $page, 'T01 resolved, with help');
        self::assertMatchesRegularExpression('/cm-sg-tk--doing\s+is-blocked"\s+title="T02 /', $page, 'T02 stuck');
    }

    public function testTheNotionsCountTheStudentsHoldingAMisconception(): void
    {
        $notions = '/serious-games/results/'.$this->program->getId().'/neko-con/notions';
        $this->assertScreens($this->teacher, [$notions => 200]);
        $page = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('HTML 01', $page);
        self::assertStringContainsString('CSS 07', $page);
        self::assertStringContainsString('1 élève sur 2', $page);
        self::assertStringContainsString('0 % des tickets clos', $page, 'T01 was closed with help');
    }

    public function testTheStudentSheetKeepsTheFailedCriteriaNeverTheCode(): void
    {
        $card = '/serious-games/results/student/'.$this->student->getId();
        $this->assertScreens($this->teacher, [$card => 200]);
        $page = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('T01 · Les accents sont devenus des hiéroglyphes', $page);
        self::assertStringContainsString('Livraison refusée', $page);
        self::assertStringContainsString('L’affiche s’affiche', $page);
        self::assertStringContainsString('pendant l&#039;aide', $page);
        self::assertStringNotContainsString('&lt;img', $page);
    }

    public function testOnlyTheTeachersOfTheClassRead(): void
    {
        $stranger = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'neko.reading.stranger');
        $base = '/serious-games/results/'.$this->program->getId().'/neko-con';
        $this->assertScreens($stranger, [$base => 404, $base.'/notions' => 404, '/serious-games/results/student/'.$this->student->getId() => 404]);

        $this->assertScreens($this->teacher, ['/serious-games/results/'.$this->program->getId().'/observatoire/notions' => 404]);
    }

    /**
     * Léa closes T01 after a wrong guided answer and a wrong explanation, then gets two deliveries
     * of T02 refused; Hugo plays nothing.
     */
    private function play(): void
    {
        $play = static::getContainer()->get(SeriousGameScenarioPlay::class);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $player = $entityManager->getReference(User::class, $this->student->getId());
        $game = static::getContainer()->get(SeriousGameRepository::class)->find($this->game->getId()) ?? self::fail('game');

        $play->board($player, $game);
        $started = $play->startTicket($player, $game, 'T01');
        $session = $play->ownedTicketSession($player, $game, \is_int($started['session']) ? $started['session'] : 0);
        $play->useHint($player, $session);
        $play->submitGuide($player, $session, 'couche', 1);
        $play->submitGuide($player, $session, 'couche', 0);
        $play->submitStep($player, $session, 'corriger', ['passed' => true, 'failed' => []]);
        $play->submitStep($player, $session, 'expliquer', 2);
        $play->submitStep($player, $session, 'expliquer', 1);
        $play->endTicket($player, $session);

        $started = $play->startTicket($player, $game, 'T02');
        $session = $play->ownedTicketSession($player, $game, \is_int($started['session']) ? $started['session'] : 0);
        foreach ([1, 2] as $attempt) {
            $play->submitStep($player, $session, 'corriger', ['passed' => false, 'failed' => ['L’affiche s’affiche sur la page d’accueil'], 'files' => ['index.html' => '<img src="x">']]);
        }
    }
}
