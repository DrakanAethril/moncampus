<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GameTrack;
use App\Repository\OptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A specialization students can follow across one or more Programs (e.g. SLAM, SISR, Latin).
 */
#[ORM\Entity(repositoryClass: OptionRepository::class)]
#[ORM\Table(name: '`option`')]
class Option extends AbstractStructureNode
{
    #[ORM\Column(name: 'short_name', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $shortName;

    // Same purpose as LessonType::$agendaColor (a hex string driving a UI swatch) - not named
    // "agendaColor" here since it started out only for badges (templates/program/_user_card.html.twig)
    // but is now also used to color LessonSession timetable events when exactly one Option is set
    // (see LessonSessionEventFormatter::backgroundColor()).
    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private string $color;

    /** @var Collection<int, Program> */
    #[ORM\ManyToMany(targetEntity: Program::class, inversedBy: 'options')]
    #[ORM\JoinTable(name: 'option_program')]
    private Collection $programs;

    /**
     * The campus game's filière, when this option *is* one.
     *
     * A formation is not a filière: BTS SIO holds SLAM and SISR side by side in the same class, and
     * which one a student belongs to is their **option**, not their formation. So the game's
     * univers - the wording of the six levels, and the catalogue a pseudonym is drawn from - hangs
     * here rather than on App\Entity\Program, and App\Service\Game\GameTrackResolver reads it
     * through App\Entity\ProgramStudentOption, one student at a time.
     *
     * Null on every option that is not a filière - a group, a bilingual track, a mini-entreprise -
     * which is nearly all of them. `Program::$gameTrack` remains the answer for a formation whose
     * whole class is one filière and which therefore splits into no option at all.
     */
    #[ORM\Column(name: 'game_track', length: 10, nullable: true, enumType: GameTrack::class)]
    private ?GameTrack $gameTrack = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    public function __construct(string $name, string $shortName, string $color)
    {
        parent::__construct($name);
        $this->shortName = $shortName;
        $this->color = $color;
        $this->programs = new ArrayCollection();
    }

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function setShortName(string $shortName): static
    {
        $this->shortName = $shortName;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /** @return Collection<int, Program> */
    public function getPrograms(): Collection
    {
        return $this->programs;
    }

    public function addProgram(Program $program): static
    {
        if (!$this->programs->contains($program)) {
            $this->programs->add($program);
            $program->getOptions()->add($this);
        }

        return $this;
    }

    public function removeProgram(Program $program): static
    {
        if ($this->programs->removeElement($program)) {
            $program->getOptions()->removeElement($this);
        }

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getGameTrack(): ?GameTrack
    {
        return $this->gameTrack;
    }

    public function setGameTrack(?GameTrack $gameTrack): static
    {
        $this->gameTrack = $gameTrack;

        return $this;
    }
}
