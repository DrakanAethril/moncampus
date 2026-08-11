<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizInstanceQuestion;
use App\Enum\QuestionType;
use App\Service\QuizAnswerChecker;
use App\Service\QuizAttemptGrader;
use PHPUnit\Framework\TestCase;

/**
 * Grading rules of the two "zones" question types (design/design_handoff_quiz follow-up, étude
 * 2026-08-11): `zone` (click the right zone(s) in a support) and `legende` (place each label on
 * its zone). Same single-rule setup as the blanks: QuizAnswerChecker holds the verdict,
 * QuizAttemptGrader turns it into points, and both the real passation and the teacher's "Tester"
 * preview go through them.
 */
class QuizZoneGradingTest extends TestCase
{
    private QuizAnswerChecker $checker;
    private QuizAttemptGrader $grader;

    protected function setUp(): void
    {
        $this->checker = new QuizAnswerChecker();
        $this->grader = new QuizAttemptGrader($this->checker);
    }

    // --- zone: click the right zone(s) ---

    public function testZoneSelectionMustMatchTheCorrectSetExactly(): void
    {
        $question = $this->zoneQuestion('Cliquez la fermeture. [[z1|<nav>]]…[[z2|</nav>]]', ['z2']);

        self::assertTrue($this->checker->isCorrect($question, [], [], [], ['z2']));
        self::assertFalse($this->checker->isCorrect($question, [], [], [], ['z1']));
        self::assertFalse($this->checker->isCorrect($question, [], [], [], []), 'no click is not an answer');
        self::assertFalse($this->checker->isCorrect($question, [], [], [], ['z1', 'z2']), 'clicking everything is not an answer');
    }

    public function testZoneWithSeveralTargetsIgnoresClickOrder(): void
    {
        $question = $this->zoneQuestion('Cliquez les verbes. [[a|court]] et [[b|nage]] et [[c|bleu]]', ['a', 'b']);

        self::assertTrue($this->checker->isCorrect($question, [], [], [], ['b', 'a']));
        self::assertFalse($this->checker->isCorrect($question, [], [], [], ['a']), 'a partial set is wrong');
    }

    public function testAZoneQuestionWithNoCorrectZoneConfiguredIsNeverRight(): void
    {
        // Same guard as an answerless QCM: an unfinished question must not hand out points.
        $question = $this->zoneQuestion('[[z1|<p>]]', []);

        self::assertFalse($this->checker->isCorrect($question, [], [], [], ['z1']));
    }

    public function testACorrectIdUnknownToTheSupportIsIgnored(): void
    {
        // A stale config (the teacher rewrote the support) must not make a question unwinnable
        // silently at grading time - the unknown id simply no longer exists.
        $question = $this->zoneQuestion('[[z1|<p>]]', ['z9']);

        self::assertSame([], $question->getZoneCorrectIds());
        self::assertFalse($this->checker->isCorrect($question, [], [], [], ['z9']));
    }

    public function testZoneScoreIsAllOrNothingTimesThePoints(): void
    {
        $question = $this->zoneQuestion('[[z1|<nav>]] [[z2|</nav>]]', ['z2'], points: 2.0);

        self::assertSame(2.0, $this->grader->score($question, [], [], ['z2']));
        self::assertSame(0.0, $this->grader->score($question, [], [], ['z1']));
    }

    // --- legende: place each label on its zone ---

    public function testLegendePlacementsAreGradedPerZone(): void
    {
        $question = $this->legendeQuestion(
            '[[s|.menu]] { [[p|color]]: red; }',
            ['s' => 'Sélecteur', 'p' => 'Propriété'],
        );

        self::assertSame(['s' => true, 'p' => true], $this->checker->zoneResults($question, ['s' => 's', 'p' => 'p']));
        self::assertSame(['s' => false, 'p' => true], $this->checker->zoneResults($question, ['s' => 'p', 'p' => 'p']));
        self::assertSame(['s' => false, 'p' => false], $this->checker->zoneResults($question, []), 'nothing placed, nothing right');
    }

    public function testAPlacedDistractorIsAlwaysWrong(): void
    {
        $question = $this->legendeQuestion('[[s|.menu]] {}', ['s' => 'Sélecteur'], ['Attribut']);

        self::assertSame(['s' => false], $this->checker->zoneResults($question, ['s' => 'd0']));
    }

