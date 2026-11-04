<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The three ManyToMany of a StructureTargetable: Sections, Tracks and Programs.
 *
 * The join tables carry no explicit name on purpose: Doctrine names them after the *using* entity
 * (`quiz_tournament_section`, `serious_game_track`…), which is what lets one trait serve several
 * entities. Their foreign keys cascade on delete - removing a Section removes it from the targetings,
 * nothing else.
 *
 * The using class calls initStructureTargets() from its constructor.
 */
trait StructureTargetsTrait
{
    /** @var Collection<int, Section> */
    #[ORM\ManyToMany(targetEntity: Section::class)]
    private Collection $targetSections;

    /** @var Collection<int, Track> */
    #[ORM\ManyToMany(targetEntity: Track::class)]
    private Collection $targetTracks;

    /** @var Collection<int, Program> */
    #[ORM\ManyToMany(targetEntity: Program::class)]
    private Collection $targetPrograms;

    private function initStructureTargets(): void
    {
        $this->targetSections = new ArrayCollection();
        $this->targetTracks = new ArrayCollection();
        $this->targetPrograms = new ArrayCollection();
    }

    /** @return Collection<int, Section> */
    public function getTargetSections(): Collection
    {
        return $this->targetSections;
    }

    public function addTargetSection(Section $section): static
    {
        if (!$this->targetSections->contains($section)) {
            $this->targetSections->add($section);
        }

        return $this;
    }

    public function removeTargetSection(Section $section): static
    {
        $this->targetSections->removeElement($section);

        return $this;
    }

    /** @return Collection<int, Track> */
    public function getTargetTracks(): Collection
    {
        return $this->targetTracks;
    }

    public function addTargetTrack(Track $track): static
    {
        if (!$this->targetTracks->contains($track)) {
            $this->targetTracks->add($track);
        }

        return $this;
    }

    public function removeTargetTrack(Track $track): static
    {
        $this->targetTracks->removeElement($track);

        return $this;
    }

    /** @return Collection<int, Program> */
    public function getTargetPrograms(): Collection
    {
        return $this->targetPrograms;
    }

    public function addTargetProgram(Program $program): static
    {
        if (!$this->targetPrograms->contains($program)) {
            $this->targetPrograms->add($program);
        }

        return $this;
    }

    public function removeTargetProgram(Program $program): static
    {
        $this->targetPrograms->removeElement($program);

        return $this;
    }

    public function hasStructureTargets(): bool
    {
        return !$this->targetSections->isEmpty() || !$this->targetTracks->isEmpty() || !$this->targetPrograms->isEmpty();
    }
}
