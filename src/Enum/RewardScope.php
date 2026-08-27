<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Who a reward is granted to: one student, one team, or the whole class (§5.5).
 *
 * The team and class scopes are what let a collective be rewarded **without inventing collective
 * points**. The only collective figure in the whole system is the team's threshold of §4, decision 7,
 * and it pays each member in recognition points; everything else a group earns together is a reward,
 * which by construction moves no index.
 */
enum RewardScope: string
{
    case Student = 'student';
    case Team = 'team';
    case ClassWide = 'class';

    public function labelKey(): string
    {
        return match ($this) {
            self::Student => 'rewardScopeStudentLabel',
            self::Team => 'rewardScopeTeamLabel',
            self::ClassWide => 'rewardScopeClassLabel',
        };
    }
}
