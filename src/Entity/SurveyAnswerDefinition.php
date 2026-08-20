<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What SurveyAnswer (the author's editable row) and SurveyCampaignAnswer (its frozen copy) both
 * are - the counterpart of SurveyQuestionDefinition one level down.
 *
 * On a question flagged is_scale, getOrderIndex() *is* the scale value, 0 being the low pole. That
 * is what lets the average be computed without adding a weight column (surveys.md §4).
 */
interface SurveyAnswerDefinition
{
    public function getId(): ?int;

    public function getLabel(): string;

    public function getOrderIndex(): int;
}
