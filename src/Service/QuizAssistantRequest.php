<?php

declare(strict_types=1);

namespace App\Service;

/**
 * « Ma demande » - what the teacher states at step 1 of the quiz assistant when the questions are to
 * be written from a subject rather than transposed from a document or read off a course.
 *
 * The four named fields are the four lines App\Service\QuizPromptCatalog's closing already carried
 * between brackets ([Réseaux], [VLAN, trunk, 802.1Q], …). Filling them in the application rather
 * than in the conversation is the whole point of the step: the copied prompt is then complete, and a
 * field left blank simply keeps its bracketed example, so the worst case is exactly today's screen.
 *
 * `$extra` is free prose appended after them - the things a fixed form cannot anticipate («deux
 * questions de calcul de masque », « pas de question sur la RFC »).
 *
 * A value object on primitives: it never sees a Request, a session or a form, so the rules live in
 * one readable place and are tested without booting a kernel.
 */
final class QuizAssistantRequest
{
    /**
     * A ceiling on the *stated* count, not on what comes back.
     *
     * It exists because the number travels into a prompt: a teacher who types 9999 is asking a model
     * for something no model will do, and the honest answer is to carry a number that means
     * something rather than to refuse the form over it. Capping rather than refusing also keeps the
     * field free of validation the teacher would have to satisfy before seeing their prompt.
     */
    public const int MAX_QUESTION_COUNT = 100;

    public function __construct(
        public readonly string $subjectMatter = '',
        public readonly string $notions = '',
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
            subjectMatter: self::text($raw['subjectMatter'] ?? null),
            notions: self::text($raw['notions'] ?? null),
            audience: self::text($raw['audience'] ?? null),
            questionCount: self::count($raw['questionCount'] ?? null),
            extra: self::text($raw['extra'] ?? null),
        );
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'subjectMatter' => $this->subjectMatter,
            'notions' => $this->notions,
            'audience' => $this->audience,
            'questionCount' => $this->questionCount,
            'extra' => $this->extra,
        ];
    }

    public function isEmpty(): bool
    {
        return '' === $this->subjectMatter
            && '' === $this->notions
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
