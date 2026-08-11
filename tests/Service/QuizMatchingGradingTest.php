<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizInstanceQuestion;
use App\Enum\QuestionType;
use App\Service\QuizAnswerChecker;
use App\Service\QuizAttemptGrader;
use PHPUnit\Framework\TestCase;

/**
 * Grading rules of the `apparier` question type (2026-08-11): relate each item of the left column
 * to its item in the right one. Same single-rule setup as the blanks and the zones:
 * QuizAnswerChecker holds the verdict, QuizAttemptGrader turns it into points, and both the real
 * passation and the teacher's "Tester" preview go through them.
 */
class QuizMatchingGradingTest extends TestCase
{
    private QuizAnswerChecker $checker;
    private QuizAttemptGrader $grader;

    protected function setUp(): void
    {
        $this->checker = new QuizAnswerChecker();
        $this->grader = new QuizAttemptGrader($this->checker);
    }

    // --- the verdict ---

    public function testEveryPairMustBeAssociatedWithItsOwnItem(): void
    {
        $question = $this->matchingQuestion([
            ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
            ['id' => 'p2', 'left' => 'Italie', 'right' => 'Rome'],
        ]);

        self::assertTrue($this->isCorrect($question, ['p1' => 'p1', 'p2' => 'p2']));
        self::assertFalse($this->isCorrect($question, ['p1' => 'p2', 'p2' => 'p1']), 'swapped is wrong');
        self::assertFalse($this->isCorrect($question, ['p1' => 'p1']), 'a partial answer is wrong');
        self::assertFalse($this->isCorrect($question, []), 'no association is not an answer');
    }

    public function testAssociatingADistractorIsWrong(): void
    {
        $question = $this->matchingQuestion([
            ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
            ['id' => 'p2', 'left' => 'Italie', 'right' => 'Rome'],
        ], ['Bruxelles']);

        self::assertFalse($this->isCorrect($question, ['p1' => 'd0', 'p2' => 'p2']));
    }

    public function testAQuestionWithNoUsablePairIsNeverRight(): void
    {
        // Same guard as an answerless QCM: an unfinished question must not hand out points.
        $question = $this->matchingQuestion([]);

        self::assertFalse($this->isCorrect($question, ['p1' => 'p1']));
        self::assertSame(0.0, $this->score($question, ['p1' => 'p1']));
    }

    public function testAHalfWrittenPairIsDroppedRatherThanGradedAgainst(): void
    {
        // A row missing its right-hand side would be an unanswerable slot: the student could never
        // get it right, so it must not count against them either.
        $question = $this->matchingQuestion([
            ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
            ['id' => 'p2', 'left' => 'Italie', 'right' => ''],
        ]);

        self::assertSame(['p1'], $question->getMatchingPairIds());
        self::assertTrue($this->isCorrect($question, ['p1' => 'p1']));
    }

    public function testTwoPairsSharingAnAnswerAcceptEitherChip(): void
    {
        // The student sees two identical chips and cannot tell which key they carry - grading on
        // the key would mark one of the two wrong at random, which is why it grades on the text.
        $question = $this->matchingQuestion([
            ['id' => 'p1', 'left' => 'Capitale de la France', 'right' => 'Paris'],
            ['id' => 'p2', 'left' => 'Ville des accords de 2015', 'right' => 'Paris'],
        ]);

        self::assertTrue($this->isCorrect($question, ['p1' => 'p2', 'p2' => 'p1']));
    }

    public function testADistractorRepeatingARealAnswerIsAccepted(): void
    {
        // Same reasoning: the two chips are indistinguishable, so picking the decoy cannot be the
        // student's mistake. Both importers drop such a distractor on the way in anyway.
        $question = $this->matchingQuestion([
            ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
            ['id' => 'p2', 'left' => 'Italie', 'right' => 'Rome'],
        ], ['Paris']);

        self::assertTrue($this->isCorrect($question, ['p1' => 'd0', 'p2' => 'p2']));
    }

    // --- the points ---

    public function testScoreSplitsThePointsEquallyBetweenPairs(): void
    {
        $question = $this->matchingQuestion([
            ['id' => 'p1', 'left' => 'a', 'right' => 'A'],
            ['id' => 'p2', 'left' => 'b', 'right' => 'B'],
            ['id' => 'p3', 'left' => 'c', 'right' => 'C'],
            ['id' => 'p4', 'left' => 'd', 'right' => 'D'],
        ]);

        self::assertSame(0.75, $this->score($question, ['p1' => 'p1', 'p2' => 'p2', 'p3' => 'p3', 'p4' => 'p1']));
        self::assertSame(1.0, $this->score($question, ['p1' => 'p1', 'p2' => 'p2', 'p3' => 'p3', 'p4' => 'p4']));
        self::assertSame(0.0, $this->score($question, []));
    }

