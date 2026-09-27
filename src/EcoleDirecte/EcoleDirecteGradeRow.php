<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

use App\Enum\EcoleDirecteGradeState;

/**
 * One line of a grade send's preview. `$studentId` is the MonCampus student, `$ecoleDirecteLabel`
 * the École Directe student the grade goes to, `$linkedLabel` the remembered École Directe identity
 * when there is one - set even when that student is not in this class.
 */
final readonly class EcoleDirecteGradeRow
{
    public function __construct(
        public string $label,
        public ?int $ecoleDirecteStudentId,
        public string $value,
        public string $current,
        public EcoleDirecteGradeState $state,
        public int $studentId = 0,
        public string $ecoleDirecteLabel = '',
        public ?string $linkedLabel = null,
    ) {
    }
}
