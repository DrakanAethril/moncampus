<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The groups a piece belongs to. The first three are the stages a dossier goes through, in this
 * order: they decide its step and its « Complet ». The fourth, « Documents de suivi », holds the
 * documents the team follows without making the dossier wait for them - a piece is moved there by
 * unticking « Complétude » in Réglages › Pièces, and never holds the step back.
 */
enum CrmPhase: string
{
    case PreEnrolment = 'pre_enrolment';
    case Enrolment = 'enrolment';
    case Documents = 'documents';
    case FollowUp = 'follow_up';

    public function labelKey(): string
    {
        return match ($this) {
            self::PreEnrolment => 'crmPhasePreEnrolmentLabel',
            self::Enrolment => 'crmPhaseEnrolmentLabel',
            self::Documents => 'crmPhaseDocumentsLabel',
            self::FollowUp => 'crmPhaseFollowUpLabel',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PreEnrolment => 'Pré-inscription',
            self::Enrolment => 'Inscription',
            self::Documents => 'Documents d’entrée',
            self::FollowUp => 'Documents de suivi',
        };
    }

    /** The step a dossier is at while this stage waits; none for the follow-up documents. */
    public function step(): ?CrmStep
    {
        return match ($this) {
            self::PreEnrolment => CrmStep::PreEnrolment,
            self::Enrolment => CrmStep::Enrolment,
            self::Documents => CrmStep::Documents,
            self::FollowUp => null,
        };
    }

    /** Whether its pieces count towards the dossier's « Complet ». */
    public function countsTowardsCompletion(): bool
    {
        return self::FollowUp !== $this;
    }

    /** The documents, entry and follow-up alike: asked by modality, shown split by modality. */
    public function isDocuments(): bool
    {
        return self::Documents === $this || self::FollowUp === $this;
    }

    /**
     * The stages that make the step, in order.
     *
     * @return list<self>
     */
    public static function stages(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $phase): bool => $phase->countsTowardsCompletion()));
    }
}
