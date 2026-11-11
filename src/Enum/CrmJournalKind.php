<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A line of a dossier's journal. The first four are written by the team; « Dossier » is what the
 * CRM writes about the dossier itself, « Saisie » what it writes when a piece is entered or removed.
 */
enum CrmJournalKind: string implements HasBadge
{
    case Note = 'note';
    case Call = 'call';
    case Mail = 'mail';
    case Meeting = 'meeting';
    case System = 'system';
    case Entry = 'entry';

    public function labelKey(): string
    {
        return match ($this) {
            self::Note => 'crmJournalNoteLabel',
            self::Call => 'crmJournalCallLabel',
            self::Mail => 'crmJournalMailLabel',
            self::Meeting => 'crmJournalMeetingLabel',
            self::System => 'crmJournalSystemLabel',
            self::Entry => 'crmJournalEntryLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return $this->isWrittenByTheCrm() ? BadgeTone::Gray : BadgeTone::Blue;
    }

    public function isWrittenByTheCrm(): bool
    {
        return self::System === $this || self::Entry === $this;
    }

    /** @return list<self> */
    public static function teamKinds(): array
    {
        return [self::Note, self::Call, self::Mail, self::Meeting];
    }
}
