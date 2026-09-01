<?php

declare(strict_types=1);

namespace App\Tests\Service\Survey;

use App\Entity\SurveyQuestion;
use App\Entity\SurveyTemplate;
use App\Enum\SurveyQuestionType;
use App\Service\Survey\SurveyImportException;
use App\Service\Survey\SurveyJsonImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The « moncampus-sondage/1 » reader: what it accepts from a model that was not careful, and what it
 * refuses rather than repairs.
 *
 * The line it draws is the one the screens depend on: a bad *question* is reported and skipped, so
 * one clumsy line never costs the author the eleven others, while a document that is unusable as a
 * whole raises. Both halves are tested here because both are what the verification screen shows.
 */
class SurveyJsonImporterTest extends TestCase
{
    private SurveyJsonImporter $importer;

    protected function setUp(): void
    {
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id.(isset($parameters['%number%']) ? ' #'.$parameters['%number%'] : '');
            }

            public function getLocale(): string
            {
                return 'fr';
            }
        };

        $this->importer = new SurveyJsonImporter($translator);
    }

    public function testReadsTheFiveTypesOfAWholeDocument(): void
    {
        $payload = $this->importer->parse($this->importer->exampleJson(), 'exemple.json');

        self::assertSame('sondage', $payload['format']);
        self::assertSame('Satisfaction — 1er semestre', $payload['name']);
        self::assertSame('BTS SIO 2', $payload['subject']);
        self::assertSame('exemple.json', $payload['fileName']);
        self::assertSame([], $payload['errors']);
        self::assertSame(
            ['titre', 'unique', 'multiple', 'titre', 'ordre', 'commentaire'],
            array_column($payload['questions'], 'type'),
        );
    }

    /**
     * A model that answers in a chat window fences its JSON far more often than not, and says a
     * sentence before it. Refusing that would send the author back into the conversation for
     * nothing.
     */
    public function testReadsThroughACodeFenceAndSurroundingProse(): void
    {
        $document = "Voici votre questionnaire :\n\n```json\n".$this->minimal()."\n```\n\nBon sondage !";

        $payload = $this->importer->parse($document);

        self::assertCount(1, $payload['questions']);
    }

    /** The smallest document the reader accepts - one question, of the type that needs no answers. */
    private function minimal(): string
    {
        return '{"format":"moncampus-sondage/1","questions":[{"type":"commentaire","label":"Quelque chose à ajouter ?"}]}';
    }

    public function testRefusesADocumentThatDoesNotDeclareTheFormat(): void
    {
        $this->expectException(SurveyImportException::class);

        $this->importer->parse('{"format":"moncampus-quiz/1","questions":[]}');
    }

    public function testRefusesTextThatIsNotJson(): void
    {
        $this->expectException(SurveyImportException::class);

        $this->importer->parse('je vous propose douze questions sur la formation');
    }

    /** Nothing to verify, so the paste screen keeps the author rather than handing over an empty one. */
    public function testRefusesADocumentWhoseEveryQuestionIsUnusable(): void
    {
        $this->expectException(SurveyImportException::class);

        $this->importer->parse('{"format":"moncampus-sondage/1","questions":[{"type":"martien","label":"…"},{"type":"unique","label":""}]}');
    }

    /** One bad question is reported and skipped - never fatal for the ones around it. */
    public function testSkipsTheBadQuestionAndKeepsTheOthers(): void
    {
        $payload = $this->importer->parse(<<<'JSON'
            {"format":"moncampus-sondage/1","questions":[
              {"type":"unique","label":"Le rythme vous convient-il ?","answers":["Oui","Non"]},
              {"type":"unique","label":"Une seule proposition","answers":["Oui"]},
              {"type":"commentaire","label":"Quelque chose à ajouter ?"}
            ]}
            JSON);

        self::assertCount(2, $payload['questions']);
        self::assertSame(['surveyImportQuestionMissingAnswersError #2'], $payload['errors']);
    }

    /**
     * There is deliberately no Échelle type - an ordered scale is a Unique question carrying
     * `is_scale` - so a document that names one must not lose every scale question it wrote.
     */
    public function testReadsEchelleAsAUniqueQuestionCarryingTheScaleFlag(): void
    {
        $payload = $this->importer->parse('{"format":"moncampus-sondage/1","questions":[{"type":"echelle","label":"Le rythme ?","answers":["Trop lent","Adapté","Trop rapide"]}]}');

        self::assertSame('unique', $payload['questions'][0]['type']);
        self::assertTrue($payload['questions'][0]['scale']);
    }

    /** A bound past the number of propositions could never be met: the answer screen would refuse every submission. */
    public function testReadsChoiceBoundsDownToWhatTheQuestionOffers(): void
    {
        $payload = $this->importer->parse('{"format":"moncampus-sondage/1","questions":[{"type":"multiple","label":"Lesquelles ?","answers":["A","B","C"],"minChoices":0,"maxChoices":9}]}');

        self::assertNull($payload['questions'][0]['minChoices']);
        self::assertSame(3, $payload['questions'][0]['maxChoices']);
    }

    /** An intertitle expects nothing, so « obligatoire » has no meaning on it whatever the document says. */
    public function testATitleIsNeverRequiredAndCarriesNoAnswer(): void
    {
        $payload = $this->importer->parse('{"format":"moncampus-sondage/1","questions":[{"type":"titre","label":"Les cours","required":true,"answers":["A","B"]}]}');

        self::assertFalse($payload['questions'][0]['required']);
        self::assertSame([], $payload['questions'][0]['answers']);
    }

    public function testAppendsQuestionsAfterWhatTheModelAlreadyHolds(): void
    {
        $template = new SurveyTemplate();
        $existing = new SurveyQuestion($template);
        $existing->setLabel('Déjà là');
        $existing->setOrderIndex(4);
        $template->addQuestion($existing);

        $payload = $this->importer->parse($this->importer->exampleJson());
        $this->importer->appendQuestions($template, $payload['questions']);

        $questions = $template->getQuestions()->toArray();
        self::assertCount(7, $questions);
        self::assertSame([4, 5, 6, 7, 8, 9, 10], array_map(static fn (SurveyQuestion $q): int => $q->getOrderIndex(), $questions));

        $scale = $questions[2];
        self::assertSame(SurveyQuestionType::Unique, $scale->getType());
        self::assertTrue($scale->isScale());
        // The order of the propositions IS the scale's value, the low pole first.
        self::assertSame(
            ['Beaucoup trop lent', 'Un peu lent', 'Adapté', 'Un peu rapide', 'Beaucoup trop rapide'],
            array_map(static fn ($answer): string => $answer->getLabel(), $scale->getAnswers()->toArray()),
        );
    }
}
