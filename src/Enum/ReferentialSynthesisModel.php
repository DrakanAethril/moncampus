<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which official synthesis table a référentiel is exported to.
 *
 * `SioE5` is the annexe VI-1 of the BTS SIO - three parts, the six competencies of bloc 1 as
 * columns, an uploaded .xlsx per session. `Generic` has no official document: its table stays on
 * screen and in PDF, and no template can be uploaded for it.
 */
enum ReferentialSynthesisModel: string
{
    case SioE5 = 'sio_e5';
    case Generic = 'generic';

    public function labelKey(): string
    {
        return match ($this) {
            self::SioE5 => 'referentialSynthesisSioE5Label',
            self::Generic => 'referentialSynthesisGenericLabel',
        };
    }
}
