<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Service\FileUploadService;
use App\Service\QuizQuestionPayload;
use PHPUnit\Framework\TestCase;

/**
 * The contract the mobile app already reads, pinned.
 *
 * This builder was lifted out of App\Controller\Api\QuizController so the interactive video could
 * describe the same twelve types to the app. The app in the field consumes the quiz shape today, so
 * what matters here is not that the payload is *good* but that it is *unchanged* - and, above all,
 * that it still leaks none of the answers.
 */
class QuizQuestionPayloadTest extends TestCase
{
    public function testAPlainQuestionCarriesItsStatementAndItsAnswers(): void
    {
        $question = $this->question(QuestionType::Qcm);
        $question->setLabel('Quelle clé chiffre un message destiné à Alice ?');

        $payload = $this->payload()->build(
            $question,
            [$this->answer(1, 'La clé publique d’Alice'), $this->answer(2, 'La clé privée d’Alice')],
            [], [], [], [], [], false,
        );

        self::assertSame('qcm', $payload['type']);
        self::assertSame('Quelle clé chiffre un message destiné à Alice ?', $payload['label']);
        self::assertSame([
            ['id' => 1, 'label' => 'La clé publique d’Alice'],
            ['id' => 2, 'label' => 'La clé privée d’Alice'],
        ], $payload['answers']);
    }

    /** The order it is handed is the order it ships: who shuffled, and how, is the caller's business. */
    public function testTheAnswerOrderIsTheCallersAndIsNotTouched(): void
    {
        $payload = $this->payload()->build(
            $this->question(QuestionType::Qcm),
            [$this->answer(7, 'sept'), $this->answer(3, 'trois'), $this->answer(5, 'cinq')],
            [], [], [], [], [], false,
        );

        self::assertSame([7, 3, 5], array_column($payload['answers'], 'id'));
    }

    /** A hint exists in entraînement only - the caller says which. */
    public function testZoneHintsOnlyTravelWhenTheCallerAllowsThem(): void
    {
        $question = $this->question(QuestionType::Zone);
        $question->setZoneConfig(['content' => '[[a|Un]] [[b|Deux]]', 'correct' => ['a'], 'hint' => ['a']]);

        self::assertSame([], $this->payload()->build($question, [], [], [], [], [], [], false)['zoneHintIds']);
        self::assertSame(['a'], $this->payload()->build($question, [], [], [], [], [], [], true)['zoneHintIds']);
    }

    /**
     * The one rule this whole object exists to keep: nothing in here is an answer. A regression on
     * any of these keys hands the student the correction with the question.
     */
    public function testNoKeyOfThePayloadCarriesAnAnswer(): void
    {
        $question = $this->question(QuestionType::Apparier);
        $question->setMatchingConfig([
            'headers' => ['left' => 'Commande', 'right' => 'Effet'],
            'pairs' => [['id' => 'p1', 'left' => 'switchport mode access', 'right' => 'Un seul VLAN']],
        ]);

        $payload = $this->payload()->build(
            $question, [], [], [],
            [['id' => 'p1', 'left' => 'switchport mode access', 'right' => 'Un seul VLAN', 'leftImage' => null, 'rightImage' => null]],
            [['key' => 'c1', 'text' => 'Un seul VLAN', 'image' => null]],
            [], false,
        );

        self::assertSame([['id' => 'p1', 'left' => 'switchport mode access', 'leftImageUrl' => null]], $payload['matchingPairs']);
        self::assertArrayNotHasKey('right', $payload['matchingPairs'][0]);
        self::assertArrayNotHasKey('correct', $payload);
        self::assertArrayNotHasKey('numericAnswer', $payload);
        self::assertArrayNotHasKey('numericFormula', $payload);
        self::assertArrayNotHasKey('blankAnswers', $payload);
        self::assertArrayNotHasKey('zoneCorrectIds', $payload);
    }

    /**
     * The statement reaches the app already rendered with this student's own values, formatted the
     * French way - a comma and a thin space, because it is read by a French classroom and the number
     * on screen is the one the student writes down.
     */
    public function testACalculatedStatementIsRenderedWithTheStudentsOwnValues(): void
    {
        $question = $this->question(QuestionType::Calculee);
        $question->setLabel('Un disque de {taille} Go se remplit de {debit} Mo/s.');
        $question->setNumericConfig([
            'variables' => [
                ['name' => 'taille', 'min' => 1000.0, 'max' => 2000.0, 'decimals' => 0],
                ['name' => 'debit', 'min' => 1.0, 'max' => 9.0, 'decimals' => 2],
            ],
            'formula' => 'taille / debit',
        ]);

        $payload = $this->payload()->build($question, [], [], [], [], [], ['taille' => 1500.0, 'debit' => 7.25], false);

        self::assertSame('Un disque de 1 500 Go se remplit de 7,25 Mo/s.', $payload['numericStatement']);
    }

    private function payload(): QuizQuestionPayload
    {
        $uploads = $this->createStub(FileUploadService::class);
        $uploads->method('url')->willReturnCallback(static fn (string $key): string => 'https://files.example/'.$key);

        return new QuizQuestionPayload($uploads);
    }

    private function question(QuestionType $type): QuizQuestion
    {
        $question = new QuizQuestion(new QuizTemplate(new User('prof')));
        $question->setType($type);

        return $question;
    }

    private function answer(int $id, string $label): QuizAnswer
    {
        $answer = $this->createStub(QuizAnswer::class);
        $answer->method('getId')->willReturn($id);
        $answer->method('getLabel')->willReturn($label);

        return $answer;
    }
}
