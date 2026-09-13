<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardSource;
use App\Enum\JobboardLearningKind;

/**
 * One gesture the resolution made, before anything decides whether to keep it.
 *
 * It exists as a value object rather than straight as a row because the dry run of the import needs
 * exactly the same list and must write nothing at all: the analysis screen announces what the real
 * pass will learn, and a preview that persisted its announcement would be announcing a pass that
 * has already happened.
 */
final readonly class SourceLearning
{
    public function __construct(
        public JobboardLearningKind $kind,
        public JobboardSource $source,
        public string $declared,
        public string $domain,
    ) {
    }

    /** The same gesture twice is one gesture: what identifies it is what it changed. */
    public function identity(): string
    {
        return $this->kind->value.'|'.$this->source->getSlug().'|'.$this->domain;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'source' => $this->source->getSlug(),
            'declared' => $this->declared,
            'domain' => $this->domain,
        ];
    }
}
