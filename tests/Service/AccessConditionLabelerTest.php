<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AccessConditionComparison;
use App\Enum\AccessConditionMoment;
use App\Enum\AccessConditionType;
use App\Service\AccessConditionLabeler;
use App\Service\AccessConditionLeaf;
use App\Service\AccessConditionNames;
use App\Service\StudentAccessFacts;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a locked row says, and - just as important - what it does not say. A reason names the object
 * that opens the way out only when the reader is entitled to know it exists; otherwise it falls back
 * on a generic, so a greyed row can never leak the existence of somebody else's activity.
 */
class AccessConditionLabelerTest extends TestCase
{
    public function testItNamesTheAssignmentToHandIn(): void
    {
        $reason = $this->labeler()->reason(
            new AccessConditionLeaf(AccessConditionType::AssignmentDone, 17),
            new AccessConditionNames(['assignment_done' => [17 => 'TP 3 — mise en place VLAN']]),
            $this->facts(),
        );

        self::assertSame('accessConditionReasonAssignmentDone{name: TP 3 — mise en place VLAN}', $reason);
    }

    /** The rule of the conception: an unnamed object is one this reader may not be told about. */
    public function testAnObjectTheReaderCannotSeeFallsBackOnAGeneric(): void
    {
        $reason = $this->labeler()->reason(
            new AccessConditionLeaf(AccessConditionType::AssignmentDone, 17),
            new AccessConditionNames([]),
            $this->facts(),
        );

        self::assertSame('accessConditionReasonAssignmentDone{name: accessConditionGenericTarget}', $reason);
    }

    /** A threshold reads as it was typed: "10", never "10,00", and a half point stays a half point. */
    public function testAGradeConditionSaysItsThresholdAndItsDirection(): void
    {
        $names = new AccessConditionNames(['grade_value' => [88 => 'Sommative HTML']]);

        self::assertSame(
            'accessConditionReasonGradeAbove{name: Sommative HTML, value: 10}',
            $this->labeler()->reason($this->gradeLeaf(AccessConditionComparison::Above, 10.0), $names, $this->facts()),
        );
        self::assertSame(
            'accessConditionReasonGradeBelow{name: Sommative HTML, value: 12,5}',
            $this->labeler()->reason($this->gradeLeaf(AccessConditionComparison::Below, 12.5), $names, $this->facts()),
        );
    }

    private function gradeLeaf(AccessConditionComparison $comparison, float $value): AccessConditionLeaf
    {
        return new AccessConditionLeaf(AccessConditionType::GradeValue, 88, comparison: $comparison, value: $value);
    }

    public function testAScoreConditionSaysItsThreshold(): void
    {
        $names = new AccessConditionNames(['quiz_score' => [42 => 'Réseaux 2']]);

        self::assertSame(
            'accessConditionReasonQuizScoreMin{name: Réseaux 2, percent: 60}',
            $this->labeler()->reason(new AccessConditionLeaf(AccessConditionType::QuizScore, 42, minPercent: 60), $names, $this->facts()),
        );
        self::assertSame(
            'accessConditionReasonQuizScoreMax{name: Réseaux 2, percent: 60}',
            $this->labeler()->reason(new AccessConditionLeaf(AccessConditionType::QuizScore, 42, maxPercent: 60), $names, $this->facts()),
        );
        self::assertSame(
            'accessConditionReasonQuizScoreRange{name: Réseaux 2, min: 30, max: 60}',
            $this->labeler()->reason(new AccessConditionLeaf(AccessConditionType::QuizScore, 42, minPercent: 30, maxPercent: 60), $names, $this->facts()),
        );
    }

    /** A whole listening and a partial one are not the same sentence. */
    public function testAListeningSaysWhetherItIsTheWholeThing(): void
    {
        $names = new AccessConditionNames(['audio_listened' => [9 => 'Compréhension orale 2']]);

        self::assertSame(
            'accessConditionReasonAudioListenedWhole{name: Compréhension orale 2}',
            $this->labeler()->reason(new AccessConditionLeaf(AccessConditionType::AudioListened, 9), $names, $this->facts()),
        );
        self::assertSame(
            'accessConditionReasonAudioListenedPartial{name: Compréhension orale 2, percent: 50}',
            $this->labeler()->reason(new AccessConditionLeaf(AccessConditionType::AudioListened, 9, minPercent: 50), $names, $this->facts()),
        );
    }

