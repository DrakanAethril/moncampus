<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SurveyQuestionType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The columns SurveyQuestion and SurveyCampaignQuestion share verbatim - the snapshot is a mirror
 * of the model, minus everything that touches correction, because a survey has none.
 *
 * $isScale is only meaningful on Unique and $minChoices/$maxChoices only on Multiple, following the
 * very convention AudienceTargetable states for $programs: the fields out of scope are not emptied,
 * they are simply not read (surveys.md §4).
 */
trait SurveyQuestionDefinitionTrait
{
    #[ORM\Column(length: 20, enumType: SurveyQuestionType::class)]
    private SurveyQuestionType $type = SurveyQuestionType::Unique;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $label = '';

    // « plusieurs réponses possibles », or any instruction the author wants under the statement.
    #[ORM\Column(name: 'help_text', type: Types::TEXT, nullable: true)]
    private ?string $helpText = null;

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    #[ORM\Column]
    private bool $required = true;

    #[ORM\Column(name: 'is_scale')]
    private bool $isScale = false;

    #[ORM\Column(name: 'min_choices', nullable: true)]
    private ?int $minChoices = null;

    #[ORM\Column(name: 'max_choices', nullable: true)]
    private ?int $maxChoices = null;

    public function getType(): SurveyQuestionType
    {
        return $this->type;
    }

    public function setType(SurveyQuestionType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getHelpText(): ?string
    {
        return $this->helpText;
    }

    public function setHelpText(?string $helpText): static
    {
        $this->helpText = $helpText;

        return $this;
    }

    public function getOrderIndex(): int
    {
        return $this->orderIndex;
    }

    public function setOrderIndex(int $orderIndex): static
    {
        $this->orderIndex = $orderIndex;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;

        return $this;
    }

    public function isScale(): bool
    {
        return $this->isScale;
    }

    public function setIsScale(bool $isScale): static
    {
        $this->isScale = $isScale;

        return $this;
    }

    public function getMinChoices(): ?int
    {
        return $this->minChoices;
    }

    public function setMinChoices(?int $minChoices): static
    {
        $this->minChoices = $minChoices;

        return $this;
    }

    public function getMaxChoices(): ?int
    {
        return $this->maxChoices;
    }

    public function setMaxChoices(?int $maxChoices): static
    {
        $this->maxChoices = $maxChoices;

        return $this;
    }
}
