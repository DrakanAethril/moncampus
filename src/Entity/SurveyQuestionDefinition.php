<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SurveyQuestionType;
use Doctrine\Common\Collections\Collection;

/**
 * What SurveyQuestion (the author's editable row) and SurveyCampaignQuestion (its frozen
 * launch-time copy) both are, as far as reading a question goes - implemented by both through
 * SurveyQuestionDefinitionTrait.
 *
 * Same reasoning as QuizQuestionDefinition: the code that only ever *reads* a question - the
 * respondent's screen, the editor preview, the payload built for the mobile API - is written once
 * against this interface instead of twice against two nearly identical classes that would
 * eventually drift apart.
 */
interface SurveyQuestionDefinition
{
    public function getType(): SurveyQuestionType;

    public function getLabel(): string;

    public function getHelpText(): ?string;

    public function getOrderIndex(): int;

    public function isRequired(): bool;

    /**
     * Whether the answers of this single-choice question form an ordered scale, i.e. whether their
     * order_index *is* a value - see surveys.md §12.A. Only meaningful on Unique.
     */
    public function isScale(): bool;

    /** Only meaningful on Multiple. */
    public function getMinChoices(): ?int;

    /** Only meaningful on Multiple. */
    public function getMaxChoices(): ?int;

    /** @return Collection<int, covariant SurveyAnswerDefinition> */
    public function getAnswers(): Collection;
}
