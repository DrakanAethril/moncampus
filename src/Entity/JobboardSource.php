<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JobboardSourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One site the veille collects from - a **row**, where it used to be a case of an enum.
 *
 * The list was closed because the ingestion refused an offer whose URL did not belong to the source
 * it declared, and that check needed a table `source → domaines`. Adding a site was a line of code,
 * a pull request and a deploy; the sites turn over faster than that, and the price was paid in the
 * only currency that matters here - offers refused with `unknown_source` and lost, because nothing
 * on this platform stores what it refused.
 *
 * So the table moved into the database, and with it the check changed direction. It is no longer
 * « l'URL appartient-elle à la source déclarée ? » (a refusal) but « à quelle source appartient
 * cette URL ? » (a resolution, App\Service\Jobboard\JobboardSourceResolver). **The URL is the
 * truth, the declared name is a hint**, which is strictly stronger than the old check: a batch
 * labelled `hellowrk` used to be refused offer by offer, and now files under HelloWork because the
 * host says so.
 *
 * A source nobody declared is therefore created on first sight, from the URL's domain. That is the
 * point of `discoveredByAgent`: the screen must be able to say which rows a human decided and which
 * ones simply turned up, because only the first kind carries a `refRule` anybody verified.
 */
#[ORM\Entity(repositoryClass: JobboardSourceRepository::class)]
#[ORM\Table(name: 'jobboard_source')]
#[ORM\UniqueConstraint(name: 'uniq_jobboard_source_slug', columns: ['slug'])]
class JobboardSource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // The stable identifier: what the API echoes, what the query string of a filter carries, and
    // what `findOneBy` looks a declared name up on. The label may be corrected at will; this may
    // not, which is why renaming a source on the screen never touches it.
    #[ORM\Column(length: 32)]
    private string $slug;

    #[ORM\Column(length: 64)]
    private string $label;

    /**
     * The domains an offer URL may sit on, compared on the host's suffix - so every subdomain of a
     * listed domain belongs to it, `candidat.francetravail.fr` being France Travail's real host.
     *
     * A list rather than a single value because a site genuinely has several (`francetravail.fr`
     * and `pole-emploi.fr` are the same board), and because a site that moves gets its new domain
     * added rather than exchanged: the offers already filed keep pointing at the old one.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $domains = [];

    // How `source_ref` is built for this site, in French, because it is read by the collecting
    // agent from Configuration > Jobboard > API. Null on a source nobody has looked at yet - and
    // the sheet says so rather than inventing one.
    #[ORM\Column(name: 'ref_rule', length: 255, nullable: true)]
    private ?string $refRule = null;

    // The prefix this site's identifiers carried in the legacy `offres.json` (`hw-83313525`). Only
    // the manual import reads it; a site discovered since has none and needs none.
    #[ORM\Column(name: 'legacy_prefix', length: 8, nullable: true)]
    private ?string $legacyPrefix = null;

    #[ORM\Column(name: 'discovered_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $discoveredAt;

    #[ORM\Column(name: 'discovered_by_agent')]
    private bool $discoveredByAgent = false;

    /** @param list<string> $domains */
    public function __construct(string $slug, string $label, array $domains = [], ?\DateTimeImmutable $discoveredAt = null)
    {
        $this->slug = $slug;
        $this->label = $label;
        $this->discoveredAt = $discoveredAt ?? new \DateTimeImmutable();
        $this->setDomains($domains);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** @return list<string> */
    public function getDomains(): array
    {
        return $this->domains;
    }

    /**
     * Normalised on the way in, and that is what makes the suffix match work at all: a domain typed
     * as `https://www.example.com/jobs` would never equal the host `example.com`, and the screen
     * would look right while resolving nothing. Whatever is pasted comes down to `example.com`.
     *
     * The `array-key` is deliberate: this is fed from a form field and from a JSON column, neither
     * of which promises a list, so `array_values()` here is normalisation and not ceremony.
     *
     * @param array<array-key, string> $domains
     */
    public function setDomains(array $domains): static
    {
        $this->domains = array_values(array_unique(array_filter(array_map(
            self::normaliseDomain(...),
            $domains,
        ))));

        return $this;
    }

    public function addDomain(string $domain): static
    {
        return $this->setDomains([...$this->domains, $domain]);
    }

    /** Scheme, path, port and a leading `www.` all dropped: what is left is a host to compare. */
    private static function normaliseDomain(string $domain): string
    {
        $value = strtolower(trim($domain));
        $value = (string) preg_replace('~^[a-z0-9+.-]*://~', '', $value);
        $value = (string) preg_replace('~[/?#].*$~', '', $value);
        $value = (string) preg_replace('~:\d+$~', '', $value);
        $value = trim($value, " \t\n\r\0\x0B.");

        return str_starts_with($value, 'www.') ? substr($value, 4) : $value;
    }

    /** Does this host belong here? Suffix match, so a subdomain belongs to its domain. */
    public function covers(string $host): bool
    {
        $host = strtolower($host);

        foreach ($this->domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    public function getRefRule(): ?string
    {
        return $this->refRule;
    }

    public function setRefRule(?string $refRule): static
    {
        $this->refRule = $refRule;

        return $this;
    }

    public function getLegacyPrefix(): ?string
    {
        return $this->legacyPrefix;
    }

    public function setLegacyPrefix(?string $legacyPrefix): static
    {
        $this->legacyPrefix = $legacyPrefix;

        return $this;
    }

    public function getDiscoveredAt(): \DateTimeImmutable
    {
        return $this->discoveredAt;
    }

    public function isDiscoveredByAgent(): bool
    {
        return $this->discoveredByAgent;
    }

    public function markDiscoveredByAgent(): static
    {
        $this->discoveredByAgent = true;

        return $this;
    }
}