    public function testScoreFollowsTheQuestionOwnBareme(): void
    {
        $question = $this->matchingQuestion([
            ['id' => 'p1', 'left' => 'a', 'right' => 'A'],
            ['id' => 'p2', 'left' => 'b', 'right' => 'B'],
        ]);
        $question->setPoints(3.0);

        self::assertSame(1.5, $this->score($question, ['p1' => 'p1']));
    }

    public function testMatchingResultsAreEmptyForOtherTypes(): void
    {
        $other = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $other->setType(QuestionType::Qcm);

        self::assertSame([], $this->checker->matchingResults($other, ['p1' => 'p1']));
    }

    // --- config accessors the grading leans on ---

    public function testChoicesMixRealAnswersAndNumberedDistractors(): void
    {
        $question = $this->matchingQuestion([
            ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
        ], ['Bruxelles', 'Genève']);

        self::assertSame(
            [
                ['key' => 'p1', 'text' => 'Paris', 'image' => null],
                ['key' => 'd0', 'text' => 'Bruxelles', 'image' => null],
                ['key' => 'd1', 'text' => 'Genève', 'image' => null],
            ],
            $question->getMatchingChoices(),
        );
    }

    public function testDuplicatePairIdsKeepTheFirstRow(): void
    {
        $question = $this->matchingQuestion([
            ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
            ['id' => 'p1', 'left' => 'Italie', 'right' => 'Rome'],
        ]);

        self::assertSame(
            [['id' => 'p1', 'left' => 'France', 'right' => 'Paris', 'leftImage' => null, 'rightImage' => null]],
            $question->getMatchingPairs(),
        );
    }

    public function testFeedbackFallsBackToTheWildcardEntry(): void
    {
        $question = $this->matchingQuestion([
            ['id' => 'p1', 'left' => 'a', 'right' => 'A'],
            ['id' => 'p2', 'left' => 'b', 'right' => 'B'],
        ]);
        $question->setMatchingConfig(array_merge($question->getMatchingConfig() ?? [], [
            'feedback' => ['p1' => 'Not that one.', '*' => 'Look again at the wording.'],
        ]));

        self::assertSame('Not that one.', $question->getMatchingFeedbackFor('p1'));
        self::assertSame('Look again at the wording.', $question->getMatchingFeedbackFor('p2'));
        // The editor re-renders the raw map: resolving the fallback here would copy the wildcard
        // into every row and save it back as N per-pair entries.
        self::assertSame(['p1' => 'Not that one.', '*' => 'Look again at the wording.'], $question->getMatchingFeedbacks());
    }

    public function testHeadersDefaultToEmptyStringsRatherThanNull(): void
    {
        $question = $this->matchingQuestion([['id' => 'p1', 'left' => 'a', 'right' => 'A']]);

        self::assertSame(['left' => '', 'right' => ''], $question->getMatchingHeaders());
    }

    public function testATotallyBrokenConfigIsReadAsAnEmptyQuestion(): void
    {
        // This is stored JSON: an entry may be anything at all, and nothing here may throw.
        $question = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $question->setType(QuestionType::Apparier);
        $question->setMatchingConfig(['pairs' => 'nope', 'distractors' => 42, 'feedback' => 'no']);

        self::assertSame([], $question->getMatchingPairs());
        self::assertSame([], $question->getMatchingDistractors());
        self::assertSame([], $question->getMatchingFeedbacks());
        self::assertNull($question->getMatchingFeedbackFor('p1'));
    }

    // --- image columns ---

    public function testAnImageColumnIsGradedOnItsImageNotOnItsAltText(): void
    {
        // Two pairs sharing an alt text ("schéma") but showing different pictures must still be
        // told apart - which is why the signature is the key, not the caption.
        $question = $this->imageQuestion([
            ['id' => 'p1', 'left' => 'Cellule animale', 'right' => 'schéma', 'rightImage' => 'k/animal.png'],
            ['id' => 'p2', 'left' => 'Cellule végétale', 'right' => 'schéma', 'rightImage' => 'k/vegetal.png'],
        ]);

        self::assertTrue($this->isCorrect($question, ['p1' => 'p1', 'p2' => 'p2']));
        self::assertFalse($this->isCorrect($question, ['p1' => 'p2', 'p2' => 'p1']));
    }

    public function testTwoPairsSharingAnImageAcceptEitherChip(): void
    {
        // The text rule, one kind over: identical pictures are indistinguishable to the student.
        $question = $this->imageQuestion([
            ['id' => 'p1', 'left' => 'Vue de face', 'right' => '', 'rightImage' => 'k/same.png'],
            ['id' => 'p2', 'left' => 'Vue de dos', 'right' => '', 'rightImage' => 'k/same.png'],
        ]);

        self::assertTrue($this->isCorrect($question, ['p1' => 'p2', 'p2' => 'p1']));
    }

    public function testAnImagePairMissingItsImageIsDroppedEvenWithAnAltText(): void
    {
        // On an image column the picture is the item; an alt text alone is an unanswerable slot.
        $question = $this->imageQuestion([
            ['id' => 'p1', 'left' => 'a', 'right' => 'A', 'rightImage' => 'k/a.png'],
            ['id' => 'p2', 'left' => 'b', 'right' => 'B'],
        ]);

        self::assertSame(['p1'], $question->getMatchingPairIds());
    }

