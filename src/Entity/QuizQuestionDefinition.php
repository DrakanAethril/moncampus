<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BlankMode;
use App\Enum\QuestionType;

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
}
