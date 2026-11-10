<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The placement rules a teacher sets on a seating plan (design/validated/plans-de-classe.md, §4).
 *
 * What each one means is App\Service\SeatingPlan\SeatingRuleChecker's to say; this enum only
 * names them and bounds how many students a rule may hold - a « séparer » of one student
 * separates nothing, an « associer » of nine is a class, not a group.
 */
enum SeatingRuleType: string implements HasBadge
{
    case Separate = 'separate';
    case Together = 'together';
    case Front = 'front';
    case Back = 'back';
    case Alone = 'alone';

    public function minStudents(): int
    {
        return match ($this) {
            self::Separate, self::Together => 2,
            self::Front, self::Back, self::Alone => 1,
        };
    }

    public function maxStudents(): int
    {
        return match ($this) {
            self::Separate, self::Alone => 6,
            self::Together => 4,
            self::Front => 12,
            self::Back => 8,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Separate => 'seatingRuleSeparateLabel',
            self::Together => 'seatingRuleTogetherLabel',
            self::Front => 'seatingRuleFrontLabel',
            self::Back => 'seatingRuleBackLabel',
            self::Alone => 'seatingRuleAloneLabel',
        };
    }

    public function descriptionKey(): string
    {
        return match ($this) {
            self::Separate => 'seatingRuleSeparateDescription',
            self::Together => 'seatingRuleTogetherDescription',
            self::Front => 'seatingRuleFrontDescription',
            self::Back => 'seatingRuleBackDescription',
            self::Alone => 'seatingRuleAloneDescription',
        };
    }

    public function needKey(): string
    {
        return match ($this) {
            self::Separate => 'seatingRuleSeparateNeed',
            self::Together => 'seatingRuleTogetherNeed',
            self::Front, self::Back => 'seatingRuleOneOrMoreNeed',
            self::Alone => 'seatingRuleAloneNeed',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Separate => BadgeTone::Red,
            self::Together => BadgeTone::Green,
            self::Front => BadgeTone::Blue,
            self::Back => BadgeTone::Teal,
            self::Alone => BadgeTone::Purple,
        };
    }
}
