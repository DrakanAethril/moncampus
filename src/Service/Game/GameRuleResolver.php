<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Program;
use App\Repository\GameRuleRepository;

/**
 * What a rule is worth here and now: the catalogue, overridden by whatever this formation saved for
 * this period.
 *
 * The catalogue is the authority on the *shape* of a rule - which family it pays into, what one
 * occasion adds to the possible - and the stored row only ever moves its value, its weekly cap or
 * switches it off. A code absent from the catalogue answers null everywhere rather than inventing a
 * family, which is what keeps a stale row in the database from paying into nothing.
 */
final class GameRuleResolver
{
    /** @var array<string, array<string, GameRuleValue>> */
    private array $cache = [];

    public function __construct(private readonly GameRuleRepository $rules)
    {
    }

    public function valueOf(Program $program, string $code): ?GameRuleValue
    {
        return $this->all($program)[$code] ?? null;
    }

    /** What one occasion of this rule pays, 0 when it is switched off or unknown. */
    public function pointsOf(Program $program, string $code): int
    {
        $value = $this->valueOf($program, $code);

        return null !== $value && $value->enabled ? $value->points : 0;
    }

    /**
     * Every rule, resolved, keyed by code.
     *
     * @return array<string, GameRuleValue>
     */
    public function all(Program $program): array
    {
        $key = (string) $program->getId();

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $overrides = $this->rules->findForProgram($program);

        $resolved = [];
        foreach (GameRuleCatalog::all() as $code => $definition) {
            $override = $overrides[$code] ?? null;

            $resolved[$code] = new GameRuleValue(
                $definition,
                $override?->getPoints() ?? $definition->points,
                null !== $override ? $override->getWeeklyCap() : $definition->weeklyCap,
                $override?->isEnabled() ?? true,
            );
        }

        return $this->cache[$key] = $resolved;
    }
}
