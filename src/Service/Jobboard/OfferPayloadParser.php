<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardSource;
use App\Enum\JobboardBtsAccess;
use App\Enum\JobboardContract;
use App\Enum\JobboardCountry;
use App\Enum\JobboardLevelSource;
use App\Enum\JobboardRemote;
use Symfony\Component\Clock\ClockInterface;

/**
 * The boundary: one untyped array in, one OfferPayload out, or a rejection naming its reason.
 *
 * **Better a clean refusal than a guessed value.** What is refused is the list of
 * design/validated/jobboard.md §4.4 and nothing more; what is *not* refused matters just as much -
 * an absent publication date, an empty city, a company reading « Non précisée » and an unknown
 * `categorie` are normal, and a parser that tightened any of them would silently drop real offers.
 *
 * The same parser serves the API and the manual import. The import passes `legacy: true`, which
 * changes exactly two things: the old field names are accepted as aliases, and `date_reperage`
 * becomes the offer's first-seen stamp. The rules themselves are not duplicated - writing them
 * twice is the surest way to see the two doors disagree.
 *
 * **The source is no longer among the refusals.** It used to be: a closed enum, and an offer from a
 * site nobody had added yet was refused with `unknown_source` and lost, because nothing here stores
 * what it refuses. The sites turn over faster than a deploy, so the question was turned around -
 * the URL says which source an offer belongs to, and an unknown one is created rather than
 * refused (App\Service\Jobboard\JobboardSourceResolver). What survives of the old check is
 * stronger than it was: a URL that is not a link at all is still refused, and a host that belongs
 * to a known site wins over whatever name the agent declared.
 */