    public function testLegendeIsCorrectOnlyWhenEveryZoneIsRight(): void
    {
        $question = $this->legendeQuestion('[[a|x]] et [[b|y]]', ['a' => 'A', 'b' => 'B']);

        self::assertTrue($this->checker->isCorrect($question, [], [], [], ['a' => 'a', 'b' => 'b']));
        self::assertFalse($this->checker->isCorrect($question, [], [], [], ['a' => 'a', 'b' => 'a']));
    }

    public function testLegendeScoreSplitsThePointsEquallyBetweenZones(): void
    {
        // Same partial-credit rule as the blanks - 1 of 2 zones right on a 1-point question is 0.5.
        $question = $this->legendeQuestion('[[a|x]] et [[b|y]]', ['a' => 'A', 'b' => 'B']);

        self::assertSame(0.5, $this->grader->score($question, [], [], ['a' => 'a', 'b' => 'x']));
        self::assertSame(1.0, $this->grader->score($question, [], [], ['a' => 'a', 'b' => 'b']));
        self::assertSame(0.0, $this->grader->score($question, [], [], []));
    }

    public function testZoneResultsAreEmptyForOtherTypes(): void
    {
        $other = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $other->setType(QuestionType::Qcm);

        self::assertSame([], $this->checker->zoneResults($other, ['a' => 'a']));
    }

    // --- config accessors the grading leans on ---

    public function testLegendeChoicesMixRealLabelsAndNumberedDistractors(): void
    {
        $question = $this->legendeQuestion('[[s|.menu]] {}', ['s' => 'Sélecteur'], ['Attribut', 'Valeur']);

        self::assertSame(
            [
                ['key' => 's', 'text' => 'Sélecteur'],
                ['key' => 'd0', 'text' => 'Attribut'],
                ['key' => 'd1', 'text' => 'Valeur'],
            ],
            $question->getLegendeChoices(),
        );
    }

    public function testAMissingLabelFallsBackToTheZoneOwnText(): void
    {
        // A label the teacher never filled in still has to be placeable - the zone's own text is
        // the only honest default.
        $question = $this->legendeQuestion('[[s|.menu]] et [[p|color]]', ['s' => 'Sélecteur']);

        self::assertSame('color', $question->getZoneLabelTexts()['p']);
    }

    public function testFeedbackFallsBackToTheWildcardEntry(): void
    {
        $question = $this->zoneQuestion('[[z1|a]] [[z2|b]] [[z3|c]]', ['z1']);
        $question->setZoneConfig(array_merge($question->getZoneConfig() ?? [], [
            'feedback' => ['z2' => 'Not that one.', '*' => 'Wrong element.'],
        ]));

        self::assertSame('Not that one.', $question->getZoneFeedbackFor('z2'));
        self::assertSame('Wrong element.', $question->getZoneFeedbackFor('z3'));
        self::assertNull($question->getZoneFeedbackFor('z1'), 'the right zone needs no error feedback');
    }

    public function testHintIdsAreBoundedByTheSupport(): void
    {
        $question = $this->zoneQuestion('[[z1|a]] [[z2|b]]', ['z1'], hint: ['z2', 'z9']);

        self::assertSame(['z2'], $question->getZoneHintIds());
    }

    // --- enum plumbing ---

    public function testZoneTypesAreExcludedFromLiveAndFromAnswerRows(): void
    {
        foreach ([QuestionType::Zone, QuestionType::Legende] as $type) {
            self::assertFalse($type->isAvailableInLiveContest());
            self::assertFalse($type->usesAnswerRows());
            self::assertTrue($type->usesZoneConfig());
        }
        self::assertFalse(QuestionType::Qcm->usesZoneConfig());
        self::assertFalse(QuestionType::TexteATrous->usesZoneConfig());
    }

    /** @param list<string> $correct @param list<string> $hint */
    private function zoneQuestion(string $content, array $correct, float $points = 1.0, array $hint = []): QuizInstanceQuestion
    {
        $question = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $question->setType(QuestionType::Zone);
        $question->setLabel('Consigne');
        $question->setZoneConfig(['kind' => 'code', 'language' => 'html', 'content' => $content, 'correct' => $correct, 'hint' => $hint]);
        $question->setPoints($points);

        return $question;
    }

    /** @param array<string, string> $labels @param list<string> $distractors */
    private function legendeQuestion(string $content, array $labels, array $distractors = [], float $points = 1.0): QuizInstanceQuestion
    {
        $question = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $question->setType(QuestionType::Legende);
        $question->setLabel('Consigne');
        $question->setZoneConfig(['kind' => 'code', 'language' => 'css', 'content' => $content, 'labels' => $labels, 'distractors' => $distractors]);
        $question->setPoints($points);

        return $question;
    }
}
