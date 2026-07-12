<?php

namespace App\Entity;

use App\Enum\AssignmentAudienceType;
use App\Repository\AssignmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A generic "place to submit work" - see design/validated/assignment-submission-box.md. Not tied
 * to a LessonSession (part A optionally links to one from its travail avant/après slots, but
 * Assignment itself has no idea it's being used that way). Hard-deleted like LessonSession (no
 * inactiveDate lifecycle) - AuditableTrait is used only for createdBy/lastUpdatedBy tracking,
 * same as InternshipProgramInfo.
 */
#[ORM\Entity(repositoryClass: AssignmentRepository::class)]
#[ORM\Table(name: 'assignment')]
class Assignment
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'due_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    #[ORM\Column(name: 'audience_type', length: 20, enumType: AssignmentAudienceType::class)]
    #[Assert\NotNull]
    private ?AssignmentAudienceType $audienceType = null;

    // Populated only when $audienceType is Option - cleared by the controller otherwise. A
    // student is in the audience if they hold ANY of the selected options (union, not
    // intersection) - see App\Service\AssignmentAudienceResolver.
    /** @var Collection<int, Option> */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'assignment_option')]
    private Collection $options;

    // Populated only when $audienceType is Manual - cleared by the controller otherwise. Same
    // unidirectional ManyToMany shape as Program::$students (no inverse side on User).
    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'assignment_manual_recipient')]
    private Collection $manualRecipients;

    public function __construct(Program $program)
    {
        $this->manualRecipients = new ArrayCollection();
        $this->options = new ArrayCollection();
        $this->program = $program;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

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

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getAudienceType(): ?AssignmentAudienceType
    {
        return $this->audienceType;
    }

    public function setAudienceType(?AssignmentAudienceType $audienceType): static
    {
        $this->audienceType = $audienceType;

        return $this;
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

    /** @return Collection<int, User> */
    public function getManualRecipients(): Collection
    {
        return $this->manualRecipients;
    }

    public function addManualRecipient(User $recipient): static
    {
        if (!$this->manualRecipients->contains($recipient)) {
            $this->manualRecipients->add($recipient);
        }

        return $this;
    }

    public function removeManualRecipient(User $recipient): static
    {
        $this->manualRecipients->removeElement($recipient);

        return $this;
    }

    // A submission strictly after this instant counts as late - end-of-due-date, not the exact
    // due_date midnight boundary (a student submitting at 23:00 on the due date is on time).
    public function isLate(\DateTimeImmutable $submittedAt): bool
    {
        return $submittedAt > $this->dueDate->modify('+1 day');
    }
}
