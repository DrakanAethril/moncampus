<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\SkillGroup;

/**
 * One activity-type as the booklet reads it today: a competency group of the formation, its rank
 * (the « Activité-type n » printed), its code and its competences in order.
 */
final readonly class EcfActivityType
{
    /** @param list<string> $competences */
    public function __construct(
        public int $number,
        public string $code,
        public string $label,
        public array $competences,
        public SkillGroup $group,
    ) {
    }
}
