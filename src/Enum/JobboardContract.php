<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The kind of contract an offer proposes - one of the values the veille guarantees.
 *
 * The colours are the handoff's own (design/jobboard/design_handoff_jobboard/README.md, « Code
 * couleur des contrats ») and live here rather than in the template because the same tag is drawn
 * in the list and in the detail panel: two copies would drift on the day a further contract
 * appears.
 *
 * `Spontanee` is the odd one: it names a company that accepts unsolicited applications rather than
 * an advert for a post. Nothing else about the row changes - it is collected, filed, filtered and
 * closed exactly like an offer - except that the agent has no job title to read, so this is the
 * one contract whose `poste` may be left out; see defaultPosition().
 */
enum JobboardContract: string
{
    case Alternance = 'alternance';
    case Stage = 'stage';
    case Cdd = 'cdd';
    case Cdi = 'cdi';
    case Spontanee = 'spontanee';
    case Autre = 'autre';

    public function labelKey(): string
    {
        return match ($this) {
            self::Alternance => 'jobboardContractAlternanceLabel',
            self::Stage => 'jobboardContractStageLabel',
            self::Cdd => 'jobboardContractCddLabel',
            self::Cdi => 'jobboardContractCdiLabel',
            self::Spontanee => 'jobboardContractSpontaneeLabel',
            self::Autre => 'jobboardContractAutreLabel',
        };
    }

    /**
     * The title to file the offer under when the payload carries none, or null when the contract
     * demands one.
     *
     * It is stored on the row rather than substituted at display time, so that a search on the
     * position finds these entries the way it finds every other one. The agent may still send a
     * `poste` of its own - a company page often names the kind of profile it is open to - and what
     * it sends always wins.
     */
    public function defaultPosition(): ?string
    {
        return self::Spontanee === $this ? 'Candidature spontanée' : null;
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
