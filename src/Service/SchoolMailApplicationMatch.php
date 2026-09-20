<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailMessage;
use App\Entity\JobApplication;
use App\Enum\SchoolMailApplicationEvidence;

/**
 * What App\Service\SchoolMailApplicationRecovery found, and *why* it found it.
 *
 * The reason travels with the application on purpose: this is the one place on the platform where a
 * mail is filed under a démarche nobody named, so the console and the log can say which send the
 * mail quoted and by what. "Linked automatically", with no evidence beside it, would be
 * indistinguishable from a guess.
 */
final readonly class SchoolMailApplicationMatch
{
    public function __construct(
        public JobApplication $application,
        /** The send the incoming mail quotes - the démarche above is that send's own. */
        public EmailMessage $quotedSend,
        /** The Message-ID or the address that was found, as it appears in the mail. */
        public string $evidence,
        public SchoolMailApplicationEvidence $kind,
    ) {
    }

    /** Display text - what the repair pass prints beside each mail it files. Logs read the two fields above. */
    public function describe(): string
    {
        return match ($this->kind) {
            SchoolMailApplicationEvidence::MessageId => sprintf('Message-ID cité %s', $this->evidence),
            SchoolMailApplicationEvidence::Address => sprintf('adresse citée %s', $this->evidence),
        };
    }
}
