<?php

declare(strict_types=1);

namespace App\Service\Kahoot;

/**
 * A Kahoot game report read back into a quiz: the « moncampus-quiz/1 » document it becomes, and what
 * the reading has to say about it. A report is a record of a game that was played, not a quiz
 * definition - which is why it carries no images, and why a slider's margin has to be guessed.
 *
 * Nothing about the players crosses: their pseudonyms, answers and scores are read only to guess a
 * margin, and never kept.
 */
final readonly class KahootReport
{
    /**
     * @param array<string, mixed> $document the « moncampus-quiz/1 » document
     * @param list<KahootNote>     $notes
     * @param array<string, int>   $typeCounts question type value => how many
     */
    public function __construct(
        public string $title,
        public ?string $playedOn,
        public array $document,
        public array $notes,
        public array $typeCounts,
    ) {
    }

    public function questionCount(): int
    {
        return array_sum($this->typeCounts);
    }

    /**
     * What makes two reports the same quiz: its name and its statements, in order. A game played
     * twice exports twice, and importing both would make a duplicate.
     */
    public function fingerprint(): string
    {
        $questions = \is_array($this->document['questions'] ?? null) ? $this->document['questions'] : [];
        $labels = array_map(static fn (mixed $question): string => \is_array($question) && \is_string($question['label'] ?? null) ? $question['label'] : '', $questions);

        return hash('sha256', mb_strtolower($this->title)."\n".implode("\n", $labels));
    }
}
