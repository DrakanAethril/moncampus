<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Enum\QuizQuestionGap;
use App\Service\QuizQuestionCompleteness;
use PHPUnit\Framework\TestCase;

/**
 * "Incomplète" is a third state, next to valid and in error: a question that can be created but not
 * yet played. One mechanism, two causes - a media that was named but never attached, and zones that
 * a model cannot place. See design/comparaison/conception_import_quiz_ia.md, sections 5 bis/5 ter.
 */
class QuizQuestionCompletenessTest extends TestCase
{
    private QuizQuestionCompleteness $completeness;

    private QuizTemplate $template;

    protected function setUp(): void
    {
        $this->completeness = new QuizQuestionCompleteness();
        $this->template = new QuizTemplate(new User('teacher'));
    }

    public function testAnOrdinaryQuestionHasNoGap(): void
    {
        self::assertNull($this->completeness->gapOf($this->question(QuestionType::Qcm)));
    }

    public function testANamedButUnattachedMediaIsAGap(): void
    {
        $question = $this->question(QuestionType::Qcm);
        $question->setExpectedMediaName('schema-reseau-etage2.png');

        self::assertSame(QuizQuestionGap::Media, $this->completeness->gapOf($question));
    }

    /**
     * An image question with no image at all is the same broken passation as a named-but-missing
     * one - the student would be asked about a picture nobody can see.
     */
    public function testAnImageQuestionWithoutItsImageIsAGap(): void
    {
        $question = $this->question(QuestionType::Image);

        self::assertSame(QuizQuestionGap::Media, $this->completeness->gapOf($question));

        $question->setImageStorageKey('quiz-question-images/abc.png');
        self::assertNull($this->completeness->gapOf($question));
    }

    /** Attaching the file is what closes the gap: the name it was waiting for goes with it. */
    public function testAttachingTheImageClosesTheGap(): void
    {
        $question = $this->question(QuestionType::Image);
        $question->setExpectedMediaName('schema.png');
        $question->attachMedia('quiz-question-images/abc.png');

        self::assertNull($this->completeness->gapOf($question));
        self::assertNull($question->getExpectedMediaName());
        self::assertSame('quiz-question-images/abc.png', $question->getImageStorageKey());
    }

    /**
     * A zone question drawn on an image the model could not place: same state, other cause, and the
     * screen sends the teacher to the visual editor rather than to a file picker.
     */
    public function testAZoneQuestionOnAnImageSupportWaitsForItsZones(): void
    {
        $question = $this->question(QuestionType::Zone);
        $question->setZoneConfig(['kind' => 'image', 'zones' => []]);

        self::assertSame(QuizQuestionGap::Zones, $this->completeness->gapOf($question));

        $question->setImageStorageKey('quiz-question-images/abc.png');
        self::assertNull($this->completeness->gapOf($question));
    }

    public function testAZoneQuestionOnATextSupportIsComplete(): void
    {
        $question = $this->question(QuestionType::Zone);
        $question->setZoneConfig(['kind' => 'code', 'content' => 'switchport mode [[z1|trunk]]']);

        self::assertNull($this->completeness->gapOf($question));
    }

    public function testIncompleteListsTheQuestionsThatBlockALaunch(): void
    {
        $fine = $this->question(QuestionType::Qcm);
        $waiting = $this->question(QuestionType::Image);
        $waiting->setExpectedMediaName('schema.png');

        self::assertSame([$waiting], $this->completeness->incomplete([$fine, $waiting]));
        self::assertSame(1, $this->completeness->countIncomplete([$fine, $waiting]));
        self::assertSame(0, $this->completeness->countIncomplete([$fine]));
    }

    private function question(QuestionType $type): QuizQuestion
    {
        $question = new QuizQuestion($this->template);
        $question->setType($type);
        $question->setLabel('Question');

        return $question;
    }
}
