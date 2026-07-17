<?php

namespace App\Enum;

enum EcoRunnerStatus: string
{
    case NotStarted = 'not_started';
    case Racing = 'racing';
    case Finished = 'finished';

    public function labelKey(): string
    {
        return match ($this) {
            self::NotStarted => 'ecoRunnerStatusNotStartedLabel',
            self::Racing => 'ecoRunnerStatusRacingLabel',
            self::Finished => 'ecoRunnerStatusFinishedLabel',
        };
    }
}
