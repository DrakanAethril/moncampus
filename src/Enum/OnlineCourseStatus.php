<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where an online course stands (design/validated/cours-en-ligne.md, §8).
 *
 * Draft is what a course is born as, whoever creates it - a screen or the Claude connector.
 * Public puts it on its author's page, readable without an account. PathOnly keeps it off that
 * page: it is then read only from an opened step of a learning path, which is what makes a path's
 * lock a real one for that course.
 *
 * The value is stored, so renaming a case means migrating rows.
 */
enum OnlineCourseStatus: string implements HasBadge
{
    case Draft = 'draft';
    case PublicCourse = 'public';
    case PathOnly = 'path_only';

    /** Online at all, on the author's page or inside a path only. */
    public function isPublished(): bool
    {
        return self::Draft !== $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Draft => 'onlineCourseStatusDraftLabel',
            self::PublicCourse => 'onlineCourseStatusPublicLabel',
            self::PathOnly => 'onlineCourseStatusPathOnlyLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Draft => BadgeTone::Gray,
            self::PublicCourse => BadgeTone::Green,
            self::PathOnly => BadgeTone::Purple,
        };
    }
}
