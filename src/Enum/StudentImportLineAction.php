<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What the import actually did with one student - the three writing verdicts of
 * App\Enum\ClassImportAction, kept once the import has run.
 *
 * Deliberately a smaller set than the analysis's: Decide and Blocked describe a file that was
 * never written, so they can never reach a App\Entity\StudentImportBatchLine.
 */
enum StudentImportLineAction: string
{
    /** An account, its directory request and its class membership. */
    case Create = 'create';

    /** An existing account the operator recognised as this person, added to the class. */
    case Attach = 'attach';

    /** Already a student of this class: the missing address and the missing options, nothing else. */
    case Update = 'update';

    public function labelKey(): string
    {
        return match ($this) {
            self::Create => 'classImportActionCreateLabel',
            self::Attach => 'classImportActionAttachLabel',
            self::Update => 'classImportActionUpdateLabel',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Create => 'cm-badge--green',
            self::Attach => 'cm-badge--blue',
            self::Update => 'cm-badge--gray',
        };
    }

    public static function fromAnalysis(ClassImportAction $action): self
    {
        return match ($action) {
            ClassImportAction::Create => self::Create,
            ClassImportAction::Attach => self::Attach,
            ClassImportAction::Update => self::Update,
            // Unreachable: the executor only ever runs against an importable analysis, which has
            // neither by definition. Spelled out rather than silently defaulted.
            default => throw new \LogicException(sprintf('A %s line is never written.', $action->value)),
        };
    }
}
