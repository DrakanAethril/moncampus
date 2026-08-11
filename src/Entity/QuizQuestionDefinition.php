<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BlankMode;
use App\Enum\MatchingSideKind;
use App\Enum\QuestionType;
use App\Enum\ToleranceMode;
use App\Enum\ZoneSupportKind;

/**
 * What QuizQuestion (a teacher's editable row) and QuizInstanceQuestion (its frozen launch-time
 * copy) both are, as far as reading a question's definition goes - implemented by both through
 * QuizQuestionDefinitionTrait.
 *
 * Exists so the code that only ever *reads* a question - the blanks grader, the correction
 * templates, the teacher's "Tester" preview - can be written once instead of twice. Without it, the
 * preview tab (which works on templates) and the real passation (which works on instances) would
 * each need their own copy of the same rules, and the two copies would eventually disagree - which
 * is exactly the bug class this module already documents around QuizAttemptGrader.
 */
interface QuizQuestionDefinition
{
    public function getType(): QuestionType;

    public function getLabel(): ?string;

    public function getExplanation(): ?string;

    public function getPoints(): float;

    public function getBlankMode(): BlankMode;

    public function getBlankCount(): int;

    /** @return list<array{type: 'text'|'blank', value: string, index: int}> */
    public function getBlankSegments(): array;

    /** @return list<list<string>> accepted variants per blank, in text order */
    public function getBlankAnswers(): array;

    /** @return list<string> */
    public function getDistractors(): array;

    /** @return list<string> */
    public function getWordBank(): array;

    public function isIgnoreCase(): bool;

    public function isTolerateTypo(): bool;

    public function getZoneKind(): ZoneSupportKind;

    public function getZoneLanguage(): ?string;

    public function getZoneContent(): string;

    /** @return list<array{type: 'text'|'zone', value: string, id: string}> */
    public function getZoneSegments(): array;

    /** @return list<list<array{type: 'text'|'zone', value: string, id: string}>> */
    public function getZoneLines(): array;

    /** @return list<string> */
    public function getZoneIds(): array;

    /** @return list<array{id: string, x: float, y: float, w: float, h: float}> */
    public function getImageZones(): array;

    /** @return list<string> */
    public function getZoneCorrectIds(): array;

    /** @return list<string> */
    public function getZoneHintIds(): array;

    /** @return array<string, string> */
    public function getZoneLabelTexts(): array;

    /** @return list<array{key: string, text: string}> */
    public function getLegendeChoices(): array;

    public function getZoneFeedbackFor(string $zoneId): ?string;

    /** @return array{left: string, right: string} */
    public function getMatchingHeaders(): array;

    public function getMatchingLeftKind(): MatchingSideKind;

    public function getMatchingRightKind(): MatchingSideKind;

    /** @return list<array{id: string, left: string, right: string, leftImage: ?string, rightImage: ?string}> */
    public function getMatchingPairs(): array;

    /** @return list<string> */
    public function getMatchingPairIds(): array;

    /** @return list<string> */
    public function getMatchingDistractors(): array;

    /** @return list<string> */
    public function getMatchingDistractorImages(): array;

    /** @return list<array{key: string, text: string, image: ?string}> */
    public function getMatchingChoices(): array;

    /** @return array<string, string> choice key => what that choice is, for grading */
    public function getMatchingSignatures(): array;

    /** @return list<string> */
    public function getMatchingImageKeys(): array;

    /** @return array<string, string> */
    public function getMatchingFeedbacks(): array;

    public function getMatchingFeedbackFor(string $pairId): ?string;

    public function getNumericAnswer(): ?float;

    public function getNumericFormula(): ?string;

    /** @return list<array{name: string, min: float, max: float, step: float, decimals: int}> */
    public function getNumericVariables(): array;

    /** @return list<string> */
    public function getNumericStatementVariables(): array;

    /** @return list<array{type: 'text'|'variable', value: string, name: string}> */
    public function getNumericStatementSegments(): array;

    public function getNumericTolerance(): float;

    public function getNumericToleranceMode(): ToleranceMode;

    public function getNumericUnit(): ?string;

    public function isNumericUnitRequired(): bool;

    public function getNumericDecimals(): int;
}
