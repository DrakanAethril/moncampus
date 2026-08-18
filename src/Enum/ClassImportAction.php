<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What the import would do with one line of the file - decided by App\Service\ClassImport\
 * ClassImportAnalyzer, shown on the verification screen, and decided again from scratch just
 * before anything is written.
 */
enum ClassImportAction: string
{
    /** Nobody carries this name: an account, its directory request and its class membership. */
    case Create = 'create';

    /** An existing account the operator recognised as this very person, added to the class. */
    case Attach = 'attach';

    /** Already a student of this class: the missing address and the missing options, nothing else. */
    case Update = 'update';

    /**
     * At least one account carries this name, outside the class, and the operator has not said
     * whether it is the same person. Blocks the import until they do - two people really can carry
     * the same name, so this is never decided for them.
     */
    case Decide = 'decide';

    /** At least one Blocking finding on this line, so the file as a whole cannot be imported. */
    case Blocked = 'blocked';

    public function labelKey(): string
    {
        return match ($this) {
            self::Create => 'classImportActionCreateLabel',
            self::Attach => 'classImportActionAttachLabel',
            self::Update => 'classImportActionUpdateLabel',
            self::Decide => 'classImportActionDecideLabel',
            self::Blocked => 'classImportActionBlockedLabel',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Create => 'cm-badge--green',
            self::Attach => 'cm-badge--blue',
            self::Update => 'cm-badge--gray',
            self::Decide => 'cm-badge--gold',
            self::Blocked => 'cm-badge--red',
        };
    }
}
