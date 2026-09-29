<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which official document an uploaded template is. One today: the E5 synthesis table of annexe
 * VI-1, published as an .xlsx with each session's circulaire. The E6 fiches may follow as .docx.
 */
enum ReferentialTemplateKind: string
{
    case E5Synthesis = 'e5_synthesis';

    public function labelKey(): string
    {
        return match ($this) {
            self::E5Synthesis => 'referentialTemplateE5SynthesisLabel',
        };
    }
}
