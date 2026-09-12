<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

/**
 * The verdict on one line of a batch, said offer by offer: what it was, what became of it, and
 * when it was refused, why. The API echoes this list; the import screen prints it.
 */
final readonly class IngestLine
{
    public function __construct(
        public int $index,
        public IngestOutcome $outcome,
        public ?string $source = null,
        public ?string $sourceRef = null,
        public ?string $position = null,
        public ?JobboardRejection $reason = null,
        public ?string $field = null,
    ) {
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        $line = [
            'index' => $this->index,
            'outcome' => $this->outcome->value,
            'source' => $this->source,
            'source_ref' => $this->sourceRef,
        ];

        if (null !== $this->reason) {
            $line['reason'] = $this->reason->value;
            $line['field'] = $this->field;
        }

        return $line;
    }
}