final readonly class OfferPayloadParser
{
    /** A `brut` bigger than this is refused on its own line rather than making the request unreadable. */
    public const int MAX_RAW_BYTES = 65536;

    private const string DEPARTEMENT_PATTERN = '/^(?:97[1-6]|2A|2B|0[1-9]|[1-8][0-9]|9[0-5])$/';

    public function __construct(
        private ClockInterface $clock,
        private JobboardSourceResolver $sources,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     * @param bool                    $learn whether resolving an unknown site may create it - false
     *                                       for the import's dry run, which announces what the real
     *                                       pass will do and writes nothing
     *
     * @throws OfferRejectedException
     */
    public function parse(array $data, bool $legacy = false, bool $learn = true): OfferPayload
    {
        $declared = $this->requiredString($data, 'source');
        $url = $this->requiredString($data, 'url', 1000);
        $host = JobboardSourceResolver::hostOf($url);

        if (null === $host) {
            throw new OfferRejectedException(JobboardRejection::InvalidUrl, 'url');
        }

        $source = $learn ? $this->sources->resolve($declared, $host) : $this->sources->preview($declared, $host);

        $sourceRef = $legacy
            ? $this->legacyRef($data, $source)
            : $this->requiredString($data, 'source_ref');

        $contract = JobboardContract::tryFromLoose($this->requiredString($data, 'contrat'));
        if (null === $contract) {
            throw new OfferRejectedException(JobboardRejection::UnknownContract, 'contrat');
        }

        // `poste` is required of every contract but one: a company that takes unsolicited
        // applications publishes no job title, so there is nothing for the agent to read. The
        // contract itself carries the fallback, and a title the agent *did* find always wins.
        $position = $this->optionalString($data, 'poste', 255) ?? $contract->defaultPosition();
        if (null === $position) {
            throw new OfferRejectedException(JobboardRejection::MissingField, 'poste');
        }

        $country = JobboardCountry::tryFromLoose($this->requiredString($data, 'pays'));
        if (null === $country) {
            throw new OfferRejectedException(JobboardRejection::UnknownCountry, 'pays');
        }

        // `niveau_source` absent is its own refusal in the contract, and rightly so: the field
        // exists to keep an uncertainty visible, and a default would hide exactly what it measures.
        $rawLevelSource = $this->optionalString($data, 'niveau_source');
        if (null === $rawLevelSource) {
            throw new OfferRejectedException(JobboardRejection::MissingField, 'niveau_source');
        }

        $levelSource = JobboardLevelSource::tryFromLoose($rawLevelSource);
        if (null === $levelSource) {
            throw new OfferRejectedException(JobboardRejection::UnknownLevelSource, 'niveau_source');
        }

        $btsAccess = JobboardBtsAccess::tryFromLoose($this->requiredString($data, 'acces_bts'));
        if (null === $btsAccess) {
            throw new OfferRejectedException(JobboardRejection::UnknownBtsAccess, 'acces_bts');
        }

        $remote = JobboardRemote::tryFromLoose($this->requiredString($data, 'teletravail'));
        if (null === $remote) {
            throw new OfferRejectedException(JobboardRejection::UnknownRemote, 'teletravail');
        }

        $departement = $this->optionalString($data, 'departement');
        if (null !== $departement) {
            if (JobboardCountry::France !== $country) {
                throw new OfferRejectedException(JobboardRejection::DepartementOutsideFrance, 'departement');
            }

            if (1 !== preg_match(self::DEPARTEMENT_PATTERN, $departement)) {
                throw new OfferRejectedException(JobboardRejection::InvalidDepartement, 'departement');
            }
        }

        $publishedAt = $this->date($data, 'date_publication');
        if (null !== $publishedAt && $publishedAt > $this->clock->now()->setTime(0, 0)) {
            throw new OfferRejectedException(JobboardRejection::PublishedInFuture, 'date_publication');
        }

        return new OfferPayload(
            source: $source,
            sourceRef: $sourceRef,
            url: $url,
            position: $position,
            company: $this->requiredString($data, 'entreprise'),
            category: $this->category($data),
            contract: $contract,
            country: $country,
            region: $this->optionalString($data, 'region', 120),
            departement: $departement,
            city: $this->optionalString($data, 'ville', 120),
            level: $this->requiredString($data, 'niveau', 120),
            levelSource: $levelSource,
            btsAccess: $btsAccess,
            remote: $remote,
            publishedAt: $publishedAt,
            publishedAtApprox: $this->approx($data, $legacy),
            note: $this->optionalString($data, 'note', 500),
            raw: $this->raw($data),
            firstSeenAt: $legacy ? $this->date($data, 'date_reperage') : null,
        );
    }

    /**
     * The legacy file has no `source_ref`: it carries `hw-83313525`, the prefix being the source.
     * This is the rule the published instructions quote back to the agent - see
     * App\Entity\JobboardSource::getRefRule() and design/validated/jobboard.md §8.2, where it is
     * also said why the URL is not used instead.
     *
     * A source discovered since the list was opened carries no prefix, and the id is then kept
     * whole. Guessing one - stripping any two letters and a dash - would silently merge two offers
     * whose real identifiers happen to start that way.
     *
     * @param array<array-key, mixed> $data
     */
    private function legacyRef(array $data, JobboardSource $source): string
    {
        $id = $this->requiredString($data, 'id');
        $legacyPrefix = $source->getLegacyPrefix();

        if (null === $legacyPrefix || '' === $legacyPrefix) {
            return $id;
        }

        $prefix = $legacyPrefix.'-';

        return str_starts_with($id, $prefix) ? substr($id, \strlen($prefix)) : $id;
    }

    /** @param array<array-key, mixed> $data */
    private function category(array $data): ?string
    {
        $value = $this->optionalString($data, 'categorie', 32);

        // Free text, lowercased and never refused: the data contract lists what must be rejected,
        // and `categorie` is deliberately not in it (design/validated/jobboard.md §3.3).
        return null === $value ? null : mb_strtolower($value);
    }

    /** @param array<array-key, mixed> $data */
    private function approx(array $data, bool $legacy): bool
    {
        $value = $data[$legacy ? 'date_approx' : 'date_publication_approx'] ?? null;

        if (\is_bool($value)) {
            return $value;
        }

        // A date nobody dated is approximate by construction: saying otherwise would promise a
        // precision the advert never gave.
        return !\is_string($value) ? true : \in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>|null
     */
    private function raw(array $data): ?array
    {
        $value = $data['brut'] ?? null;

        if (!\is_array($value)) {
            return null;
        }

        $encoded = json_encode($value);

        if (false === $encoded || \strlen($encoded) > self::MAX_RAW_BYTES) {
            throw new OfferRejectedException(JobboardRejection::RawTooLarge, 'brut');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private function date(array $data, string $key): ?\DateTimeImmutable
    {
        $value = $this->optionalString($data, $key);

        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        if (false === $date) {
            throw new OfferRejectedException(JobboardRejection::InvalidDate, $key);
        }

        return $date;
    }

    /** @param array<array-key, mixed> $data */
    private function requiredString(array $data, string $key, int $maxLength = 255): string
    {
        $value = $this->optionalString($data, $key, $maxLength);

        if (null === $value) {
            throw new OfferRejectedException(JobboardRejection::MissingField, $key);
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private function optionalString(array $data, string $key, int $maxLength = 1000): ?string
    {
        $value = $data[$key] ?? null;

        if (\is_int($value) || \is_float($value)) {
            $value = (string) $value;
        }

        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        // Truncated rather than refused: a title three characters too long is not a reason to lose
        // an offer, and the column is the only thing that cares.
        return '' === $value ? null : mb_substr($value, 0, $maxLength);
    }
}