    public function testASeanceOnTheTimetableIsNamedWithItsDate(): void
    {
        $reason = $this->labeler()->reason(
            new AccessConditionLeaf(AccessConditionType::SeancePassed, 412),
            new AccessConditionNames(['seance_passed' => [412 => '4 — TP, mise en place complète']]),
            $this->facts(seanceEndDates: [412 => new \DateTimeImmutable('2026-09-18 16:00:00')]),
        );

        self::assertStringStartsWith('accessConditionReasonSeanceEnd{name: 4 — TP, mise en place complète, date: ', $reason);
    }

    /**
     * "Disponible après la séance 4" would let a student wait for a date that does not exist. The
     * sentence has to say the séance is not placed yet - the conception's own words.
     */
    public function testASeanceWithNoSlotSaysSoRatherThanPromisingADate(): void
    {
        $reason = $this->labeler()->reason(
            new AccessConditionLeaf(AccessConditionType::SeancePassed, 412),
            new AccessConditionNames(['seance_passed' => [412 => '4 — TP']]),
            $this->facts(seanceEndDates: [412 => null]),
        );

        self::assertSame('accessConditionReasonSeanceNotScheduled{name: 4 — TP}', $reason);
    }

    public function testTheStartMomentIsItsOwnSentence(): void
    {
        $reason = $this->labeler()->reason(
            new AccessConditionLeaf(AccessConditionType::SeancePassed, 412, moment: AccessConditionMoment::Start),
            new AccessConditionNames(['seance_passed' => [412 => '4 — TP']]),
            $this->facts(seanceStartDates: [412 => new \DateTimeImmutable('2026-09-18 14:00:00')]),
        );

        self::assertStringStartsWith('accessConditionReasonSeanceStart{', $reason);
    }

    public function testADateIsReadOffTheLeafItself(): void
    {
        $reason = $this->labeler()->reason(
            new AccessConditionLeaf(AccessConditionType::DateFrom, at: new \DateTimeImmutable('2026-09-15 08:00:00')),
            new AccessConditionNames([]),
            $this->facts(),
        );

        self::assertStringStartsWith('accessConditionReasonDateFrom{date: ', $reason);
    }

    /** Several unmet leaves make several sentences, and the same one twice makes one. */
    public function testReasonsAreDeduplicated(): void
    {
        $reasons = $this->labeler()->reasons([
            new AccessConditionLeaf(AccessConditionType::AssignmentDone, 17),
            new AccessConditionLeaf(AccessConditionType::AssignmentDone, 17),
            new AccessConditionLeaf(AccessConditionType::ResourceViewed, 31),
        ], new AccessConditionNames([]), $this->facts());

        self::assertCount(2, $reasons);
    }

    private function labeler(): AccessConditionLabeler
    {
        return new AccessConditionLabeler($this->translator());
    }

    /**
     * @param array<int, ?\DateTimeImmutable> $seanceStartDates
     * @param array<int, ?\DateTimeImmutable> $seanceEndDates
     */
    private function facts(array $seanceStartDates = [], array $seanceEndDates = []): StudentAccessFacts
    {
        return new StudentAccessFacts(
            new \DateTimeImmutable('2026-09-10 09:00:00'),
            seanceStartDates: $seanceStartDates,
            seanceEndDates: $seanceEndDates,
        );
    }

    /** Renders "key{param: value, …}" so a test reads the key and its parameters, not French. */
    private function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                if ([] === $parameters) {
                    return $id;
                }

                $pairs = [];
                foreach ($parameters as $name => $value) {
                    $pairs[] = \sprintf('%s: %s', trim((string) $name, '%'), \is_scalar($value) ? (string) $value : '');
                }

                return \sprintf('%s{%s}', $id, implode(', ', $pairs));
            }

            public function getLocale(): string
            {
                return 'fr';
            }
        };
    }
}
