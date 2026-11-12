<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Entity\Program;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Security\StructureAccessChecker;

/**
 * The classes one may create clouds for and whose clouds one's list shows - read by the list, the
 * form and the Claude connector: the classes actually taught, and every active one for staff who
 * teach none (the rule the class picker of « Animer la classe » reads).
 */
class WordCloudPrograms
{
    public function __construct(
        private readonly ProgramRepository $programs,
        private readonly StructureAccessChecker $accessChecker,
    ) {
    }

    /** @return list<Program> */
    public function piloted(User $user): array
    {
        $taught = $this->programs->findAllForTeacher($user);

        if ([] !== $taught) {
            return $taught;
        }

        return $this->accessChecker->isStaff() ? $this->programs->findActiveForNav($user) : [];
    }
}
