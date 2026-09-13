<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\JobboardBtsAccess;
use App\Enum\JobboardContract;
use App\Enum\JobboardCountry;
use App\Enum\JobboardLevelSource;
use App\Enum\JobboardRemote;
use App\Repository\JobboardOfferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One job offer, **inside one filière**.
 *
 * The identity is the triple (track, source, source_ref), and that is a decision rather than a
 * convenience: the same advert pushed by two filières' agents makes two rows, each with its own
 * first-seen date. One row shared between filières would make `premiere_vue` mean "the first time
 * *anybody* saw it", which is not the question the screen answers.
 *
 * **`premiere_vue` is written once and never moves.** No site can give it back: if it is lost, it is
 * lost for good, and it is the only field here that cannot be rebuilt by collecting again. There is
 * deliberately no setter for it.
 *
 * Everything else follows the asymmetry of App\Service\Jobboard\OfferIngestor: a second, more
 * thorough pass may enrich a row, a hurried pass may never degrade what was verified.
 */
#[ORM\Entity(repositoryClass: JobboardOfferRepository::class)]
#[ORM\Table(name: 'jobboard_offer')]
#[ORM\UniqueConstraint(name: 'uniq_jobboard_offer_identity', columns: ['track_id', 'source_id', 'source_ref'])]
#[ORM\Index(name: 'idx_jobboard_offer_listing', columns: ['track_id', 'closed_at', 'first_seen_at', 'id'])]
#[ORM\Index(name: 'idx_jobboard_offer_departement', columns: ['track_id', 'departement'])]
#[ORM\Index(name: 'idx_jobboard_offer_contract', columns: ['track_id', 'contract'])]
class JobboardOffer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // The filière, decided by the ingestion token and by nothing else - never deduced from the
    // advert's content (design/validated/jobboard.md §3.1).
    #[ORM\ManyToOne(targetEntity: Track::class)]
    #[ORM\JoinColumn(name: 'track_id', nullable: false, onDelete: 'CASCADE')]
    private Track $track;

    // A row rather than an enum case since the list of sites was opened: the veille meets new
    // boards faster than a deploy could add them, and a source nobody declared is created on
    // first sight (App\Service\Jobboard\JobboardSourceResolver). It is still part of this
    // offer's identity, and still never rewritten by a later pass.
    #[ORM\ManyToOne(targetEntity: JobboardSource::class)]
    #[ORM\JoinColumn(name: 'source_id', nullable: false, onDelete: 'RESTRICT')]
    private JobboardSource $source;

    #[ORM\Column(name: 'source_ref', length: 190)]
    private string $sourceRef;

    #[ORM\Column(length: 1000)]
    private string $url = '';

    #[ORM\Column(length: 255)]
    private string $position = '';

    #[ORM\Column(length: 255)]
    private string $company = '';

    // Free text, not an enum, and that is a reading of the data contract itself: it lists what must
    // be refused and `categorie` is not in it. It is the agent's own classification, and "sisr"
    // will mean nothing the day the MCO veille pushes its first batch.
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(length: 16, enumType: JobboardContract::class)]
    private JobboardContract $contract;

    #[ORM\Column(length: 16, enumType: JobboardCountry::class)]
    private JobboardCountry $country;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $region = null;

    // A string, never an integer: "01" must stay "01", and 2A/2B are not numbers at all.
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $departement = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 120)]
    private string $level = '';

    #[ORM\Column(name: 'level_source', length: 16, enumType: JobboardLevelSource::class)]
    private JobboardLevelSource $levelSource;

    #[ORM\Column(name: 'bts_access', length: 16, enumType: JobboardBtsAccess::class)]
    private JobboardBtsAccess $btsAccess;

    #[ORM\Column(length: 16, enumType: JobboardRemote::class)]
    private JobboardRemote $remote;

    #[ORM\Column(name: 'published_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'published_at_approx')]
    private bool $publishedAtApprox = true;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note = null;

    /** @var array<array-key, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $raw = null;

    // The day the veille brought this advert back for the first time, and the column the board is
    // ordered on. Written once, never moved - see the class docblock.
    #[ORM\Column(name: 'first_seen_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    // An offer taken off the site is closed, never deleted. How long an advert stays online is an
    // information in itself, and nothing here ever erases a row.
    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    // The same advert published on two sites arrives as two rows and stays as two rows. This column
    // is where a future rapprochement would designate the canonical one; nothing writes it and
    // nothing reads it today, deliberately (design/validated/jobboard.md §3.6): an automatic merge
    // that hid a line would eventually hide the wrong one.
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'canonical_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $canonical = null;

    public function __construct(
        Track $track,
        JobboardSource $source,
        string $sourceRef,
        \DateTimeImmutable $firstSeenAt,
    ) {
        $this->track = $track;
        $this->source = $source;
        $this->sourceRef = $sourceRef;
        $this->firstSeenAt = $firstSeenAt;
        $this->lastSeenAt = $firstSeenAt;
        $this->contract = JobboardContract::Autre;
        $this->country = JobboardCountry::France;
        $this->levelSource = JobboardLevelSource::Estime;
        $this->btsAccess = JobboardBtsAccess::Accessible;
        $this->remote = JobboardRemote::NonPrecise;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getSource(): JobboardSource
    {
        return $this->source;
    }

    public function getSourceRef(): string
    {
        return $this->sourceRef;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    public function setPosition(string $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getCompany(): string
    {
        return $this->company;
    }

    public function setCompany(string $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getContract(): JobboardContract
    {
        return $this->contract;
    }

    public function setContract(JobboardContract $contract): static
    {
        $this->contract = $contract;

        return $this;
    }

    public function getCountry(): JobboardCountry
    {
        return $this->country;
    }

    public function setCountry(JobboardCountry $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $region;

        return $this;
    }

    public function getDepartement(): ?string
    {
        return $this->departement;
    }

    public function setDepartement(?string $departement): static
    {
        $this->departement = $departement;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getLevelSource(): JobboardLevelSource
    {
        return $this->levelSource;
    }

    public function setLevelSource(JobboardLevelSource $levelSource): static
    {
        $this->levelSource = $levelSource;

        return $this;
    }

    public function getBtsAccess(): JobboardBtsAccess
    {
        return $this->btsAccess;
    }

    public function setBtsAccess(JobboardBtsAccess $btsAccess): static
    {
        $this->btsAccess = $btsAccess;

        return $this;
    }

    public function getRemote(): JobboardRemote
    {
        return $this->remote;
    }

    public function setRemote(JobboardRemote $remote): static
    {
        $this->remote = $remote;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function isPublishedAtApprox(): bool
    {
        return $this->publishedAtApprox;
    }

    /**
     * The publication date and its precision move together - two fields that must never be able to
     * disagree, which is why there is one setter and not two.
     *
     * Neither of them orders the board: a publication date is what the advert says about itself,
     * and half the sources say nothing at all. The order is `first_seen_at`.
     */
    public function setPublication(?\DateTimeImmutable $publishedAt, bool $approx): static
    {
        $this->publishedAt = $publishedAt;
        $this->publishedAtApprox = $approx;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    /** @return array<array-key, mixed>|null */
    public function getRaw(): ?array
    {
        return $this->raw;
    }

    /** @param array<array-key, mixed>|null $raw */
    public function setRaw(?array $raw): static
    {
        $this->raw = $raw;

        return $this;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function markSeen(\DateTimeImmutable $at): static
    {
        $this->lastSeenAt = $at;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function isClosed(): bool
    {
        return null !== $this->closedAt;
    }

    /**
     * Closing is idempotent and one-way here: a row already closed keeps the date it was closed on,
     * and an offer that reappears in a later batch is **not** reopened by that alone.
     */
    public function close(\DateTimeImmutable $at): static
    {
        $this->closedAt ??= $at;

        return $this;
    }

    public function getCanonical(): ?self
    {
        return $this->canonical;
    }
}
