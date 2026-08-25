<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\Feature;
use App\Enum\FeatureAccessState;

/**
 * The resolution rule of design/validated/feature-access.md §6, and nothing else.
 *
 * Deliberately built on **primitives** - a list of roles, a matrix, a table of overrides, the set
 * of features at least one of the person's formations opens - rather than on Doctrine entities and
 * a security token. App\Security\FeatureAccess is the shell that goes and reads those four things
 * once per request; this class is the rule, and it is the rule that is testable
 * (tests/Security/FeatureResolverTest.php).
 *
 * The separation is not decoration. A wrong resolution order throws nothing: it shows or hides one
 * screen too many, and nobody notices for a week. Being able to state the six cases without a
 * database is what makes that the one part of this design with a test that means something.
 *
 * The order, each step returning:
 *
 *   1. `ROLE_ADMIN` -> true, nothing is read.
 *   2. Parent declared and extinguished -> false. **Before** the override on purpose: a child
 *      switched on by hand must not resurrect under a parent that no longer exists.
 *   3. Individual override, if there is one.
 *   4. Program-scoped feature -> does at least one formation open it.
 *   5. Role matrix -> does at least one *managed* role of this person have it ticked.
 *   6. Otherwise the catalogue's own default.
 *
 * It can only ever remove. Nothing here grants a right: the Voters stay the sole authority on who
 * writes what, and a feature switched on for a student does not open them a teacher's screen.
 */
final class FeatureResolver
{
    /** @var array<string, bool> memo, since the nav asks the same question some forty times a page */
    private array $answers = [];

    /**
     * @param list<string>                      $roles        the account's roles, unfiltered - the managed ones are picked out here
     * @param array<string, bool>               $matrix       the stored role matrix, keyed `"<feature>|<role>"`; an absent pair is not a `false`
     * @param array<string, FeatureAccessState> $overrides    the account's own overrides, keyed by feature value
     * @param list<string>                      $openPrograms the feature values at least one of the account's formations opens
     */
    public function __construct(
        private readonly bool $isAdmin,
        private readonly array $roles,
        private readonly array $matrix = [],
        private readonly array $overrides = [],
        private readonly array $openPrograms = [],
    ) {
    }

    public function isEnabled(Feature $feature): bool
    {
        return $this->answers[$feature->value] ??= $this->resolve($feature);
    }

    /**
     * The whole catalogue resolved, keyed by the enum's values - the mobile app's `features`
     * object, and the lookup the nav reads.
     *
     * @return array<string, bool>
     */
    public function all(): array
    {
        $all = [];
        foreach (Feature::cases() as $feature) {
            $all[$feature->value] = $this->isEnabled($feature);
        }

        return $all;
    }

    private function resolve(Feature $feature): bool
    {
        // 1. The escape hatch (§8.8). Read nothing: it is what makes switching a feature off for
        // everybody a safe gesture, and what guarantees no setting can close the settings screen.
        if ($this->isAdmin) {
            return true;
        }

        // 2. Before the override, deliberately.
        $parent = $feature->parent();
        if (null !== $parent && !$this->isEnabled($parent)) {
            return false;
        }

        // 3. The individual derogation, in both directions - it comes before the formation flag,
        // which is what opens a mailbox to one student of a closed formation (§3.5).
        $override = $this->overrides[$feature->value] ?? null;
        if ($override instanceof FeatureAccessState) {
            return $override->isEnabled();
        }

        // 4. The formation axis short-circuits the matrix: for a scoped feature, what the
        // establishment decided per formation is the answer, most permissive across formations.
        if ($feature->isProgramScoped()) {
            return \in_array($feature->value, $this->openPrograms, true);
        }

        // 5. The matrix, most permissive across the managed roles this person holds. Roles with no
        // column - ROLE_USER, the cohort roles - are not read at all: reading them would make every
        // "ON by default" feature impossible to switch off, ROLE_USER answering for everybody.
        $answered = false;
        foreach (Feature::managedRoles() as $role) {
            if (!\in_array($role, $this->roles, true)) {
                continue;
            }

            $answered = true;
            // An absent pair is not a `false`: it falls back on the catalogue, so adding a feature
            // or a column to the matrix needs no data migration to stay coherent.
            if ($this->matrix[$feature->value.'|'.$role] ?? $feature->defaultForRoles()) {
                return true;
            }
        }

        // 6. Either every managed role said no, or this account holds none of them.
        return $answered ? false : $feature->defaultForRoles();
    }
}
