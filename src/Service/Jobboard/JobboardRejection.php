<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

/**
 * Why one offer of a batch was refused.
 *
 * A refusal is always about **one line**: a malformed offer must never take the other 499 with it
 * (design/validated/jobboard.md §4.4). The code travels in the API's answer, the label is what the
 * import screen prints next to the line it rejected.
 *
 * What is deliberately absent is as telling as what is here: a missing publication date, an empty
 * city, a company reading « Non précisée » and an unknown `categorie` are all normal situations,
 * and none of them has a code.
 *
 * **Two codes were removed the day the list of sites was opened** - `unknown_source` and
 * `url_domain_mismatch`. They were the two refusals that cost real offers: a site the veille had
 * just met was refused until somebody shipped a deploy, and nothing here keeps what it refused.
 * What replaces them is a resolution, not a check (App\Service\Jobboard\JobboardSourceResolver),
 * and the only thing still refused about a link is that it is not one - `invalid_url`.
 */
enum JobboardRejection: string
{
    case MissingField = 'missing_field';
    case UnknownContract = 'unknown_contract';
    case UnknownCountry = 'unknown_country';
    case UnknownRemote = 'unknown_remote';
    case UnknownLevelSource = 'unknown_level_source';
    case UnknownBtsAccess = 'unknown_bts_access';
    case DepartementOutsideFrance = 'departement_outside_france';
    case InvalidDepartement = 'invalid_departement';
    case InvalidUrl = 'invalid_url';
    case InvalidDate = 'invalid_date';
    case PublishedInFuture = 'published_in_future';
    case RawTooLarge = 'raw_too_large';
    case DuplicateInBatch = 'duplicate_in_batch';

    public function labelKey(): string
    {
        return match ($this) {
            self::MissingField => 'jobboardRejectMissingFieldLabel',
            self::UnknownContract => 'jobboardRejectUnknownContractLabel',
            self::UnknownCountry => 'jobboardRejectUnknownCountryLabel',
            self::UnknownRemote => 'jobboardRejectUnknownRemoteLabel',
            self::UnknownLevelSource => 'jobboardRejectUnknownLevelSourceLabel',
            self::UnknownBtsAccess => 'jobboardRejectUnknownBtsAccessLabel',
            self::DepartementOutsideFrance => 'jobboardRejectDepartementOutsideFranceLabel',
            self::InvalidDepartement => 'jobboardRejectInvalidDepartementLabel',
            self::InvalidUrl => 'jobboardRejectInvalidUrlLabel',
            self::InvalidDate => 'jobboardRejectInvalidDateLabel',
            self::PublishedInFuture => 'jobboardRejectPublishedInFutureLabel',
            self::RawTooLarge => 'jobboardRejectRawTooLargeLabel',
            self::DuplicateInBatch => 'jobboardRejectDuplicateInBatchLabel',
        };
    }
}
