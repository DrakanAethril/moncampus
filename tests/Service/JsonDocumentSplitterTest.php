<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\JsonDocumentSplitter;
use PHPUnit\Framework\TestCase;

/**
 * Cutting a paste box into the documents it holds.
 *
 * A teacher who asks a model for several quizzes gets several documents in one answer, and pastes
 * the lot. What arrives is never a JSON array of documents: it is objects one under another, almost
 * always wrapped in ```json fences, usually with a sentence of the model's own prose between them.
 * The splitter's whole job is to find the top-level objects in that and hand them over in order.
 */
class JsonDocumentSplitterTest extends TestCase
{
    private JsonDocumentSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new JsonDocumentSplitter();
    }

    public function testASingleDocumentComesBackWhole(): void
    {
        $json = '{"format":"moncampus-quiz/1","questions":[]}';

        self::assertSame([$json], $this->splitter->split($json));
    }

    public function testItSplitsTwoDocumentsPastedOneUnderTheOther(): void
    {
        $documents = $this->splitter->split(<<<'JSON'
            {"format":"moncampus-quiz/1","template":{"name":"Un"},"questions":[]}
            {"format":"moncampus-quiz/1","template":{"name":"Deux"},"questions":[]}
            JSON);

        self::assertCount(2, $documents);
        self::assertStringContainsString('"Un"', $documents[0]);
        self::assertStringContainsString('"Deux"', $documents[1]);
    }

    public function testNestedBracesDoNotEndADocument(): void
    {
        $documents = $this->splitter->split('{"a":{"b":{"c":1}},"d":2} {"e":{"f":3}}');

        self::assertSame(['{"a":{"b":{"c":1}},"d":2}', '{"e":{"f":3}}'], $documents);
    }

    public function testABraceInsideAStringIsNotStructure(): void
    {
        $documents = $this->splitter->split('{"enonce":"Que fait } dans une regex ?"} {"b":1}');

        self::assertCount(2, $documents);
        self::assertStringContainsString('regex', $documents[0]);
    }

    public function testAnEscapedQuoteDoesNotCloseTheString(): void
    {
        $documents = $this->splitter->split('{"enonce":"il a dit \"}\" puis"} {"b":1}');

        self::assertCount(2, $documents);
        self::assertSame('{"b":1}', $documents[1]);
    }

    public function testMarkdownFencesAndProseBetweenDocumentsAreIgnored(): void
    {
        $documents = $this->splitter->split(<<<'TEXT'
            Voici les deux quiz demandés.

            ```json
            {"format":"moncampus-quiz/1","template":{"name":"Un"},"questions":[]}
            ```

            Et le second :

            ```json
            {"format":"moncampus-quiz/1","template":{"name":"Deux"},"questions":[]}
            ```

            N'hésitez pas si vous voulez d'autres questions.
            TEXT);

        self::assertCount(2, $documents);
        self::assertStringContainsString('"Un"', $documents[0]);
        self::assertStringContainsString('"Deux"', $documents[1]);
    }

    public function testTextWithoutAnyObjectSplitsIntoNothing(): void
    {
        self::assertSame([], $this->splitter->split("Je n'ai pas réussi à générer le quiz."));
        self::assertSame([], $this->splitter->split('   '));
    }

    /**
     * An unterminated document is handed over as it stands rather than dropped: the reader that
     * gets it answers « ce document n'est pas du JSON valide », which names the problem. Dropping
     * it would leave the teacher with a batch silently one quiz short.
     */
    public function testATruncatedLastDocumentIsStillHandedOver(): void
    {
        $documents = $this->splitter->split('{"a":1} {"b":2');

        self::assertSame(['{"a":1}', '{"b":2'], $documents);
    }
}
