<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurveyQuestionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a SurveyTemplate - a question, a free-text comment, or an intertitle
 * (SurveyQuestionType::Titre), which is a line in the ordering and nothing else.
 *
 * Two of the five types carry no SurveyAnswer at all (see SurveyQuestionType::hasAnswers()), which
 * is why the collection below can legitimately be empty.
 */
#[ORM\Entity(repositoryClass: SurveyQuestionRepository::class)]
#[ORM\Table(name: 'survey_question')]
class SurveyQuestion implements SurveyQuestionDefinition
{
    use SurveyQuestionDefinitionTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SurveyTemplate::class, inversedBy: 'questions')]
    #[ORM\JoinColumn(name: 'survey_template_id', nullable: false)]
    private ?SurveyTemplate $surveyTemplate = null;

    /** @var Collection<int, SurveyAnswer> */
    #[ORM\OneToMany(mappedBy: 'surveyQuestion', targetEntity: SurveyAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $answers;

    public function __construct(SurveyTemplate $surveyTemplate)
    {
        $this->surveyTemplate = $surveyTemplate;
        $this->answers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSurveyTemplate(): ?SurveyTemplate
    {
        return $this->surveyTemplate;
    }

    /** @return Collection<int, SurveyAnswer> */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(SurveyAnswer $answer): static
    {
        if (!$this->answers->contains($answer)) {
            $this->answers->add($answer);
        }

        return $this;
    }

    public function removeAnswer(SurveyAnswer $answer): static
    {
        $this->answers->removeElement($answer);

        return $this;
    }
}
