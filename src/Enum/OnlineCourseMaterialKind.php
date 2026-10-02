<?php

declare(strict_types=1);

namespace App\Enum;

use App\Service\UploadPolicy;

/**
 * The natures a course's material comes in (design/validated/cours-en-ligne.md, §4) - the catalogue
 * the author's « Ajouter un support » menu, the upload field and the public player all read.
 *
 * Adding a nature is a case here: what it accepts, what it is called, which player shows it.
 * Nothing is migrated, since a material row only stores the case's value.
 *
 * The value is stored and it is also the default last segment of a material's public address
 * (`/courses/{page}/{course}/summary`), so renaming a case means migrating rows and breaking links.
 */
enum OnlineCourseMaterialKind: string implements HasBadge
{
    case Interactive = 'interactive';
    case Pdf = 'pdf';
    case Summary = 'summary';
    case Video = 'video';

    /**
     * What the upload field accepts for this nature - a narrowing of the platform list, like every
     * other field's. An interactive course travels as an archive: `html` and `js` are accepted
     * nowhere on their own (App\Service\UploadPolicy), and that stays true here.
     */
    public function uploadPolicy(): UploadPolicy
    {
        return match ($this) {
            self::Interactive => UploadPolicy::platform()->restrictTo('zip')->withMaxSize(UploadPolicy::PLATFORM_MAX_SIZE),
            // A course handout carries figures and captures: the 20 Mo a field gets by default is
            // what a scanned syllabus weighs, not a chapter.
            self::Pdf, self::Summary => UploadPolicy::pdf()->withMaxSize('50M'),
            self::Video => UploadPolicy::platform()->restrictTo('mp4', 'webm', 'mov')->withMaxSize(UploadPolicy::PLATFORM_MAX_SIZE),
        };
    }

    /** An archive unpacked into a folder of its own, rather than one file. */
    public function isBundle(): bool
    {
        return self::Interactive === $this;
    }

    /** Shown by the browser's own PDF reader. */
    public function isDocument(): bool
    {
        return self::Pdf === $this || self::Summary === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Interactive => 'onlineCourseKindInteractiveLabel',
            self::Pdf => 'onlineCourseKindPdfLabel',
            self::Summary => 'onlineCourseKindSummaryLabel',
            self::Video => 'onlineCourseKindVideoLabel',
        };
    }

    /** The short word a course card lists its materials with. */
    public function shortLabelKey(): string
    {
        return match ($this) {
            self::Interactive => 'onlineCourseKindInteractiveShortLabel',
            self::Pdf => 'onlineCourseKindPdfShortLabel',
            self::Summary => 'onlineCourseKindSummaryShortLabel',
            self::Video => 'onlineCourseKindVideoShortLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Interactive => BadgeTone::Blue,
            self::Pdf => BadgeTone::Gray,
            self::Summary => BadgeTone::Gold,
            self::Video => BadgeTone::Purple,
        };
    }

    /** The icon of assets/icons/ drawn next to the nature's name. */
    public function icon(): string
    {
        return match ($this) {
            self::Interactive => 'bolt',
            self::Pdf => 'file-text',
            self::Summary => 'list-details',
            self::Video => 'video',
        };
    }

    /**
     * The order a new material takes among its course's: the interactive course first, since it is
     * the one a course page opens on when the author has not decided otherwise.
     */
    public function defaultRank(): int
    {
        return match ($this) {
            self::Interactive => 0,
            self::Pdf => 1,
            self::Summary => 2,
            self::Video => 3,
        };
    }
}
