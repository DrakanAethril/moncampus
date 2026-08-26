<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurveyTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A survey model in its author's library - see design/validated/surveys.md §4.
 *
 * Deliberately carries no program_id: a model belongs to nobody's class, it belongs to its author,
 * exactly like QuizTemplate and SequenceTemplate. It is the campaign that aims at people, and it
 * does so through the shared audience mechanism (SurveyCampaign implements AudienceTargetable).
 *
 * Launching it (App\Service\Survey\SurveyLauncher) deep-copies its questions onto a
 * SurveyCampaign; editing the model afterwards never touches a campaign already launched, which is
 * what lets results survive a change of model and what makes a replay ask exactly the same
 * questions.
 */
#[ORM\Entity(repositoryClass: SurveyTemplateRepository::class)]
#[ORM\Table(name: 'survey_template')]
class SurveyTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false)]
    private ?User $owner = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    // Shown to the respondent, above the first question.
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    // Free text, same "teacher-private tag" reasoning as QuizTemplate::$subject - not linked to
    // the real Topic/Option entities.
    #[ORM\Column(name: 'subject', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $subject = null;

    /**
     * Where the author filed this model, null at the root of their library
     * (App\Entity\SurveyFolder). A classement, not an owner: the model belongs to its author and
     * the folder only says where it is shown.
     *
     * `ON DELETE SET NULL` is a floor, not the rule - App\Service\Survey\SurveyFolderManager
     * promotes the content of a deleted folder one level up, so a model never silently jumps to the
     * root. The constraint is what guarantees that a folder row removed by any other path still
     * cannot take a model with it.
     */
    #[ORM\ManyToOne(targetEntity: SurveyFolder::class)]
    #[ORM\JoinColumn(name: 'folder_id', nullable: true, onDelete: 'SET NULL')]
    private ?SurveyFolder $folder = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'last_updated_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUpdatedDate = null;

    /** @var Collection<int, SurveyQuestion> */
    #[ORM\OneToMany(mappedBy: 'surveyTemplate', targetEntity: SurveyQuestion::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $questions;

    public function __construct()
    {
        $this->questions = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getFolder(): ?SurveyFolder
    {
        return $this->folder;
    }

    public function setFolder(?SurveyFolder $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function setCreationDate(\DateTimeImmutable $creationDate): static
    {
        $this->creationDate = $creationDate;

        return $this;
    }

    public function getLastUpdatedDate(): ?\DateTimeImmutable
    {
        return $this->lastUpdatedDate;
    }

    public function touch(): static
    {
        $this->lastUpdatedDate = new \DateTimeImmutable();

        return $this;
    }

    /** @return Collection<int, SurveyQuestion> */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(SurveyQuestion $question): static
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
        }

        return $this;
    }

    public function removeQuestion(SurveyQuestion $question): static
    {
        $this->questions->removeElement($question);

        return $this;
    }

    /**
     * The questions that actually ask something - Titre excluded. Every count in the feature goes
     * through this rather than through getQuestions(), see surveys.md §7.13 and the twin method on
     * SurveyCampaign.
     *
     * @return list<SurveyQuestion>
     */
    public function answerableQuestions(): array
    {
        return array_values(array_filter(
            $this->questions->toArray(),
            static fn (SurveyQuestion $question): bool => $question->getType()->isAnswerable(),
        ));
    }
}
