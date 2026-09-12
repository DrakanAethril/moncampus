<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The job sites the veille collects from - a closed list, on purpose.
 *
 * It is closed because the ingestion refuses an offer whose URL does not belong to the source it
 * declares (design/validated/jobboard.md §4.4), and that check needs a domain to compare against.
 * Adding a site is therefore a case here plus its domain, which is the price of the check and is
 * assumed.
 *
 * Each case also carries **how `source_ref` is built** for that site. That is not documentation: it
 * is published to the agent by App\Service\Jobboard\IngestInstructions, and it is the whole answer
 * to the one real trap of this feature - the legacy ids of Jobteaser and RemoteFR are truncations
 * of the site's own identifier, so an agent inventing a different rule would file the same offer
 * twice and lose the first-seen date that cannot be recovered (§8.2).
 */
enum JobboardSource: string
{
    case Hellowork = 'hellowork';
    case FranceTravail = 'francetravail';
    case Meteojob = 'meteojob';
    case Jobteaser = 'jobteaser';
    case RemoteFr = 'remotefr';
    case Jobillico = 'jobillico';
    case Aliptic = 'aliptic';
    case Jobup = 'jobup';
    case Moovijob = 'moovijob';

    /** The name a reader sees. Not a translation key: these are trade names, identical in every locale. */
    public function label(): string
    {
        return match ($this) {
            self::Hellowork => 'HelloWork',
            self::FranceTravail => 'France Travail',
            self::Meteojob => 'Meteojob',
            self::Jobteaser => 'Jobteaser',
            self::RemoteFr => 'RemoteFR',
            self::Jobillico => 'Jobillico',
            self::Aliptic => 'Aliptic',
            self::Jobup => 'Jobup',
            self::Moovijob => 'Moovijob',
        };
    }

    /**
     * The domains an offer URL may sit on. Compared on the host's suffix, so every subdomain of a
     * listed domain passes - `candidat.francetravail.fr` is France Travail's real offer host.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        return match ($this) {
            self::Hellowork => ['hellowork.com'],
            self::FranceTravail => ['francetravail.fr', 'pole-emploi.fr'],
            self::Meteojob => ['meteojob.com'],
            self::Jobteaser => ['jobteaser.com'],
            self::RemoteFr => ['remotefr.com'],
            self::Jobillico => ['jobillico.com'],
            self::Aliptic => ['aliptic.net'],
            self::Jobup => ['jobup.ch'],
            self::Moovijob => ['moovijob.com'],
        };
    }

    /**
     * The prefix this source's identifiers carried in the legacy `offres.json` (`hw-83313525`).
     * The import strips it to recover `source_ref`, and the published instructions quote it so the
     * agent keeps producing the same value.
     */
    public function legacyPrefix(): string
    {
        return match ($this) {
            self::Hellowork => 'hw',
            self::FranceTravail => 'ft',
            self::Meteojob => 'mj',
            self::Jobteaser => 'jt',
            self::RemoteFr => 'rf',
            self::Jobillico => 'jb',
            self::Aliptic => 'al',
            self::Jobup => 'ju',
            self::Moovijob => 'mv',
        };
    }

    /**
     * How `source_ref` is built, said in French because it is read by the collecting agent from the
     * Configuration > Jobboard > API screen.
     */
    public function refRule(): string
    {
        return match ($this) {
            self::Hellowork => "le nombre qui termine l'URL de l'offre (/emplois/83313525.html → 83313525)",
            self::FranceTravail => "le dernier segment de l'URL (/detail/212WTTG → 212WTTG)",
            self::Meteojob => "le dernier segment de l'URL (/jobs/56745532 → 56745532)",
            self::Jobteaser => "les 8 premiers caractères de l'UUID de l'URL (e2ec328c-1474-… → e2ec328c)",
            self::RemoteFr => "les 12 derniers caractères de l'identifiant rec… de l'URL (recJXBzjn9S2wFILz → Bzjn9S2wFILz)",
            self::Jobillico => "l'identifiant numérique de l'offre tel qu'il apparaît dans la liste de résultats",
            self::Aliptic => "le nombre qui ouvre le dernier segment de l'URL (/job/396-ingenieure… → 396)",
            self::Jobup => "l'identifiant numérique de l'offre",
            self::Moovijob => "l'identifiant de l'offre tel qu'il apparaît dans son URL",
        };
    }

    /**
     * The case a legacy file's display name maps to (`"France Travail"`, `"RemoteFR"`), and the one
     * a tolerant reader maps a value to. Returns null rather than throwing: the caller turns that
     * into a per-line rejection, never into a failed file.
     */
    public static function tryFromLoose(string $value): ?self
    {
        $normalised = strtolower(str_replace([' ', '-', '_', '.'], '', trim($value)));

        foreach (self::cases() as $case) {
            if ($normalised === $case->value || $normalised === strtolower(str_replace([' ', '-'], '', $case->label()))) {
                return $case;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
