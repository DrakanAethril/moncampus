<?php

declare(strict_types=1);

namespace App\SeriousGame\Scenario;

/**
 * A ticket of a scenario (design/validated/serious-games-neko-con.md §3.1) - the unit of the
 * mechanic. What the server knows of it: where it sits in the graph (`act`, `after`), who asks
 * (`requester`, `from`), the course it revises, its steps and Inès' guided questions with their
 * answers, how many written hints the game holds after them, and what closing it gives.
 *
 * Steps are designated by their identifier, never by their rank (§3.3): a revision that reorders or
 * removes a step does not block a ticket in progress - an unknown step starts it again.
 *
 * @phpstan-type Fragment array{index: int, value: string, where: string}
 */
final readonly class ScenarioTicket
{
    public const string RESOLVED = 'resolved';
    public const string REASSIGNED = 'reassigned';

    /**
     * @param list<string>       $after the tickets that must be closed before this one opens
     * @param list<ScenarioStep> $steps
     * @param list<ScenarioStep> $guide
     * @param Fragment|null      $fragment
     */
    public function __construct(
        public string $id,
        public int $act,
        public array $after,
        public string $requester,
        public ?string $from,
        public string $priority,
        public int $duplicates,
        public string $title,
        public string $request,
        public string $course,
        public string $closeAs,
        public bool $bascule,
        public bool $final,
        public array $steps,
        public array $guide,
        public int $hints,
        public ?array $fragment,
        public ?string $clue,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $id = \is_string($data['id'] ?? null) ? $data['id'] : '';
        if ('' === $id) {
            return null;
        }

        $steps = static fn (mixed $list): array => array_values(array_filter(array_map(
            static fn (mixed $step): ?ScenarioStep => \is_array($step) ? ScenarioStep::fromArray($step) : null,
            \is_array($list) ? $list : [],
        )));
        $reward = \is_array($data['reward'] ?? null) ? $data['reward'] : [];
        $fragment = \is_array($reward['fragment'] ?? null) ? $reward['fragment'] : null;
        $from = self::text($data['from'] ?? null);

        return new self(
            $id,
            \is_int($data['act'] ?? null) ? $data['act'] : 1,
            array_values(array_filter(array_map(self::text(...), \is_array($data['after'] ?? null) ? $data['after'] : []))),
            self::text($data['requester'] ?? null) ?: 'ines',
            '' === $from ? null : $from,
            self::text($data['priority'] ?? null) ?: 'normale',
            \is_int($data['duplicates'] ?? null) ? max(1, $data['duplicates']) : 1,
            self::text($data['title'] ?? null),
            self::text($data['request'] ?? null),
            self::text($data['course'] ?? null),
            self::REASSIGNED === ($data['closeAs'] ?? null) ? self::REASSIGNED : self::RESOLVED,
            true === ($data['bascule'] ?? false),
            true === ($data['final'] ?? false),
            $steps($data['steps'] ?? null),
            $steps($data['guide'] ?? null),
            \is_int($data['hints'] ?? null) ? max(0, $data['hints']) : 0,
            null === $fragment || !\is_int($fragment['index'] ?? null) ? null : ['index' => $fragment['index'], 'value' => self::text($fragment['value'] ?? null), 'where' => self::text($fragment['where'] ?? null)],
            \is_string($reward['clue'] ?? null) ? $reward['clue'] : null,
        );
    }

    public function reassigns(): bool
    {
        return self::REASSIGNED === $this->closeAs;
    }

    public function step(?string $id): ?ScenarioStep
    {
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        return null;
    }

    public function guideStep(string $id): ?ScenarioStep
    {
        foreach ($this->guide as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The rank of the step in progress: 0 when the ticket was never started or when a revision
     * removed its step, the number of steps once every one is answered.
     */
    public function stepIndex(?string $stepId): int
    {
        if (Scenario::ALL_STEPS_DONE === $stepId) {
            return \count($this->steps);
        }
        foreach ($this->steps as $index => $step) {
            if ($step->id === $stepId) {
                return $index;
            }
        }

        return 0;
    }

    /** The identifier of the first step, or ALL_STEPS_DONE for a ticket with none. */
    public function firstStepId(): string
    {
        return $this->steps[0]->id ?? Scenario::ALL_STEPS_DONE;
    }

    /** The step that follows a right answer to this one, or ALL_STEPS_DONE after the last. */
    public function stepAfter(string $stepId): string
    {
        $index = $this->stepIndex($stepId);

        return $this->steps[$index + 1]->id ?? Scenario::ALL_STEPS_DONE;
    }

    /** Inès' help is a ladder: her guided questions, then the game's written hints. */
    public function maxHints(): int
    {
        return \count($this->guide) + $this->hints;
    }

    public function hasRepair(): bool
    {
        foreach ($this->steps as $step) {
            if (ScenarioStepKind::Fix === $step->kind) {
                return true;
            }
        }

        return false;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) || \is_int($value) ? (string) $value : '';
    }
}
