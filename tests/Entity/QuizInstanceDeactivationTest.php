<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\QuizMode;
use PHPUnit\Framework\TestCase;

/**
 * Deactivating a launched quiz takes it away from the class without deleting anything.
 *
 * The rule that matters is the one QuizInstance::isOpenNow() carries, because that single method is
 * what QuizAttemptStarter consults - and through it both the web passation and the mobile API. An
 * entraînement is otherwise "toujours ouvert" whatever its dates say, so deactivation is the one
 * thing that can close it, and a test is the cheapest way to notice if that ever stops being true.
 */
class QuizInstanceDeactivationTest extends TestCase
{
    public function testAnInstanceStartsActive(): void
    {
        $instance = $this->instance(QuizMode::Entrainement);

        self::assertTrue($instance->isActive());
        self::assertNull($instance->getDeactivatedAt());
        self::assertNull($instance->getDeactivatedBy());
        self::assertTrue($instance->isOpenNow());
    }

    public function testDeactivationClosesAnEntrainementThatWouldOtherwiseAlwaysBeOpen(): void
    {
        $instance = $this->instance(QuizMode::Entrainement);
        $teacher = new User();

        $instance->deactivate($teacher);

        self::assertFalse($instance->isActive());
        self::assertFalse($instance->isOpenNow());
        self::assertSame($teacher, $instance->getDeactivatedBy());
        self::assertNotNull($instance->getDeactivatedAt());
    }

    public function testDeactivationClosesAnEvaluationInsideItsWindow(): void
    {
        $instance = $this->instance(QuizMode::Evaluation);
        $instance->setOpensAt(new \DateTimeImmutable('-1 day'));
        $instance->setClosesAt(new \DateTimeImmutable('+1 day'));

        self::assertTrue($instance->isOpenNow());

        $instance->deactivate(new User());

        self::assertFalse($instance->isOpenNow());
    }

    // Re-posting the "Désactiver" form must not move the date the screen reports.
    public function testDeactivatingTwiceKeepsTheFirstDate(): void
    {
        $instance = $this->instance(QuizMode::Entrainement);
        $first = new User();
        $instance->deactivate($first);
        $stamp = $instance->getDeactivatedAt();

        $instance->deactivate(new User());

        self::assertSame($stamp, $instance->getDeactivatedAt());
        self::assertSame($first, $instance->getDeactivatedBy());
    }

    public function testReactivationGivesTheQuizBackToTheClass(): void
    {
        $instance = $this->instance(QuizMode::Entrainement);
        $instance->deactivate(new User());

        $instance->reactivate();

        self::assertTrue($instance->isActive());
        self::assertNull($instance->getDeactivatedAt());
        self::assertNull($instance->getDeactivatedBy());
        self::assertTrue($instance->isOpenNow());
    }

    private function instance(QuizMode $mode): QuizInstance
    {
        $program = new Program('SIO-2 2026-2027', 'SIO-2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        $instance = new QuizInstance($program, new User());
        $instance->setMode($mode);

        return $instance;
    }
}
