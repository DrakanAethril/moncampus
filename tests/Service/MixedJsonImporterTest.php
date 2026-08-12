<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Service\FileUploadService;
use App\Service\MatchingImageStore;
use App\Service\MatchingJsonImporter;
use App\Service\MixedJsonImporter;
use App\Service\NumericJsonImporter;
use App\Service\QuizCsvImporter;
use App\Service\QuizCsvImportException;
use App\Service\QuizImportImages;
use App\Service\ShortAnswerJsonImporter;
use App\Service\SimpleJsonImporter;
use App\Service\ZoneJsonImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-quiz/1" format - one document for the twelve types, which is what lets a single
 * prompt ask a model to pick the form each notion deserves.
 *
 * It reads nothing itself: it hands each question to the reader that owns its type and keeps the
 * document's own order. That order is not cosmetic - the prompt asks for variety ("jamais plus de
 * deux questions consécutives du même type"), and grouping the questions by family on the way in
 * would quietly undo it.
 */
class MixedJsonImporterTest extends TestCase
{
    private MixedJsonImporter $importer;

    protected function setUp(): void
    {
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return match ($id) {
                    'answerTrueLabel' => 'Vrai',
                    'answerFalseLabel' => 'Faux',
                    default => $id.(isset($parameters['%number%']) ? ' #'.$parameters['%number%'] : ''),
                };
            }

