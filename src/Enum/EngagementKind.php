<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The five declared engagements of §5.3, and what each of them pays once an adult has looked at it.
 *
 * These are the ones that carry the family. The six automatic signals - a survey answered, a wiki
 * revision, an application filed - are deliberately worth little and capped by the week: they are
 * real gestures, but they are also *repeatable at will*, and without a ceiling they get emitted in a
 * batch on a Sunday evening. **The bulk of volunteering comes from here**, that is to say from
 * things somebody has read.
 */
enum EngagementKind: string
{
    case Certification = 'certification';
    case Representation = 'representation';
    case Project = 'project';
    case Tutoring = 'tutoring';
    case Mandate = 'mandate';

    public function points(): int
    {
        return match ($this) {
            self::Certification => 60,
            self::Representation, self::Project => 40,
            self::Tutoring, self::Mandate => 30,
        };
    }

    /** The rule the credit is written under, so the journal names what was validated. */
    public function ruleCode(): string
    {
        return match ($this) {
            self::Certification => 'engagement.certification',
            self::Representation => 'engagement.representation',
            self::Project => 'engagement.project',
            self::Tutoring => 'engagement.tutoring',
            self::Mandate => 'engagement.mandate',
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Certification => 'engagementKindCertificationLabel',
            self::Representation => 'engagementKindRepresentationLabel',
            self::Project => 'engagementKindProjectLabel',
            self::Tutoring => 'engagementKindTutoringLabel',
            self::Mandate => 'engagementKindMandateLabel',
        };
    }

    /** What the form says is expected next to the declaration - the design's « pièce attendue ». */
    public function expectedProofKey(): string
    {
        return match ($this) {
            self::Certification => 'engagementProofCertificationText',
            // Nobody has to prove it: the teacher was there.
            self::Representation => 'engagementProofRepresentationText',
            self::Project => 'engagementProofProjectText',
            self::Tutoring => 'engagementProofTutoringText',
            self::Mandate => 'engagementProofMandateText',
        };
    }

    /** A mandate is declared once per formation; the others may be declared as often as they happen. */
    public function isOncePerPeriod(): bool
    {
        return self::Mandate === $this;
    }
}
