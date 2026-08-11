<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Service\FileUploadService;
use App\Service\QuizCsvImportException;
use App\Service\ZoneJsonImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-zones/1" import format - the paste-a-JSON way into zone/legende questions, built
 * to be produced by a language model from the copyable prompt on the import screen. Same contract
 * as QuizCsvImporter: parse() never touches Doctrine, a question the reader cannot use is skipped
 * and reported rather than fatal, and only appendQuestions() builds entities after the teacher
 * confirmed the preview.
 */
class ZoneJsonImporterTest extends TestCase
{
    private ZoneJsonImporter $importer;

    protected function setUp(): void
    {
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                // The key itself, plus the question number when given - enough to assert on.
                return $id.(isset($parameters['%number%']) ? ' #'.$parameters['%number%'] : '');
            }

            public function getLocale(): string
            {
                return 'fr';
            }
        };

        $this->importer = new ZoneJsonImporter($translator, $this->createStub(FileUploadService::class));
    }

    public function testParseAcceptsAWellFormedFile(): void
    {
        $payload = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-zones/1',
            'template' => ['name' => 'HTML — structure', 'subject' => 'Web'],
            'questions' => [
                [
                    'type' => 'zone',
                    'label' => 'Cliquez la fermeture de <nav>.',
                    'difficulty' => 'moyen',
                    'support' => ['kind' => 'code', 'language' => 'html', 'content' => '[[z1|<nav>]]…[[z2|</nav>]]'],
                    'correct' => ['z2'],
                    'hint' => ['z1', 'z2'],
                    'feedback' => ['z1' => 'Ouvrante.', '*' => 'Autre élément.'],
                    'explanation' => 'Même niveau d’indentation.',
                ],
                [
                    'type' => 'legende',
                    'label' => 'Placez les étiquettes.',
                    'points' => 2,
                    'support' => ['kind' => 'texte', 'content' => 'Le [[s|chat]] [[v|mange]].'],
                    'labels' => ['s' => 'Sujet', 'v' => 'Verbe'],
                    'distractors' => ['Complément'],
                ],
            ],
        ]), 'exemple.json');

        self::assertSame('HTML — structure', $payload['name']);
        self::assertSame('Web', $payload['subject']);
        self::assertSame('exemple.json', $payload['fileName']);
        self::assertSame([], $payload['errors']);
        self::assertCount(2, $payload['questions']);

        $first = $payload['questions'][0];
        self::assertSame('zone', $first['type']);
        self::assertSame('moyen', $first['difficulty']);
        self::assertSame(1.0, $first['points']);
        self::assertSame('code', $first['zoneConfig']['kind']);
        self::assertSame(['z2'], $first['zoneConfig']['correct']);
        self::assertSame(['z1', 'z2'], $first['zoneConfig']['hint']);

        $second = $payload['questions'][1];
        self::assertSame('legende', $second['type']);
        self::assertSame(2.0, $second['points']);
        self::assertSame(['s' => 'Sujet', 'v' => 'Verbe'], $second['zoneConfig']['labels']);
        self::assertSame(['Complément'], $second['zoneConfig']['distractors']);
    }

    public function testParseRefusesAnythingButTheZonesFormat(): void
    {
        $this->expectException(QuizCsvImportException::class);

        $this->importer->parse('{"format": "somebody-elses/2", "questions": []}');
    }

    public function testParseRefusesUnreadableJson(): void
    {
        $this->expectException(QuizCsvImportException::class);

        $this->importer->parse('{oops');
    }

    public function testAQuestionWithAnUnknownCorrectIdIsSkippedAndReported(): void
    {
        $payload = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-zones/1',
            'template' => ['name' => 'Essai'],
            'questions' => [
                [
                    'type' => 'zone',
                    'label' => 'Bad one.',
                    'support' => ['kind' => 'code', 'content' => '[[z1|x]]'],
                    'correct' => ['z9'],
                ],
                [
                    'type' => 'zone',
                    'label' => 'Good one.',
                    'support' => ['kind' => 'texte', 'content' => '[[a|x]] ou [[b|y]]'],
                    'correct' => ['b'],
                ],
            ],
        ]));

        self::assertCount(1, $payload['questions']);
        self::assertSame('Good one.', $payload['questions'][0]['label']);
        self::assertCount(1, $payload['errors']);
        self::assertStringContainsString('#1', $payload['errors'][0], 'the report names the failing question');
    }

    public function testMarkersCanBeOverriddenPerQuestion(): void
    {
        $payload = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-zones/1',
            'template' => ['name' => 'JS'],
            'questions' => [[
                'type' => 'zone',
                'label' => 'Cliquez le tableau.',
                'support' => ['kind' => 'code', 'language' => 'js', 'content' => 'const m = ⟦z1|[[1], [2]]⟧;', 'markers' => ['open' => '⟦', 'close' => '⟧']],
                'correct' => ['z1'],
            ]],
        ]));

        self::assertSame([], $payload['errors']);
        self::assertSame(['open' => '⟦', 'close' => '⟧'], $payload['questions'][0]['zoneConfig']['markers']);
    }

    public function testLegendeNeedsAtLeastOneKnownLabel(): void
    {
        $payload = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-zones/1',
            'template' => ['name' => 'Essai'],
            'questions' => [[
                'type' => 'legende',
                'label' => 'Placez.',
                'support' => ['kind' => 'texte', 'content' => '[[a|x]]'],
                'labels' => ['z9' => 'Inconnu'],
            ]],
        ]));

        self::assertCount(0, $payload['questions']);
        self::assertCount(1, $payload['errors']);
    }

    public function testUnknownHintAndFeedbackIdsAreDroppedSilently(): void
    {
        // Wrong ids in the *answer* kill the question; wrong ids in decoration only lose the
        // decoration - a model that hallucinated one hint id must not cost the whole question.
        $payload = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-zones/1',
            'template' => ['name' => 'Essai'],
            'questions' => [[
                'type' => 'zone',
                'label' => 'Cliquez.',
                'support' => ['kind' => 'texte', 'content' => '[[a|x]] ou [[b|y]]'],
                'correct' => ['a'],
                'hint' => ['a', 'z9'],
                'feedback' => ['b' => 'Non.', 'z9' => 'Fantôme.', '*' => 'Ailleurs.'],
            ]],
        ]));

        self::assertSame([], $payload['errors']);
        self::assertSame(['a'], $payload['questions'][0]['zoneConfig']['hint']);
        self::assertSame(['b' => 'Non.', '*' => 'Ailleurs.'], $payload['questions'][0]['zoneConfig']['feedback']);
    }

    public function testAppendQuestionsBuildsTheEntitiesAfterConfirmation(): void
    {
        $payload = $this->importer->parse((string) json_encode([
            'format' => 'moncampus-zones/1',
            'template' => ['name' => 'HTML'],
            'questions' => [[
                'type' => 'zone',
                'label' => 'Cliquez la fermeture.',
                'difficulty' => 'facile',
                'points' => 2,
                'support' => ['kind' => 'code', 'language' => 'html', 'content' => '[[z1|<p>]][[z2|</p>]]'],
                'correct' => ['z2'],
            ]],
        ]));

        $template = new QuizTemplate(new User('teacher'));
        $this->importer->appendQuestions($template, $payload['questions']);

        self::assertCount(1, $template->getQuestions());
        $question = $template->getQuestions()->first();
        self::assertSame(QuestionType::Zone, $question->getType());
        self::assertSame('Cliquez la fermeture.', $question->getLabel());
        self::assertSame(2.0, $question->getPoints());
        self::assertSame(['z2'], $question->getZoneCorrectIds());
        self::assertSame('html', $question->getZoneLanguage());
    }
}
