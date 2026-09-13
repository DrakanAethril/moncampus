<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardSource;
use App\Enum\JobboardBtsAccess;
use App\Enum\JobboardContract;
use App\Enum\JobboardCountry;
use App\Enum\JobboardLevelSource;
use App\Enum\JobboardRemote;

/**
 * One offer, read and typed, on its way in. Nothing here is `mixed` any more: the boundary is
 * OfferPayloadParser, and no cast happens further in.
 *
 * `source` is the one field here that is not read off the payload but *resolved* from it: the URL
 * decides which site an offer belongs to, and an unknown one is created rather than refused. The
 * entity may therefore be brand new and not yet flushed - that is normal, and it is the ingestor
 * that owns the transaction it lands in.
 *
 * `firstSeenAt` is the one field the collecting agent never sends - it is the platform's own
 * stamp. Only the manual import fills it, because the legacy file carries a `date_reperage` that
 * cannot be rebuilt by collecting again.
 */
final readonly class OfferPayload
{
    /** @param array<array-key, mixed>|null $raw */
    public function __construct(
        public JobboardSource $source,
        public string $sourceRef,
        public string $url,
        public string $position,
        public string $company,
        public ?string $category,
        public JobboardContract $contract,
        public JobboardCountry $country,
        public ?string $region,
        public ?string $departement,
        public ?string $city,
        public string $level,
        public JobboardLevelSource $levelSource,
        public JobboardBtsAccess $btsAccess,
        public JobboardRemote $remote,
        public ?\DateTimeImmutable $publishedAt,
        public bool $publishedAtApprox,
        public ?string $note,
        public ?array $raw,
        public ?\DateTimeImmutable $firstSeenAt = null,
    ) {
    }

    /** The key this offer is filed under, inside one filière. */
    public function identity(): string
    {
        return $this->source->getSlug().'|'.$this->sourceRef;
    }
}
