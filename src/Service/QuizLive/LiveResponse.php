<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

use App\Service\JsonRequestPayload;

/**
 * What a phone sent for one question of a live concours, whatever its type - the propositions
 * tapped (in the order tapped, which is the answer of a remise en ordre), a number, a word. Stored
 * as is on App\Entity\QuizLiveAnswer::$response; checked by isWellFormedFor() before it is graded.
 */
final readonly class LiveResponse
{
    /** A word longer than this is not an answer to a « réponse courte », it is a paste. */
    public const int MAX_TEXT_LENGTH = 200;

    /**
     * @param list<int> $answerIds
     */
    public function __construct(
        public array $answerIds = [],
        public ?float $value = null,
        public ?string $text = null,
    ) {
    }

    /**
     * Read off a request body, web or mobile alike. `answerId` (one) is what the phones sent before
     * the room learnt several propositions and is still understood; `order` is the same list under
     * the name the order screen uses.
     */
    public static function fromPayload(JsonRequestPayload $payload): self
    {
        $ids = $payload->ids('answerIds');
        if ([] === $ids) {
            $ids = $payload->ids('order');
        }
        if ([] === $ids && null !== ($single = $payload->int('answerId'))) {
            $ids = [$single];
        }

        $text = $payload->has('text') ? trim($payload->string('text')) : null;

        return new self($ids, $payload->float('value'), '' === $text ? null : $text);
    }

    /** @param array<string, mixed>|null $stored */
    public static function fromArray(?array $stored): self
    {
        $ids = [];
        foreach (\is_array($stored['answerIds'] ?? null) ? $stored['answerIds'] : [] as $id) {
            if (\is_int($id)) {
                $ids[] = $id;
            }
        }
        $value = $stored['value'] ?? null;
        $text = $stored['text'] ?? null;

        return new self($ids, is_numeric($value) ? (float) $value : null, \is_string($text) ? $text : null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter(
            ['answerIds' => [] === $this->answerIds ? null : $this->answerIds, 'value' => $this->value, 'text' => $this->text],
            static fn (mixed $value): bool => null !== $value,
        );
    }

    /**
     * Whether this is an answer of the right shape for the question - never trust the client.
     *
     * @param list<int> $questionAnswerIds the question's own propositions
     */
    public function isWellFormedFor(LiveAnswerKind $kind, array $questionAnswerIds): bool
    {
        $known = [] === array_diff($this->answerIds, $questionAnswerIds);
        $distinct = \count(array_unique($this->answerIds)) === \count($this->answerIds);

        return match ($kind) {
            LiveAnswerKind::Choice => 1 === \count($this->answerIds) && $known,
            LiveAnswerKind::Multi => [] !== $this->answerIds && $known && $distinct,
            // Every piece, each once: a partial order is not an order.
            LiveAnswerKind::Order => \count($this->answerIds) === \count($questionAnswerIds) && $known && $distinct,
            LiveAnswerKind::Number => null !== $this->value && is_finite($this->value),
            LiveAnswerKind::Text => null !== $this->text && mb_strlen($this->text) <= self::MAX_TEXT_LENGTH,
        };
    }
}
