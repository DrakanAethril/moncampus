<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizQuestionDefinition;
use App\Enum\QuestionType;
use App\Util\NumericAnswerParser;

/**
 * Grading one answer given inside a video (créas 5B, screen 4).
 *
 * It decides nothing about right and wrong: the verdict is App\Service\QuizAnswerChecker's, the
 * same one the passation and the library's "Tester" tab go through. What this owns is the
 * *boundary* - the overlay posts a JSON body, typed by nobody, and every key of it has to be read
 * back into the shapes the checker expects, bounded by the question's own configuration. A zone id
 * the support does not carry, an association onto a choice that does not exist, an "answers" key
 * holding a string: all of them read as "not answered", never as a free point.
 *
 * Nothing here touches Doctrine, which is why the twelve types are covered by unit tests over
 * primitives (tests/Service/VideoCueGraderTest.php) rather than by a fixture per type.
 */
final class VideoCueGrader
{
    public function __construct(private readonly QuizAnswerChecker $checker)
    {
    }

    /**
     * @param list<array{id: int, correct: bool, orderIndex: int}> $answers  the question's own answer rows, any order
     * @param array<array-key, mixed>                              $submitted the posted body
     * @param array<string, float>                                 $variables Calculée: the values this student was drawn
     */
    public function isCorrect(QuizQuestionDefinition $question, array $answers, array $submitted, array $variables = []): bool
    {
        $number = NumericAnswerParser::parse($this->readString($submitted, 'numeric'));

        return $this->checker->isCorrect(
            $question,
            $answers,
            $this->readSelectedIds($submitted),
            $this->readBlanks($submitted),
            $this->readZoneResponses($question, $submitted),
            $this->readMatchingResponses($question, $submitted),
            $number['value'],
            $number['unit'],
            $variables,
        );
    }

    /**
     * The answer rows of a library question, reduced to what grading needs - the shape
     * QuizAnswerChecker takes, and the only reason one rule can serve both question entities.
     *
     * @return list<array{id: int, correct: bool, orderIndex: int}>
     */
    public function answerRows(QuizQuestion $question): array
    {
        if (!$question->getType()->usesAnswerRows()) {
            return [];
        }

        return array_values(array_map(
            static fn (QuizAnswer $answer): array => [
                'id' => (int) $answer->getId(),
                'correct' => $answer->isCorrect(),
                'orderIndex' => $answer->getOrderIndex(),
            ],
            $question->getAnswers()->toArray(),
        ));
    }

    /**
     * The values a calculée asks *this* student for, at *this* marker.
     *
     * Nothing stores them, unlike a quiz attempt's draw (App\Service\QuizDrawService seeds its own
     * on the attempt row): a video cue has no attempt to hang them on, and reloading the page
     * mid-answer must ask the same question rather than a new one. So they are a pure function of
     * the student and the marker - deterministic, and different from the neighbour's, which is the
     * whole point of the type.
     *
     * @return array<string, float>
     */
    public function variablesFor(QuizQuestionDefinition $question, int $studentId, int $cuePointId): array
    {
        $values = [];

        foreach ($question->getNumericVariables() as $variable) {
            // How many steps fit in the range, inclusive of both ends - same reading as
            // QuizDrawService::drawNumericVariables(), from which this whole draw is borrowed.
            $steps = (int) floor(($variable['max'] - $variable['min']) / $variable['step'] + 1e-9);
            $pick = $steps > 0 ? $this->index($studentId, $cuePointId, $variable['name'], $steps + 1) : 0;
            $value = $variable['min'] + $pick * $variable['step'];

            $values[$variable['name']] = round(min($value, $variable['max']), $variable['decimals']);
        }

        return $values;
    }

    /** A stable pseudo-random integer in [0, $count), the same md5 trick QuizDrawService uses. */
    private function index(int $studentId, int $cuePointId, string $name, int $count): int
    {
        $hash = (int) hexdec(substr(md5(\sprintf('cue-%d-%d-%s', $cuePointId, $studentId, $name)), 0, 8));

        return $count > 0 ? $hash % $count : 0;
    }

    /**
     * @param array<array-key, mixed> $submitted
     *
     * @return list<int> in submission order, which is the answer itself for an "ordre"
     */
    private function readSelectedIds(array $submitted): array
    {
        $raw = $submitted['answers'] ?? [];
        if (!\is_array($raw)) {
            return [];
        }

        // Cast rather than filtered on is_int: JSON.stringify of a checkbox value gives "12".
        return array_values(array_map(intval(...), array_filter($raw, is_scalar(...))));
    }

    /**
     * @param array<array-key, mixed> $submitted
     *
     * @return list<string> in text order
     */
    private function readBlanks(array $submitted): array
    {
        $raw = $submitted['blanks'] ?? [];
        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_map(strval(...), array_filter($raw, is_scalar(...))));
    }

    /**
     * Zone: the clicked ids, kept to the support's own zones. Légende: zone id => placed label,
     * kept to the question's own zones and choices.
     *
     * @param array<array-key, mixed> $submitted
     *
     * @return array<array-key, string>
     */
    private function readZoneResponses(QuizQuestionDefinition $question, array $submitted): array
    {
        if (QuestionType::Zone === $question->getType()) {
            $raw = $submitted['zones'] ?? [];
            $clicked = array_map(strval(...), array_filter(\is_array($raw) ? $raw : [], is_scalar(...)));

            return array_values(array_unique(array_intersect($clicked, $question->getZoneIds())));
        }

        if (QuestionType::Legende !== $question->getType()) {
            return [];
        }

        $raw = $submitted['placements'] ?? [];
        $choiceKeys = array_column($question->getLegendeChoices(), 'key');
        $zoneIds = $question->getZoneIds();
        $placements = [];

        foreach (\is_array($raw) ? $raw : [] as $zoneId => $key) {
            if (\is_scalar($key) && \in_array((string) $zoneId, $zoneIds, true) && \in_array((string) $key, $choiceKeys, true)) {
                $placements[(string) $zoneId] = (string) $key;
            }
        }

        return $placements;
    }

    /**
     * @param array<array-key, mixed> $submitted
     *
     * @return array<string, string> pair id => picked choice key
     */
    private function readMatchingResponses(QuizQuestionDefinition $question, array $submitted): array
    {
        if (QuestionType::Apparier !== $question->getType()) {
            return [];
        }

        $raw = $submitted['pairs'] ?? [];
        $choiceKeys = array_column($question->getMatchingChoices(), 'key');
        $pairIds = $question->getMatchingPairIds();
        $associations = [];

        foreach (\is_array($raw) ? $raw : [] as $pairId => $key) {
            if (\is_scalar($key) && \in_array((string) $pairId, $pairIds, true) && \in_array((string) $key, $choiceKeys, true)) {
                $associations[(string) $pairId] = (string) $key;
            }
        }

        return $associations;
    }

    /** @param array<array-key, mixed> $submitted */
    private function readString(array $submitted, string $key): string
    {
        $value = $submitted[$key] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }
}
