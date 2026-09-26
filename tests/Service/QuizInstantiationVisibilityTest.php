<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\QuizTemplate;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\QuizMode;
use App\Enum\QuizScoring;
use App\Service\FileUploadService;
use App\Service\MatchingImageStore;
use App\Service\QuizDifficultyDistributionResolver;
use App\Service\QuizInstantiationService;
use App\Service\QuizPoolShares;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The option a launch is narrowed to, as the instance builder stores it.
 *
 * The launch form already refuses an option foreign to the class it is submitted with; this is the
 * second belt, and it is worth having because the consequence is silent: an instance pointing at an
 * option of another class addresses nobody at all, so no student sees the quiz and no screen says
 * why. Dropped to "the whole class" rather than kept.
 */
class QuizInstantiationVisibilityTest extends TestCase
{
    public function testTheClassesOwnOptionIsKept(): void
    {
        $program = $this->program();
        $slam = new Option('SLAM', 'SLAM', '#0d6efd');
        $slam->addProgram($program);

        self::assertSame($slam, $this->launch($program, $slam)->getVisibilityOption());
    }

    public function testAnOptionOfAnotherClassIsDroppedRatherThanStored(): void
    {
        $program = $this->program();
        $foreign = new Option('GMSI', 'GMSI', '#dc3545');
        $foreign->addProgram($this->program());

        self::assertNull($this->launch($program, $foreign)->getVisibilityOption());
    }

    public function testNoOptionMeansTheWholeClass(): void
    {
        self::assertNull($this->launch($this->program(), null)->getVisibilityOption());
    }

    private function launch(Program $program, ?Option $option): \App\Entity\QuizInstance
    {
        $service = new QuizInstantiationService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(FileUploadService::class),
            new QuizDifficultyDistributionResolver(),
            // MatchingImageStore is final, so it is built for real - harmless here, its only
            // dependency being the upload service and this launch carrying no question at all.
            new MatchingImageStore($this->createStub(FileUploadService::class)),
            new QuizPoolShares(),
        );

        $template = new QuizTemplate(new User('prof'));
        $template->setName('Réseaux — VLAN');

        return $service->instantiateQuiz(
            templates: [$template],
            program: $program,
            createdBy: new User('prof'),
            mode: QuizMode::Evaluation,
            questionCount: 0,
            difficultySliderPosition: 50,
            sameQuestionsForAll: true,
            questionOrderPerStudent: false,
            answerOrderPerStudent: false,
            opensAt: null,
            closesAt: null,
            secondsPerQuestion: null,
            globalTimeMinutes: null,
            scoring: QuizScoring::Note20,
            scoreVisibleImmediately: true,
            visibilityOption: $option,
        );
    }

    private function program(): Program
    {
        return new Program(
            'SIO-2 2026-2027',
            'SIO-2',
            new Cohort('SIO-2', new Track('SIO', new Section('BTS'))),
            new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30')),
        );
    }
}
