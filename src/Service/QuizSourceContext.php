<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\QuizSourceScope;

/**
 * The course a quiz is being generated from, written into the prompt that carries it.
 *
 * The twin of App\Service\VideoImportContext in role - a language model cannot read the séquence any
 * more than it can watch the video, so the prompt the teacher carries has to contain it. Without this,
 * generation was blind to the course: the teacher retyped "BTS SIO 2, SISR, Ansible" into the prompt's
 * bracketed fields and got questions about the subject rather than about their own lesson.
 *
 * It is deliberately **not** called SequenceImportContext, which is what the design document proposed.
 * In this repository `SequenceImport*` already means "the import *of* a séquence"
 * (SequenceImportPouring, SequenceImportWriter, SequenceImportPayload); reusing the prefix for the
 * opposite - a quiz import made *from* a séquence - would put the one genuine naming collision of the
 * feature on the object that two controllers read.
 *
 * Primitives only, like App\Service\AccessConditionEvaluator: the four strings are what a prompt
 * needs, and both rules below are then testable without a database.
 * App\Service\QuizSourceContextFactory is the half that reads entities.
 *
 * The two rules, and both are about not lying:
 *
 * - **It counts the characters that are actually sent**, headings included, so the figure on screen is
 *   the figure the model receives.
 * - **It never truncates.** Over the cap it says so and the screen names the two levers - untick the
 *   phases, or narrow the scope to one séance. Cutting the course in half in silence would produce
 *   questions about the first half with nothing to explain why.
 */
final class QuizSourceContext
{
    public function __construct(
        public readonly QuizSourceScope $scope,
        public readonly string $title,
        private readonly string $objectives,
        private readonly string $phases,
    ) {
    }

    /**
     * The block as it goes into the prompt, or an empty string when the teacher ticked neither box -
     * a heading with nothing under it is characters spent to say nothing.
     */
    public function text(bool $withObjectives, bool $withPhases): string
    {
        $parts = [];
        if ($withObjectives && $this->hasObjectives()) {
            $parts[] = QuizPromptCatalog::CONTEXT_OBJECTIVES_HEADING."\n".$this->objectives;
        }
        if ($withPhases && $this->hasPhases()) {
            $parts[] = QuizPromptCatalog::CONTEXT_PHASES_HEADING."\n".$this->phases;
        }

        if ([] === $parts) {
            return '';
        }

        return implode("\n\n", [QuizPromptCatalog::CONTEXT_HEADING, $this->scopeSentence(), ...$parts]);
    }

    /** Characters, not bytes: "é" is not two of anything the teacher can see. */
    public function length(bool $withObjectives, bool $withPhases): int
    {
        return mb_strlen($this->text($withObjectives, $withPhases));
    }

    public function exceedsCap(bool $withObjectives, bool $withPhases): bool
    {
        return $this->length($withObjectives, $withPhases) > QuizPromptCatalog::MAX_CONTEXT_CHARACTERS;
    }

    /** Readable on its own because the browser assembles the prompt as the teacher ticks. */
    public function objectives(): string
    {
        return $this->objectives;
    }

    public function phases(): string
    {
        return $this->phases;
    }

    public function hasObjectives(): bool
    {
        return '' !== trim($this->objectives);
    }

    public function hasPhases(): bool
    {
        return '' !== trim($this->phases);
    }

    /** Nothing written down anywhere - the screen says so rather than offering two empty boxes. */
    public function isEmpty(): bool
    {
        return !$this->hasObjectives() && !$this->hasPhases();
    }

    public function scopeSentence(): string
    {
        $template = QuizSourceScope::Seance === $this->scope
            ? QuizPromptCatalog::CONTEXT_SEANCE_TEMPLATE
            : QuizPromptCatalog::CONTEXT_SEQUENCE_TEMPLATE;

        return str_replace('%title%', $this->title, $template);
    }
}
