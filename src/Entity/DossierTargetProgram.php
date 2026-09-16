<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DossierTargetProgramRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One formation a dossier is asked of, and — optionally — the options it is narrowed to.
 *
 * A row rather than a join table, and that is the whole of this class: **the narrowing belongs to
 * the pair, not to the dossier**. « SIO-2, les SLAM » and « SIO-1, tout le monde » is one dossier
 * with two targets, and a flat list of options beside a flat list of classes could not say it - it
 * would either empty the class that does not offer the option, or quietly widen the one that does.
 *
 * **No option means the whole class**, and it is not a special case anywhere: an empty collection is
 * read as « pas de restriction », which is also what it means the moment a formation's options are
 * reorganised. A target that named an option the formation no longer has would otherwise become a
 * dossier addressed to nobody, silently.
 *
 * The audience is resolved at read time, like an Assignment's, by
 * App\Service\Dossier\DossierTargetResolver: a student who joins the class - or who picks up the
 * option in November - is a cible from that day.
 */
#[ORM\Entity(repositoryClass: DossierTargetProgramRepository::class)]
#[ORM\Table(name: 'dossier_target_program')]
#[ORM\UniqueConstraint(name: 'dossier_target_program_unique', columns: ['dossier_id', 'program_id'])]
class DossierTargetProgram
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Dossier::class, inversedBy: 'targetPrograms')]
    #[ORM\JoinColumn(name: 'dossier_id', nullable: false, onDelete: 'CASCADE')]
    private ?Dossier $dossier = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false, onDelete: 'CASCADE')]
    private ?Program $program = null;

    /**
     * Empty means the whole class. A non-empty set means « only the students carrying one of
     * these » - the union, never the intersection, exactly like an Assignment's option audience.
     *
     * @var Collection<int, Option>
     */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'dossier_target_option')]
    #[ORM\JoinColumn(name: 'target_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'option_id', onDelete: 'CASCADE')]
    private Collection $options;

    public function __construct(Dossier $dossier, Program $program)
    {
        $this->dossier = $dossier;
        $this->program = $program;
        $this->options = new ArrayCollection();

        $dossier->addTarget($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): ?Dossier
    {
        return $this->dossier;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    /** @return Collection<int, Option> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(Option $option): static
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
        }

        return $this;
    }

    public function removeOption(Option $option): static
    {
        $this->options->removeElement($option);

        return $this;
    }

    /** Is the whole class asked, or only part of it? */
    public function isWholeClass(): bool
    {
        return $this->options->isEmpty();
    }

    /**
     * How the target reads on a screen: « SIO-2 » for a whole class, « SIO-2 (SLAM) » once it is
     * narrowed.
     *
     * The option is named rather than left implicit because the alternative is a « Cibles: SIO-2 »
     * that reaches nine students out of twenty-two and says nothing about it. It is not an effectif
     * - the handoff's rule against those stands - it is which part of the class is being asked.
     */
    public function label(): string
    {
        $name = $this->program?->getDisplayShortName() ?? '';

        if ($this->isWholeClass()) {
            return $name;
        }

        return \sprintf('%s (%s)', $name, implode(', ', array_map(
            static fn (Option $option): string => $option->getName(),
            $this->options->toArray(),
        )));
    }
}
