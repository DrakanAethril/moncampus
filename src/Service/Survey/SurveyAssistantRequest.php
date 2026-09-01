<?php

declare(strict_types=1);

namespace App\Service\Survey;

/**
 * « Ma demande » - what the author states when the questionnaire is to be written from a subject
 * rather than transposed from a document they already hold.
 *
 * The four named fields are the four lines App\Service\Survey\SurveyPromptCatalog's demand block
 * carries between brackets ([Satisfaction de la formation], [BTS SIO 2e année], …). Filling them in
 * the application rather than inside the conversation is the whole point of the step: the copied
 * prompt is then complete, and a field left blank simply keeps its bracketed example, so the worst
 * case is the prompt a teacher would have written by hand.
 *
 * `$goal` has no equivalent on the quiz side, and it is the field that decides whether the survey is
 * worth sending: « ce que je veux savoir » is what separates a questionnaire that will be acted upon
 * from a list of questions about a topic. `$notions` (the quiz's third field) has none here for the
 * same reason - a survey does not cover a syllabus.
 *
 * A value object on primitives: it never sees a Request, a session or a form, so the rules live in
 * one readable place and are tested without booting a kernel.
 */
final class SurveyAssistantRequest
{
    /**
     * A ceiling on the *stated* count, not on what comes back - the same reasoning as
     * App\Service\QuizAssistantRequest::MAX_QUESTION_COUNT, with a much lower number: a survey
     * nobody finishes measures nothing, and 40 questions is already long for one.
     */
    public const int MAX_QUESTION_COUNT = 40;

    public function __construct(
        public readonly string $theme = '',
        public readonly string $goal = '',
        public readonly string $audience = '',
        public readonly ?int $questionCount = null,
        public readonly string $extra = '',
    ) {
    }

    /**
     * Reads what the session or the form hands over, which is `mixed` by construction - a resumed
     * assistant must not fatal on a value nobody ever typed.
     *
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            theme: self::text($raw['theme'] ?? null),
            goal: self::text($raw['goal'] ?? null),
            audience: self::text($raw['audience'] ?? null),
            questionCount: self::count($raw['questionCount'] ?? null),
            extra: self::text($raw['extra'] ?? null),
        );
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'theme' => $this->theme,
            'goal' => $this->goal,
            'audience' => $this->audience,
            'questionCount' => $this->questionCount,
            'extra' => $this->extra,
        ];
    }

    public function isEmpty(): bool
    {
        return '' === $this->theme
            && '' === $this->goal
            && '' === $this->audience
            && '' === $this->extra
            && null === $this->questionCount;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }

    private static function count(mixed $value): ?int
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $count = (int) $value;
        if ($count < 1) {
            return null;
        }

        return min($count, self::MAX_QUESTION_COUNT);
    }
}
