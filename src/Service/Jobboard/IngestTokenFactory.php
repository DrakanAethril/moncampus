<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardToken;
use App\Entity\Track;
use App\Entity\User;

/**
 * Mints an ingestion key: a selector to look up, a verifier to compare, and the one string the
 * administrator is ever shown.
 *
 * The split is App\Entity\MagicLoginToken's, for the same reason: the selector is indexed and
 * looked up directly, so the lookup leaks nothing, and the verifier is only ever compared with
 * hash_equals(). Nothing readable in the database opens the API.
 */
final readonly class IngestTokenFactory
{
    /**
     * @return array{token: JobboardToken, secret: string} the secret is shown once and never stored
     */
    public function create(string $label, Track $track, ?User $createdBy): array
    {
        $selector = bin2hex(random_bytes(JobboardToken::SELECTOR_LENGTH / 2));
        $verifier = bin2hex(random_bytes(JobboardToken::VERIFIER_LENGTH / 2));

        $token = new JobboardToken($label, $track, $selector, hash('sha256', $verifier), $createdBy);

        return [
            'token' => $token,
            'secret' => JobboardToken::PREFIX.'_'.$selector.'_'.$verifier,
        ];
    }
}