            public function getLocale(): string
            {
                return 'fr';
            }
        };

        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        $uploads = $this->createStub(FileUploadService::class);
        $images = new QuizImportImages($requestStack, $uploads);

        $this->importer = new MixedJsonImporter(
            $translator,
            new SimpleJsonImporter($translator, new QuizCsvImporter($translator), $images),
            new ZoneJsonImporter($translator, $uploads),
            new MatchingJsonImporter($translator, new MatchingImageStore($uploads)),
            new NumericJsonImporter($translator),
            new ShortAnswerJsonImporter($translator),
        );
    }

    public function testItReadsOneDocumentCoveringFourFamilies(): void
    {
        $payload = $this->importer->parse($this->document([
            ['type' => 'qcm', 'label' => 'À quoi sert un VLAN ?', 'answers' => ['Segmenter', 'Router'], 'correct' => [1]],
            ['type' => 'apparier', 'label' => 'Reliez chaque commande à son effet', 'pairs' => [
                ['id' => 'p1', 'left' => 'show vlan', 'right' => 'Liste les VLAN'],
                ['id' => 'p2', 'left' => 'show ip route', 'right' => 'Liste les routes'],
            ]],
            ['type' => 'reponse_courte', 'label' => 'Quel protocole d’étiquetage ?', 'answers' => ['802.1Q']],
            ['type' => 'zone', 'label' => 'Cliquez sur le VLAN natif', 'support' => [
                'kind' => 'code', 'content' => 'switchport trunk native vlan [[z1|99]]',
            ], 'correct' => ['z1']],
            ['type' => 'numerique', 'label' => 'Combien de VLAN au maximum ?', 'answer' => 4094],
        ]));

        self::assertSame([], $payload['errors']);
        self::assertCount(5, $payload['questions']);
        self::assertSame('mixed', $payload['format']);
        self::assertSame(
            ['qcm', 'apparier', 'reponse_courte', 'zone', 'numerique'],
            array_column($payload['questions'], 'type'),
            'the document order is the teacher’s order, not a grouping by family',
        );
    }

    /** The order survives all the way into the bank: it is what the prompt asked the model to vary. */
    public function testTheBankKeepsTheDocumentOrder(): void
    {
        $payload = $this->importer->parse($this->document([
            ['type' => 'qcm', 'label' => 'Q1', 'answers' => ['A', 'B'], 'correct' => [1]],
            ['type' => 'reponse_courte', 'label' => 'Q2', 'answers' => ['x']],
            ['type' => 'qcm', 'label' => 'Q3', 'answers' => ['A', 'B'], 'correct' => [2]],
        ]));

        $template = new QuizTemplate(new User('teacher'));
        $this->importer->appendQuestions($template, $payload['questions']);

        $questions = $template->getQuestions()->toArray();
        self::assertSame(['Q1', 'Q2', 'Q3'], array_map(static fn ($q) => $q->getLabel(), $questions));
        self::assertSame([QuestionType::Qcm, QuestionType::ReponseCourte, QuestionType::Qcm], array_map(static fn ($q) => $q->getType(), $questions));
        self::assertSame([1, 2, 3], array_map(static fn ($q) => $q->getOrderIndex(), $questions));
    }

    /** Appending to a quiz that already holds questions continues its numbering, never restarts it. */
    public function testItAppendsAfterAnExistingBank(): void
    {
        $template = new QuizTemplate(new User('teacher'));
        $first = $this->importer->parse($this->document([
            ['type' => 'qcm', 'label' => 'Q1', 'answers' => ['A', 'B'], 'correct' => [1]],
        ]));
        $this->importer->appendQuestions($template, $first['questions']);
        $this->importer->appendQuestions($template, $first['questions']);

        self::assertSame([1, 2], array_map(static fn ($q) => $q->getOrderIndex(), $template->getQuestions()->toArray()));
    }

    /**
     * A rejected question is named by its place in the document the teacher is looking at - not by
     * its place among the questions of its own family, which nobody can count.
     */
    public function testAnErrorNamesTheQuestionsPlaceInTheWholeDocument(): void
    {
        $payload = $this->importer->parse($this->document([
            ['type' => 'qcm', 'label' => 'Correcte', 'answers' => ['A', 'B'], 'correct' => [1]],
            ['type' => 'qcm', 'label' => 'Correcte aussi', 'answers' => ['A', 'B'], 'correct' => [2]],
            ['type' => 'reponse_courte', 'label' => 'Sans variantes'],
            ['type' => 'balbutiement', 'label' => 'Type inconnu'],
        ]));

        self::assertCount(2, $payload['questions']);
        self::assertCount(2, $payload['errors']);
        self::assertStringContainsString('#3', $payload['errors'][0]);
        self::assertStringContainsString('#4', $payload['errors'][1]);
    }

    public function testTheEnvelopeIsChecked(): void
    {
        $this->expectException(QuizCsvImportException::class);
        $this->importer->parse('{"format":"moncampus-zones/1","questions":[{"type":"qcm"}]}');
    }

    public function testAnEmptyDocumentIsRefused(): void
    {
        $this->expectException(QuizCsvImportException::class);
        $this->importer->parse('{"format":"moncampus-quiz/1","questions":[]}');
    }

    /**
     * The export is the exact inverse: one file for a heterogeneous quiz, which four per-family
     * downloads could not produce.
     */
    public function testExportRoundTripsAHeterogeneousBank(): void
    {
        $payload = $this->importer->parse($this->document([
            ['type' => 'qcm', 'label' => 'Q1', 'answers' => ['A', 'B'], 'correct' => [2]],
            ['type' => 'reponse_courte', 'label' => 'Q2', 'answers' => ['mot']],
            ['type' => 'vrai_faux', 'label' => 'Q3', 'correct' => [2]],
        ]));

        $template = new QuizTemplate(new User('teacher'));
        $template->setName('Réseaux');
        $this->importer->appendQuestions($template, $payload['questions']);

        $document = $this->importer->export($template);
        self::assertSame('moncampus-quiz/1', $document['format']);
        self::assertSame(['qcm', 'reponse_courte', 'vrai_faux'], array_column($document['questions'], 'type'));

        // And back in again, unchanged.
        $again = $this->importer->parse((string) json_encode($document));
        self::assertSame([], $again['errors']);
        self::assertSame(['Q1', 'Q2', 'Q3'], array_column($again['questions'], 'label'));
    }

    public function testItAnswersForEveryType(): void
    {
        foreach (QuestionType::cases() as $type) {
            self::assertTrue($this->importer->handles($type), $type->value.' must have a reader in the mixed format');
        }
    }

    /** The ready-made documents double as worked specimens of the format: they must actually read. */
    public function testEveryReadyMadeExampleIsAValidDocument(): void
    {
        foreach (array_keys($this->importer->exampleLabels()) as $key) {
            $json = $this->importer->exampleJson($key);
            self::assertIsString($json, $key);

            $payload = $this->importer->parse($json);
            self::assertSame([], $payload['errors'], $key);
            self::assertNotEmpty($payload['questions'], $key);
        }
    }

    /** @param list<array<string, mixed>> $questions */
    private function document(array $questions): string
    {
        return (string) json_encode([
            'format' => 'moncampus-quiz/1',
            'template' => ['name' => 'Réseaux', 'subject' => 'SISR'],
            'questions' => $questions,
        ]);
    }
}
