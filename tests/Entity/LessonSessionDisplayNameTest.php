<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Cohort;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Topic;
use PHPUnit\Framework\TestCase;

/**
 * What names a timetable slot on screen.
 *
 * The title wins over the matière whenever there is one: it is typed for that slot in particular,
 * so it says what the matière cannot. The order has been reversed once already (the title used to
 * be a copy of a séance's name, written by modules that no longer write it), which is exactly why
 * it is pinned here rather than left to the calendars that read it.
 */
class LessonSessionDisplayNameTest extends TestCase
{
    public function testTheTitleNamesTheSlotWhenThereIsOne(): void
    {
        $session = $this->session();
        $session->setTitle('Bac blanc')->setTopic(new Topic('Mathématiques', $session->getProgram()));

        self::assertSame('Bac blanc', $session->getDisplayName());
    }

    public function testTheMatiereNamesAnUntitledSlot(): void
    {
        $session = $this->session();
        $session->setTopic(new Topic('Mathématiques', $session->getProgram()));

        self::assertSame('Mathématiques', $session->getDisplayName());
    }

    public function testASlotWithNeitherStillHasSomethingToPrint(): void
    {
        self::assertSame('—', $this->session()->getDisplayName());
    }

    // The Program is only there because the constructor asks for one - nothing about it takes part
    // in the answer.
    private function session(): LessonSession
    {
        return new LessonSession(new Program(
            'SIO-2 2026-2027',
            'SIO-2',
            $this->createStub(Cohort::class),
            $this->createStub(SchoolYear::class),
        ));
    }
}
