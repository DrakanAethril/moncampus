<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Program;
use App\Enum\Feature;
use App\Repository\ProgramRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The single answer to "may this person read the Livrets d'alternance of this formation from the
 * class's own menu" - the entry « Livrets d'alternance » under Section > Formation, its list and
 * the reader behind each row all ask here, so that the menu never offers what the screen refuses.
 *
 * Two halves, kept apart because they do not fail the same way:
 *
 *  - isOffered(): the formation's side. The `ufa_booklet` feature is lit, the formation runs its
 *    livret here (Program::$internshipManagementEnabled) **and** it carries the alternance
 *    modality (Modality::$isAlternance) - the same definition of « a UFA » the UFA menu lists its
 *    formations by. A formation that fails it has no such screen: 404.
 *  - isReadable(): the reader's side on top of it - a teacher of this very formation
 *    (StructureAccessChecker::isProgramTeacher(), staff bypassed as everywhere else: the
 *    administration already reads every booklet from the UFA screens, so this opens nothing new
 *    to them). A teacher of another class is refused: 403.
 *
 * What it opens is reading only. Every writing screen of the livret stays where it was, behind the
 * administration's own roles or the tutor's and the alternant's own wizard.
 *
 * Implements ResetInterface for the reason StructureNavigationExtension does: the memo below would
 * otherwise outlive the request under FrankenPHP's worker mode and hide a formation that was given
 * the alternance modality after the worker booted.
 */
class ProgramInternshipBookletAccess implements ResetInterface
{
    // One query for the whole navbar rather than a lazy load of Program::$modalities per class -
    // the menu asks this for every formation of every section, on every authenticated page.
    /** @var array<int, true>|null */
    private ?array $alternanceProgramIds = null;

    public function __construct(
        private readonly FeatureAccess $featureAccess,
        private readonly StructureAccessChecker $accessChecker,
        private readonly ProgramRepository $programRepository,
    ) {
    }

    public function isOffered(Program $program): bool
    {
        return $this->featureAccess->isEnabled(Feature::UfaBooklet)
            && $program->isInternshipManagementEnabled()
            && $this->carriesAlternance($program);
    }

    public function isReadable(Program $program): bool
    {
        return $this->isOffered($program) && $this->accessChecker->isProgramTeacher($program);
    }

    #[\Override]
    public function reset(): void
    {
        $this->alternanceProgramIds = null;
    }

    private function carriesAlternance(Program $program): bool
    {
        $this->alternanceProgramIds ??= array_fill_keys($this->programRepository->findAlternanceProgramIds(), true);

        return isset($this->alternanceProgramIds[(int) $program->getId()]);
    }
}
