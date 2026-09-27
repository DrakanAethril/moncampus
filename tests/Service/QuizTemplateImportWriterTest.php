<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Service\InteractiveQuizImporter;
use App\Service\QuizTemplateImportWriter;
use PHPUnit\Framework\TestCase;

/**
 * A parsed quiz document turned into a library quiz - shared by the single-quiz confirmation, the
 * batch confirmation and the Claude connector, so the three cannot drift on what a freshly imported
 * quiz looks like.
 */
class QuizTemplateImportWriterTest extends TestCase
{
    private User $teacher;

    private QuizTemplateImportWriter $writer;

    protected function setUp(): void
    {
        $this->teacher = new User('prof-001');
        $this->writer = new QuizTemplateImportWriter();
    }

    public function testANewQuizCarriesItsIdentityItsAuthorAndItsFolder(): void
    {
        $folder = new QuizFolder($this->teacher, 'Réseaux');

        $quiz = $this->writer->newTemplate($this->teacher, $folder, 'VLAN', 'Réseaux', 'Les bases', 12);

        self::assertSame($this->teacher, $quiz->getTeacher());
        self::assertSame($this->teacher, $quiz->getCreatedBy());
        self::assertSame($folder, $quiz->getFolder());
        self::assertSame('VLAN', $quiz->getName());
        self::assertSame('Réseaux', $quiz->getSubject());
        self::assertSame('Les bases', $quiz->getDescription());
    }

    public function testTheDefaultDrawNeverExceedsTheBankItWasImportedWith(): void
    {
        // A draw larger than its bank is refused at launch, so a 12-question import proposes 12.
        self::assertSame(12, $this->writer->newTemplate($this->teacher, null, 'VLAN', null, null, 12)->getDefaultQuestionCount());
        // ...and a large bank keeps the ordinary default rather than drawing everything.
        self::assertSame(20, $this->writer->newTemplate($this->teacher, null, 'VLAN', null, null, 80)->getDefaultQuestionCount());
    }

    public function testFillingHandsTheQuestionsToTheImporterThatReadThem(): void
    {
        $quiz = new QuizTemplate($this->teacher);
        $questions = [['type' => 'qcm', 'label' => 'Un VLAN est…']];

        $importer = $this->createMock(InteractiveQuizImporter::class);
        $importer->expects(self::once())->method('appendQuestions')->with($quiz, $questions);

        $this->writer->fill($quiz, $importer, $questions);

        self::assertCount(0, $quiz->getSeanceTemplates());
        self::assertCount(0, $quiz->getSequenceTemplates());
    }

    public function testFillingAttachesTheQuizToTheSeanceItWasMadeFor(): void
    {
        $quiz = new QuizTemplate($this->teacher);
        $seance = new SeanceTemplate(new SequenceTemplate($this->teacher));

        $this->writer->fill($quiz, $this->createStub(InteractiveQuizImporter::class), [], $seance);

        self::assertTrue($quiz->getSeanceTemplates()->contains($seance));
        self::assertCount(0, $quiz->getSequenceTemplates());
    }

    public function testFillingAttachesTheQuizToTheSequenceItWasMadeFor(): void
    {
        $quiz = new QuizTemplate($this->teacher);
        $sequence = new SequenceTemplate($this->teacher);

        $this->writer->fill($quiz, $this->createStub(InteractiveQuizImporter::class), [], $sequence);

        self::assertTrue($quiz->getSequenceTemplates()->contains($sequence));
        self::assertCount(0, $quiz->getSeanceTemplates());
    }
}
