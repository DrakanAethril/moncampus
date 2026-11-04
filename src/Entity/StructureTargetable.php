<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\Collection;

/**
 * Something opened to students by **targeting** the structure: one or several Sections, Tracks
 * (filières) and Programs (formations), combinable (design/validated/quiz-tournament.md §14).
 *
 * Implemented through StructureTargetsTrait; who a targeting reaches is decided by
 * App\Service\StructureTargeting\StructureTargetRule and nowhere else. An empty targeting reaches
 * nobody.
 */
interface StructureTargetable
{
    /** @return Collection<int, Section> */
    public function getTargetSections(): Collection;

    /** @return Collection<int, Track> */
    public function getTargetTracks(): Collection;

    /** @return Collection<int, Program> */
    public function getTargetPrograms(): Collection;
}
