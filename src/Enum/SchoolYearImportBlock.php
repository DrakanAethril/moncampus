<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The boxes of the school-year import (design/validated/school-year-import.md §3): two calendars of
 * the establishment, then what is taken over for every formation ticked. « La formation elle-même »
 * is not a case: it is always taken over.
 *
 * The dependencies are written here once - the screen greys a box from them, and
 * App\Service\SchoolYearImport\SchoolYearImportChoices unticks a box whose dependency is unticked,
 * so a forged request cannot ask for holders without a team.
 */
enum SchoolYearImportBlock: string
{
    case PeriodCalendars = 'period_calendars';
    case EvaluationCalendars = 'evaluation_calendars';
    case Team = 'team';
    case Referents = 'referents';
    case TeacherOptions = 'teacher_options';
    case Holders = 'holders';
    case Topics = 'topics';
    case Syllabus = 'syllabus';
    case Skills = 'skills';
    case CalendarLinks = 'calendar_links';
    case Financial = 'financial';
    case Internship = 'internship';
    case Portfolio = 'portfolio';
    case PortfolioValidators = 'portfolio_validators';
    case Game = 'game';
    case Crm = 'crm';

    /** @return list<self> the calendars of the establishment, shown apart from the formations' content */
    public static function calendars(): array
    {
        return [self::PeriodCalendars, self::EvaluationCalendars];
    }

    /** @return list<self> what is taken over for each formation, in the screen's order */
    public static function programBlocks(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $block): bool => !\in_array($block, self::calendars(), true)));
    }

    /** Ticked on arrival: everything but the game and the CRM (§3), the team included (Q2). */
    public function isDefault(): bool
    {
        return self::Game !== $this && self::Crm !== $this;
    }

    /** @return list<self> every one of these must be ticked too */
    public function needs(): array
    {
        return match ($this) {
            self::Referents, self::TeacherOptions, self::Holders => [self::Team],
            self::PortfolioValidators => [self::Team, self::Portfolio],
            default => [],
        };
    }

    /** @return list<self> at least one of these must be ticked too */
    public function needsAny(): array
    {
        return match ($this) {
            self::Holders => [self::Topics, self::Skills],
            self::CalendarLinks => [self::PeriodCalendars, self::EvaluationCalendars],
            default => [],
        };
    }

    /** A sub-box of the team or of the portfolio, drawn indented under it. */
    public function isChild(): bool
    {
        return \in_array($this, [self::Referents, self::TeacherOptions, self::Holders, self::PortfolioValidators], true);
    }

    public function labelKey(): string
    {
        return 'schoolYearImportBlock'.str_replace('_', '', ucwords($this->value, '_')).'Label';
    }

    public function hintKey(): string
    {
        return 'schoolYearImportBlock'.str_replace('_', '', ucwords($this->value, '_')).'Hint';
    }
}
