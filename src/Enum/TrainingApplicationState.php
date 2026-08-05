<?php

namespace App\Enum;

/**
 * Where a training application stands (design_handoff_workflow_postulation, screens 8a to 8e).
 *
 * The cycle is `Received` -> a validator reviews -> `Validated` (everything accepted) or
 * `CorrectionsRequested` (at least one element refused) -> the student fixes and resends ->
 * `Resent` -> reviewed again -> ... -> `Validated`.
 *
 * `CorrectionsRequested` is the one state where the ball is in the student's court, which is why
 * the validators' queue (screen 8c) shows every state but that one.
 */
enum TrainingApplicationState: string
{
    case Received = 'received';
    case CorrectionsRequested = 'corrections_requested';
    case Resent = 'resent';
    case Validated = 'validated';

    /** Waiting on a validator, as opposed to waiting on the student. */
    public function isAwaitingReview(): bool
    {
        return self::Received === $this || self::Resent === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Received => 'trainingApplicationStateReceivedLabel',
            self::CorrectionsRequested => 'trainingApplicationStateCorrectionsRequestedLabel',
            self::Resent => 'trainingApplicationStateResentLabel',
            self::Validated => 'trainingApplicationStateValidatedLabel',
        };
    }

    /** Chip colour, per the handoff's own palette: blue waiting, amber to fix, green done. */
    public function chipVariant(): string
    {
        return match ($this) {
            self::Received, self::Resent => 'reply',
            self::CorrectionsRequested => 'waiting',
            self::Validated => 'validated',
        };
    }
}
