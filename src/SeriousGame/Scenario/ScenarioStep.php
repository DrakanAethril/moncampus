<?php

declare(strict_types=1);

namespace App\SeriousGame\Scenario;

/**
 * One step of a ticket, or one of Inès' guided questions (design/validated/serious-games-neko-con.md
 * §2, §3.1). It carries its answers - which option is right, the misconception of each wrong one,
 * the text the right line holds - and **publicView() is the only thing the game ever receives**.
 *
 * @phpstan-type Reaction array{who: string, expr: string, text: string}
 * @phpstan-type Option array{text: string, ok: bool, misconception: ?string, reaction: ?Reaction}
 */
final readonly class ScenarioStep
{
    /**
     * @param list<Option>  $options
     * @param list<string>  $answers  the texts one of which the right line holds
     * @param Reaction|null $reaction what Inès says of a wrong line
     */
    public function __construct(
        public string $id,
        public ScenarioStepKind $kind,
        public string $prompt,
        public ?string $expr = null,
        public ?string $file = null,
        public array $options = [],
        public array $answers = [],
        public ?array $reaction = null,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $kind = ScenarioStepKind::tryFrom(self::text($data['kind'] ?? null));
        $id = self::text($data['id'] ?? null);
        if (null === $kind || '' === $id) {
            return null;
        }

        $options = [];
        foreach (\is_array($data['options'] ?? null) ? $data['options'] : [] as $option) {
            if (!\is_array($option)) {
                continue;
            }
            $misconception = self::text($option['misconception'] ?? null);
            $options[] = [
                'text' => self::text($option['text'] ?? null),
                'ok' => true === ($option['ok'] ?? false),
                'misconception' => '' === $misconception ? null : $misconception,
                'reaction' => self::reactionOf($option['reaction'] ?? null),
            ];
        }

        $answer = $data['answer'] ?? [];
        $answers = array_values(array_filter(array_map(self::text(...), \is_array($answer) ? $answer : [$answer]), static fn (string $a): bool => '' !== $a));
        $expr = self::text($data['expr'] ?? null);
        $file = self::text($data['file'] ?? null);

        return new self($id, $kind, self::text($data['prompt'] ?? null), '' === $expr ? null : $expr, '' === $file ? null : $file, $options, $answers, self::reactionOf($data['reaction'] ?? null));
    }

    /**
     * What the game is told of the step: its question and its options' texts - never which is
     * right, never a misconception, never the line's text.
     *
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        $view = ['id' => $this->id, 'kind' => $this->kind->value, 'prompt' => $this->prompt];
        if ($this->kind->hasOptions()) {
            $view['options'] = array_map(static fn (array $o): string => $o['text'], $this->options);
        }
        if (null !== $this->file) {
            $view['file'] = $this->file;
        }
        if (null !== $this->expr) {
            $view['expr'] = $this->expr;
        }

        return $view;
    }

    /** @return Reaction|null */
    public static function reactionOf(mixed $value): ?array
    {
        if (!\is_array($value) || !\is_string($value['text'] ?? null)) {
            return null;
        }

        return ['who' => self::text($value['who'] ?? 'ines') ?: 'ines', 'expr' => self::text($value['expr'] ?? 'neutre') ?: 'neutre', 'text' => $value['text']];
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) || \is_int($value) ? (string) $value : '';
    }
}
