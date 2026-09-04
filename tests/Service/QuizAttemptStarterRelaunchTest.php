<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptAnswer;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceQuestion;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\AttemptOrigin;
use App\Enum\QuizMode;
use App\Repository\QuizAttemptRepository;
use App\Service\QuizAttemptStarter;
use App\Service\QuizDrawService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * When a teacher-granted retry's clock starts.
 *
 * « Relancer » creates the attempt at the teacher's click, which is not when the student sits back
 * down: the button is pressed at the end of a class for a machine that died, and the student comes
 * back the next morning. Anchored on creation, a 30-minute budget would already be spent - the
 * gesture would hand out an attempt that expires while nobody is looking at it.
 *
 * So the clock starts at the first opening, and only while nothing has been served: past that, the
 * attempt is an ordinary one being resumed after a crash, and when it started is a fact that a
 * refresh must not be able to move. That second half is the one worth a test - it is exactly the
 * shape of the mode contrôle's own $servedAt rule, and losing it would turn F5 into extra time.
 */
class QuizAttemptStarterRelaunchTest extends TestCase
{
    public function testAGrantedRetryTakesItsClockFromTheFirstOpening(): void
    {
        $attempt = $this->openAttempt(AttemptOrigin::Relance);
        $attempt->restartClock(new \DateTimeImmutable('-2 hours'));

        $started = $this->starter($attempt)->startOrResume($attempt->getQuizInstance(), $attempt->getStudent());

        self::assertSame($attempt, $started['attempt']);
        self::assertFalse($started['concluded']);
        self::assertLessThan(5, abs($attempt->getStartedAt()->getTimestamp() - time()));
    }

    // F5 on question 3 must not be worth two more hours.
    public function testAnAttemptAlreadyUnderWayKeepsItsStart(): void
    {
        $attempt = $this->openAttempt(AttemptOrigin::Relance);
        $twoHoursAgo = new \DateTimeImmutable('-2 hours');
        $attempt->restartClock($twoHoursAgo);
        $attempt->getAttemptAnswers()->first()->markServed(new \DateTimeImmutable('-2 hours'));

        $this->starter($attempt)->startOrResume($attempt->getQuizInstance(), $attempt->getStudent());

        self::assertEquals($twoHoursAgo, $attempt->getStartedAt());
    }

    // Nothing about an ordinary attempt changes: only a teacher's gesture moves a clock.
    public function testAnOrdinaryAttemptIsResumedWhereItWas(): void
    {
        $attempt = $this->openAttempt(AttemptOrigin::Initiale);
        $twoHoursAgo = new \DateTimeImmutable('-2 hours');
        $attempt->restartClock($twoHoursAgo);

        $this->starter($attempt)->startOrResume($attempt->getQuizInstance(), $attempt->getStudent());

        self::assertEquals($twoHoursAgo, $attempt->getStartedAt());
    }

    private function openAttempt(AttemptOrigin $origin): QuizAttempt
    {
        $program = new Program('SIO-2 2026-2027', 'SIO-2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        $instance = new QuizInstance($program, new User('teacher'));
        $instance->setMode(QuizMode::Evaluation);

        $attempt = new QuizAttempt($instance, new User('student'));
        $attempt->setOrigin($origin);
        $attempt->addAttemptAnswer(new QuizAttemptAnswer($attempt, $this->createStub(QuizInstanceQuestion::class)));

        return $attempt;
    }

    /**
     * A starter whose repository already has this attempt open - the draw service is never reached,
     * since resuming is the whole path under test.
     */
    private function starter(QuizAttempt $inProgress): QuizAttemptStarter
    {
        $repository = $this->createStub(QuizAttemptRepository::class);
        $repository->method('findInProgress')->willReturn($inProgress);

        return new QuizAttemptStarter(
            $this->createStub(EntityManagerInterface::class),
            $repository,
            $this->createStub(QuizDrawService::class),
        );
    }
}
