<?php

declare(strict_types=1);

namespace App\SeriousGame\Scenario;

/**
 * One step of a ticket, or one of Inès' guided questions (design/validated/serious-games-neko-con.md
 * §2, §3.1). It carries its answers - which option is right, the misconception of each wrong one,
 * the text the right line holds, the right order of the items - and **publicView() is the only
 * thing the game ever receives**.
 *
 * @phpstan-type Reaction array{who: string, expr: string, text: string}
 * @phpstan-type Option array{text: string, ok: bool, misconception: ?string, reaction: ?Reaction}
 */
final readonly class ScenarioStep
{
    /**
     * @param list<Option>  $options
     * @param list<string>  $answers  the texts one of which the right line holds
     * @param Reaction|null $reaction what Inès says of a wrong line or order
     * @param list<string>  $items    what an « order » step shows, in the order shown
     * @param list<int>     $order    the right order: the ranks of `items`, first to last
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
        public ?string $label = null,
        public ?string $view = null,
        public array $items = [],
        public array $order = [],
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
        $order = [];
        if (ScenarioStepKind::Order === $kind) {
            $order = array_values(array_filter(\is_array($answer) ? $answer : [], is_int(...)));
            $answers = [];
        }
        $items = array_values(array_map(self::text(...), \is_array($data['items'] ?? null) ? $data['items'] : []));
        $optional = static fn (string $key): ?string => '' === ($value = self::text($data[$key] ?? null)) ? null : $value;

        return new self($id, $kind, self::text($data['prompt'] ?? null), $optional('expr'), $optional('file'), $options, $answers, self::reactionOf($data['reaction'] ?? null), $optional('label'), $optional('view'), $items, $order);
    }

    /**
     * What the game is told of the step: its question, its options' texts, the items to order in
     * the order written - never which is right, never a misconception, never the line's text, never
     * the right order.
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
        if (null !== $this->label) {
            $view['label'] = $this->label;
        }
        if (null !== $this->view) {
            $view['view'] = $this->view;
        }
        if (ScenarioStepKind::Order === $this->kind) {
            $view['items'] = $this->items;
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
