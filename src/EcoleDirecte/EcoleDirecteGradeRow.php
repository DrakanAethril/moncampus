<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

use App\Enum\EcoleDirecteGradeState;

final readonly class EcoleDirecteGradeRow
{
    public function __construct(
        public string $label,
        public ?int $ecoleDirecteStudentId,
        public string $value,
        public string $current,
        public EcoleDirecteGradeState $state,
    ) {
    }
}
