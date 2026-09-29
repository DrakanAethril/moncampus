<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where an employer's SIRET stands - always *read* off Enterprise (Enterprise::siretStatus()),
 * never stored: the set-aside runs out on its own, and a stored state would have to be told so.
 *
 * Only Confirmed says the number is the right one, and only a person can make it so
 * (design/validated/siret-entreprises.md, R4). Pending and Missing are the two faces of « à
 * confirmer » - a number somebody typed or imported without looking, and no number at all - and
 * both are in the queue.
 */
enum EnterpriseSiretStatus: string implements HasBadge
{
    /** Somebody associated it while seeing what it designates; the fiche says who and when. */
    case Confirmed = 'confirmed';

    /** A number is recorded, nobody has looked at what it designates yet. */
    case Pending = 'pending';

    /** No number, and nobody has looked for one yet (or the set-aside ran out). */
    case Missing = 'missing';

    /** « Pas de SIRET trouvable », for Enterprise::SIRET_SET_ASIDE_DAYS - then back in the queue. */
    case SetAside = 'set_aside';

    public function isAwaitingReview(): bool
    {
        return self::Pending === $this || self::Missing === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Confirmed => 'enterpriseSiretStatusConfirmedLabel',
            self::Pending => 'enterpriseSiretStatusPendingLabel',
            self::Missing => 'enterpriseSiretStatusMissingLabel',
            self::SetAside => 'enterpriseSiretStatusSetAsideLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Confirmed => BadgeTone::Green,
            self::Pending => BadgeTone::Gold,
            self::Missing => BadgeTone::Gold,
            self::SetAside => BadgeTone::Gray,
        };
    }
}
