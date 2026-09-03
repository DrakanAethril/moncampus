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
 * Whether the student reads their own corrected copy, and the one place that answers.
 *
 * QuizInstance::isCorrectionReadable() is worth a test of its own for the reason
 * QuizInstanceDeactivationTest gives about isOpenNow(): three callers consult it - the passation,
 * the result screen and the mobile API - and none of them repeats the rule. The half that is easy
 * to lose is the second one: a per-question ✓/✕ *is* the mark, so a correction handed out while the
 * score is deferred would publish by another route exactly what the teacher chose to hold back.
 */
class QuizInstanceCorrectionVisibilityTest extends TestCase
{
    public function testACorrectionIsReadableByDefaultInBothModes(): void
    {
        self::assertTrue($this->instance(QuizMode::Entrainement)->isCorrectionVisible());
        self::assertTrue($this->instance(QuizMode::Entrainement)->isCorrectionReadable());
        self::assertTrue($this->instance(QuizMode::Evaluation)->isCorrectionReadable());
    }

    public function testTheTeacherCanHoldTheCorrectionBack(): void
    {
        $instance = $this->instance(QuizMode::Evaluation);
        $instance->setCorrectionVisible(false);

        self::assertFalse($instance->isCorrectionReadable());
    }

    public function testAnEntrainementCorrectionCanBeHeldBackToo(): void
    {
        $instance = $this->instance(QuizMode::Entrainement);
        $instance->setCorrectionVisible(false);

        self::assertFalse($instance->isCorrectionReadable());
    }

    public function testADeferredScoreDefersTheCorrectionWithIt(): void
    {
        $instance = $this->instance(QuizMode::Evaluation);
        $instance->setScoreVisibleImmediately(false);

        self::assertTrue($instance->isCorrectionVisible());
        self::assertFalse($instance->isCorrectionReadable());
    }

    public function testPublishingTheScoreLetsTheCorrectionOutWithoutAnyOtherGesture(): void
    {
        $instance = $this->instance(QuizMode::Evaluation);
        $instance->setScoreVisibleImmediately(false);
        self::assertFalse($instance->isCorrectionReadable());

        $instance->setScoreVisibleImmediately(true);

        self::assertTrue($instance->isCorrectionReadable());
    }

    public function testAnEntrainementHasNoScoreToDefer(): void
    {
        $instance = $this->instance(QuizMode::Entrainement);
        // Meaningless on an entraînement - its score is always out (Api\QuizController::result()).
        $instance->setScoreVisibleImmediately(false);

        self::assertTrue($instance->isCorrectionReadable());
    }

    private function instance(QuizMode $mode): QuizInstance
    {
        $program = new Program('SIO-2 2026-2027', 'SIO-2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        $instance = new QuizInstance($program, new User('teacher'));
        $instance->setMode($mode);

        return $instance;
    }
}
