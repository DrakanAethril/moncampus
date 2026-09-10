<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Enum\Feature;
use App\Security\FeatureAccess;
use App\Security\Voter\LessonLogVoter;
use App\Service\LessonSessionEventFormatter;
use App\Service\NameColorGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Whether a séance on the calendar can be clicked at all.
 *
 * The emploi du temps is a feature of its own, and a student may perfectly well have it and
 * nothing else - `lesson_log` is off by default for everybody, the whole Pedagogy family being
 * unlit. Handing the calendar a cahier-de-texte URL regardless turned every séance of that screen
 * into a 404, which is what these tests pin: the destination's two conditions are asked before the
 * click is offered, not after it is taken.
 */
class LessonSessionEventFormatterTest extends TestCase
{
    public function testASeanceCarriesItsCahierDeTexteWhenTheViewerMayOpenIt(): void
    {
        $event = $this->formatter(featureOn: true, mayView: true)->format($this->session(), editable: false);

        self::assertSame('/log', $event['extendedProps']['logUrl']);
    }

    /** The reported bug: the emploi du temps is lit, the cahier de texte is not. */
    public function testAnUnlitCahierDeTexteLeavesTheSeanceInert(): void
    {
        $event = $this->formatter(featureOn: false, mayView: true)->format($this->session(), editable: false);

        self::assertNull($event['extendedProps']['logUrl']);
    }

    /** The feature can be lit for the account and the séance still be somebody else's. */
    public function testAVoterRefusalLeavesTheSeanceInertToo(): void
    {
        $event = $this->formatter(featureOn: true, mayView: false)->format($this->session(), editable: false);

        self::assertNull($event['extendedProps']['logUrl']);
    }

    /** Nothing else about the event depends on the answer - the slot stays fully readable. */
    public function testAnInertSeanceStillPrintsEverythingElse(): void
    {
        $event = $this->formatter(featureOn: false, mayView: false)->format($this->session(), editable: false);

        self::assertSame('2026-09-14T08:00:00', $event['start']);
        self::assertSame('2026-09-14T10:00:00', $event['end']);
        self::assertStringContainsString('SIO-2', $event['extendedProps']['program']);
    }

    private function formatter(bool $featureOn, bool $mayView): LessonSessionEventFormatter
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/log');

        $featureAccess = $this->createStub(FeatureAccess::class);
        $featureAccess->method('isEnabled')->willReturnCallback(
            static fn (Feature $feature): bool => Feature::LessonLog === $feature && $featureOn,
        );

        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => LessonLogVoter::VIEW === $attribute && $mayView,
        );

        return new LessonSessionEventFormatter($urlGenerator, new NameColorGenerator(), $featureAccess, $authorization);
    }

    private function session(): LessonSession
    {
        $session = new LessonSession(new Program(
            'SIO-2 2026-2027',
            'SIO-2',
            $this->createStub(Cohort::class),
            $this->createStub(SchoolYear::class),
        ));

        return $session
            ->setDay(new \DateTimeImmutable('2026-09-14'))
            ->setStartHour(new \DateTimeImmutable('2026-09-14 08:00'))
            ->setEndHour(new \DateTimeImmutable('2026-09-14 10:00'));
    }
}
