<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The two states an individual override can hold (App\Entity\UserFeatureAccess).
 *
 * There is deliberately **no `default` case**: choosing « Par défaut » on the user's card deletes
 * the row (design/validated/feature-access.md §5). That is what makes the override table empty at
 * deployment and the matrix the sole decider until somebody says otherwise - a stored `default`
 * would be a row that says nothing while looking like a decision.
 */
enum FeatureAccessState: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';

    public function isEnabled(): bool
    {
        return self::Enabled === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Enabled => 'featureOverrideEnabledLabel',
            self::Disabled => 'featureOverrideDisabledLabel',
        };
    }
}
