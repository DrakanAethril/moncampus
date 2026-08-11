<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizTemplate;

/**
 * One family of the "Import interactif (JSON)" screen: a paste-a-document format designed to be
 * produced by a language model from the copyable prompt shown alongside, plus the ready-made
 * examples that double as worked specimens of it.
 *
 * Extracted when the third family arrived. The controller carried an if/else on the family in five
 * places (choose the example, choose the parser, choose the appender, choose the preview builder,
 * choose the redirect); a third branch in each is exactly the shape that goes wrong when a fourth
 * one is added and one of the five is missed.
 *
 * Implementations stay independent - each owns its own format, its own validation tiers and its own
 * preview rendering. This only says what they all have to answer.
 */
interface InteractiveQuizImporter
{
    /** The `?family=` value, and the tab that selects this importer's prompt and examples. */
    public function family(): string;

    /** The format tag the document announces, e.g. "moncampus-numerique/1". */
    public function formatTag(): string;

    /**
     * The tag stamped on the session payload, which the preview reads back to know which importer
     * produced it. Kept separate from family(): one is a URL parameter, the other is stored data,
     * and coupling them would make renaming a tab a data migration.
     */
    public function payloadFormat(): string;

    /** @return array<string, string> example key => label translation key */
    public function exampleLabels(): array;

    public function exampleJson(string $key): ?string;

    /**
     * @return array{format: string, name: string, subject: ?string, description: ?string, fileName: string, questions: list<array<string, mixed>>, errors: list<string>}
     *
     * @throws QuizCsvImportException when the document as a whole is unusable
     */
    public function parse(string $json, string $fileName = 'import.json'): array;

    /**
     * @param array<array-key, mixed> $questions the payload's questions, back out of the session
     * @param bool                    $copyImages false for the preview, which builds transient
     *                                            entities and must leave no uploads behind
     */
    public function appendQuestions(QuizTemplate $template, array $questions, bool $copyImages = true): void;

    /** @return array<string, mixed> a document of this format, for sharing between teachers */
    public function export(QuizTemplate $template): array;
}
