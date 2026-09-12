<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The kind of contract an offer proposes - one of the five values the veille guarantees.
 *
 * The colours are the handoff's own (design/jobboard/design_handoff_jobboard/README.md, « Code
 * couleur des contrats ») and live here rather than in the template because the same tag is drawn
 * in the list and in the detail panel: two copies would drift on the day a sixth contract appears.
 */
enum JobboardContract: string
{
    case Alternance = 'alternance';
    case Stage = 'stage';
    case Cdd = 'cdd';
    case Cdi = 'cdi';
    case Autre = 'autre';

    public function labelKey(): string
    {
        return match ($this) {
            self::Alternance => 'jobboardContractAlternanceLabel',
            self::Stage => 'jobboardContractStageLabel',
            self::Cdd => 'jobboardContractCddLabel',
            self::Cdi => 'jobboardContractCdiLabel',
            self::Autre => 'jobboardContractAutreLabel',
        };
    }

    /** The CSS modifier of the tag, `cm-jb-tag--<slug>`. */
    public function slug(): string
    {
        return $this->value;
    }

    public static function tryFromLoose(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
