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
 */
enum JobboardRejection: string
{
    case MissingField = 'missing_field';
    case UnknownSource = 'unknown_source';
    case UnknownContract = 'unknown_contract';
    case UnknownCountry = 'unknown_country';
    case UnknownRemote = 'unknown_remote';
    case UnknownLevelSource = 'unknown_level_source';
    case UnknownBtsAccess = 'unknown_bts_access';
    case DepartementOutsideFrance = 'departement_outside_france';
    case InvalidDepartement = 'invalid_departement';
    case UrlDomainMismatch = 'url_domain_mismatch';
    case InvalidDate = 'invalid_date';
    case PublishedInFuture = 'published_in_future';
    case RawTooLarge = 'raw_too_large';
    case DuplicateInBatch = 'duplicate_in_batch';

    public function labelKey(): string
    {
        return match ($this) {
            self::MissingField => 'jobboardRejectMissingFieldLabel',
            self::UnknownSource => 'jobboardRejectUnknownSourceLabel',
            self::UnknownContract => 'jobboardRejectUnknownContractLabel',
            self::UnknownCountry => 'jobboardRejectUnknownCountryLabel',
            self::UnknownRemote => 'jobboardRejectUnknownRemoteLabel',
            self::UnknownLevelSource => 'jobboardRejectUnknownLevelSourceLabel',
            self::UnknownBtsAccess => 'jobboardRejectUnknownBtsAccessLabel',
            self::DepartementOutsideFrance => 'jobboardRejectDepartementOutsideFranceLabel',
            self::InvalidDepartement => 'jobboardRejectInvalidDepartementLabel',
            self::UrlDomainMismatch => 'jobboardRejectUrlDomainMismatchLabel',
            self::InvalidDate => 'jobboardRejectInvalidDateLabel',
            self::PublishedInFuture => 'jobboardRejectPublishedInFutureLabel',
            self::RawTooLarge => 'jobboardRejectRawTooLargeLabel',
            self::DuplicateInBatch => 'jobboardRejectDuplicateInBatchLabel',
        };
    }
}