    public function testTextChoicesAndImageDecoysNeverCollideOnASignature(): void
    {
        // A text item literally named "k/a.png" must not be accepted for the image stored there.
        $question = $this->imageQuestion([
            ['id' => 'p1', 'left' => 'a', 'right' => 'k/a.png', 'rightImage' => 'k/a.png'],
            ['id' => 'p2', 'left' => 'b', 'right' => 'B', 'rightImage' => 'k/b.png'],
        ]);

        $signatures = $question->getMatchingSignatures();
        self::assertSame('img:k/a.png', $signatures['p1']);
        self::assertNotSame($signatures['p1'], 'txt:k/a.png');
    }

    public function testAnImageColumnIgnoresTextDistractorsAndViceVersa(): void
    {
        $question = $this->imageQuestion([
            ['id' => 'p1', 'left' => 'a', 'right' => 'A', 'rightImage' => 'k/a.png'],
            ['id' => 'p2', 'left' => 'b', 'right' => 'B', 'rightImage' => 'k/b.png'],
        ]);
        $question->setMatchingConfig([...$question->getMatchingConfig() ?? [],
            'distractors' => ['Un mot'],
            'distractorImages' => ['k/decoy.png'],
        ]);

        self::assertSame([], $question->getMatchingDistractors(), 'a word cannot decoy a picture');
        self::assertSame(['k/decoy.png'], $question->getMatchingDistractorImages());
        self::assertSame(['p1', 'p2', 'di0'], array_column($question->getMatchingChoices(), 'key'));
    }

    public function testImageKeysAreOnlySurfacedForTheColumnThatIsAnImageOne(): void
    {
        // A leftover key from a column switched back to text must not render as a picture.
        $question = $this->matchingQuestion([
            ['id' => 'p1', 'left' => 'France', 'right' => 'Paris', 'rightImage' => 'k/stale.png'],
            ['id' => 'p2', 'left' => 'Italie', 'right' => 'Rome'],
        ]);

        self::assertNull($question->getMatchingPairs()[0]['rightImage']);
        self::assertSame([], $question->getMatchingImageKeys());
    }

    public function testImageKeysListEveryPictureTheQuestionOwnsWithoutRepeats(): void
    {
        $question = $this->imageQuestion([
            ['id' => 'p1', 'left' => 'a', 'right' => '', 'rightImage' => 'k/a.png'],
            ['id' => 'p2', 'left' => 'b', 'right' => '', 'rightImage' => 'k/a.png'],
            ['id' => 'p3', 'left' => 'c', 'right' => '', 'rightImage' => 'k/c.png'],
        ]);
        $question->setMatchingConfig([...$question->getMatchingConfig() ?? [], 'distractorImages' => ['k/d.png']]);

        self::assertSame(['k/a.png', 'k/c.png', 'k/d.png'], $question->getMatchingImageKeys());
    }

    // --- enum plumbing ---

    public function testApparierIsExcludedFromLiveAndFromAnswerRows(): void
    {
        self::assertFalse(QuestionType::Apparier->isAvailableInLiveContest());
        self::assertFalse(QuestionType::Apparier->usesAnswerRows());
        self::assertFalse(QuestionType::Apparier->usesZoneConfig());
        self::assertTrue(QuestionType::Apparier->usesMatchingConfig());
        self::assertFalse(QuestionType::Qcm->usesMatchingConfig());
        self::assertFalse(QuestionType::Legende->usesMatchingConfig());
    }

    /** @param array<array-key, string> $associations */
    private function isCorrect(QuizInstanceQuestion $question, array $associations): bool
    {
        return $this->checker->isCorrect($question, [], [], [], [], $associations);
    }

    /** @param array<array-key, string> $associations */
    private function score(QuizInstanceQuestion $question, array $associations): float
    {
        return $this->grader->score($question, [], [], [], $associations);
    }

    /**
     * @param list<array<string, string>> $pairs
     * @param list<string>                $distractors
     */
    private function matchingQuestion(array $pairs, array $distractors = []): QuizInstanceQuestion
    {
        // newInstanceWithoutConstructor: QuizInstanceQuestion needs a QuizInstance it has no use
        // for here, and grading only ever reads the definition (same shortcut as the zones tests).
        $question = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $question->setType(QuestionType::Apparier);
        $question->setLabel('Reliez chaque élément.');
        $question->setMatchingConfig(['pairs' => $pairs, 'distractors' => $distractors]);

        return $question;
    }

    /** @param list<array<string, string>> $pairs a question whose right column holds pictures */
    private function imageQuestion(array $pairs): QuizInstanceQuestion
    {
        $question = $this->matchingQuestion($pairs);
        $question->setMatchingConfig([...$question->getMatchingConfig() ?? [], 'rightKind' => 'image']);

        return $question;
    }
}
