<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\Program;
use App\Entity\SeriousGame;
use App\Entity\SeriousGameAnswer;
use App\Entity\SeriousGameRewardGrant;
use App\Entity\User;
use App\Enum\SeriousGameFinish;
use App\Repository\SeriousGameAnswerRepository;
use App\Repository\SeriousGameCometRepository;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameSessionRepository;
use App\SeriousGame\Engine\AnswerCorrector;
use App\SeriousGame\SeriousGamePlay;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Who reads what of a student's games (design/validated/serious-games.md §1.9, §8): the student
 * their own page, the teachers of their class the class map, the student card and the questions
 * replayed; another teacher nothing; the staff figures without names. And the 24-month retention.
 */
class SeriousGameReadingTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private User $teacher;
    private Program $program;
    private SeriousGame $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'reading.student');
        $this->student->setFirstname('Léa')->setLastname('Martin');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'reading.teacher');
        $this->program = $this->createProgram([$this->student], [$this->teacher]);
        $this->game = $this->installObservatoire(units: ['taux', 'frac']);
        $this->playASeries();
    }

    public function testTheStudentReadsTheirOwnPage(): void
    {
        $this->assertScreens($this->student, ['/serious-games/me' => 200]);
        $page = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Taux d&#039;évolution', $page);
        self::assertStringContainsString('Vitrine', $page);
        self::assertStringNotContainsString('Rejouer la question vue', $page);
    }

    public function testTheTeachersOfTheClassReadTheMapTheCardAndTheQuestions(): void
    {
        $class = '/serious-games/results/'.$this->program->getId().'/observatoire';
        $card = '/serious-games/results/student/'.$this->student->getId();
        $answer = $this->anAnswer();
        $replay = $card.'/answers/'.$answer->getId();

        $this->assertScreens($this->teacher, [$class => 200, $class.'?students=dim&order=activity&chapter=sablier' => 200, $card => 200, $replay => 200]);
        self::assertStringContainsString('La question vue', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', $class);
        self::assertStringContainsString('Martin', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Diviser par la valeur d&#039;arrivée', (string) $this->client->getResponse()->getContent());

        $stranger = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'reading.stranger');
        $this->assertScreens($stranger, [$class => 404, $card => 404, $replay => 404]);
    }

    public function testTheStaffReadsFiguresWithoutNames(): void
    {
        $staff = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'reading.staff');

        $this->assertScreens($staff, ['/serious-games/results' => 200]);
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('chiffres d’ensemble', $page);
        self::assertStringNotContainsString('Martin', $page);
    }

    public function testAStudentWearsAPinTheyHold(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new SeriousGameRewardGrant($entityManager->getReference(User::class, $this->student->getId()), 'astrolabe', SeriousGameFinish::Brass));
        $entityManager->persist(new SeriousGameRewardGrant($entityManager->getReference(User::class, $this->student->getId()), 'pin-etoile'));
        $entityManager->flush();

        $this->client->loginUser($this->student);
        $this->client->request('GET', '/serious-games/me');
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/me/pin"]')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString('Épinglette portée', (string) $this->client->getResponse()->getContent());
    }

    public function testSessionsAndCometsAreKeptTwentyFourMonthsAfterTheLastActivity(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $sessions = static::getContainer()->get(SeriousGameSessionRepository::class);
        $comets = static::getContainer()->get(SeriousGameCometRepository::class);
        $threshold = new \DateTimeImmutable('-24 months');

        self::assertSame(0, $sessions->purgeInactiveSince($threshold));

        $entityManager->createQuery('UPDATE App\Entity\SeriousGameProgress p SET p.lastPlayedAt = :old')->setParameter('old', new \DateTimeImmutable('-25 months'))->execute();
        self::assertGreaterThan(0, $sessions->purgeInactiveSince($threshold));
        $comets->purgeInactiveSince($threshold);
        self::assertSame([], static::getContainer()->get(SeriousGameAnswerRepository::class)->findAll(), 'the answers go with their sessions');
    }

    /** One series of taux, two of its answers carrying the « valeur d'arrivée » error. */
    private function playASeries(): void
    {
        $play = static::getContainer()->get(SeriousGamePlay::class);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $player = $entityManager->getReference(User::class, $this->student->getId());
        $game = static::getContainer()->get(SeriousGameRepository::class)->find($this->game->getId()) ?? self::fail('game');

        $started = $play->startSeries($player, $game, 'taux');
        $session = $play->ownedSession($player, $game, \is_int($started['session']) ? $started['session'] : 0);
        $errors = 0;
        foreach ($session->getPlan() as $planned) {
            $play->nextQuestion($player, $session);
            $question = $play->question($game, $planned);
            $wrong = array_values(array_filter($question->wrongs, static fn (array $w): bool => 'taux-arrival' === $w['misconception']))[0] ?? null;
            $input = null !== $wrong && $errors < 2 ? $wrong['text'] : ($errors >= 2 ? AnswerCorrector::DONT_KNOW : $question->answerText);
            $errors += null !== $wrong && $errors < 2 ? 1 : 0;
            $play->answer($player, $session, $input);
        }
        $play->end($player, $session);
    }

    private function anAnswer(): SeriousGameAnswer
    {
        return static::getContainer()->get(SeriousGameAnswerRepository::class)->findOneBy([]) ?? self::fail('no answer');
    }
}
