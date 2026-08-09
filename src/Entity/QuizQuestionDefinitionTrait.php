<?php

namespace App\Entity;

use App\Enum\BlankMode;
use App\Util\BlankTextParser;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The QuestionType::TexteATrous half of a question, shared verbatim by QuizQuestion (the teacher's
 * editable template row) and QuizInstanceQuestion (its frozen launch-time copy) - screens 2a/2b of
 * design/design_handoff_quiz.
 *
 * Unlike every other question type, a texte à trous keeps no QuizAnswer rows: the whole definition
 * lives in one JSON column. Accepted answers are *per blank and multi-variant* ("32" or
 * "trente-deux"), which a flat answer table with one label per row cannot express without
 * overloading a column to mean a blank number. The JSON is never read raw outside this trait - the
 * accessors below are the only shape contract, so a future key rename stays a one-file change.
 *
 * Stored shape:
 *   {"mode":"banque"|"libre",
 *    "blanks":[{"answers":["32","trente-deux"]}, ...],   // index = blank number, in text order
 *    "distractors":["64","8"],                            // banque mode only, may be absent
 *    "ignoreCase":true, "tolerateTypo":false}
 */
trait QuizQuestionDefinitionTrait
{
    #[ORM\Column(name: 'blanks_config', type: Types::JSON, nullable: true)]
    private ?array $blanksConfig = null;

    /**
     * Optional "Correction : …" note shown under this question on the entraînement correction
     * screen (1m) once the student got it wrong. Not blanks-specific - it rides along here because
     * it is the other field both question entities gained at the same time, and keeping the two
     * copies of the mapping in one place is what stops them drifting apart.
     */
    #[ORM\Column(name: 'explanation', type: Types::TEXT, nullable: true)]
    private ?string $explanation = null;

    /**
     * The question's worth, split equally between its blanks at grading time ("1 point réparti
     * équitablement entre les trous (paramétrable)"). Only read for texte à trous - every other
     * type stays the historical all-or-nothing 1 point.
     */
    #[ORM\Column(name: 'points', type: Types::DECIMAL, precision: 5, scale: 2, options: ['default' => '1.00'])]
    private string $points = '1.00';

    public function getExplanation(): ?string
    {
        return $this->explanation;
    }

    public function setExplanation(?string $explanation): static
    {
        $this->explanation = '' === trim((string) $explanation) ? null : $explanation;

        return $this;
    }

    public function getPoints(): float
    {
        return (float) $this->points;
    }

    public function setPoints(float $points): static
    {
        $this->points = number_format(max(0.0, $points), 2, '.', '');

        return $this;
    }

    /** @return array<string, mixed>|null the raw JSON, for deep-copying to an instance question only */
    public function getBlanksConfig(): ?array
    {
        return $this->blanksConfig;
    }

    public function setBlanksConfig(?array $blanksConfig): static
    {
        $this->blanksConfig = $blanksConfig;

        return $this;
    }

    public function getBlankMode(): BlankMode
    {
        return BlankMode::tryFrom((string) ($this->blanksConfig['mode'] ?? '')) ?? BlankMode::Banque;
    }

    public function setBlankMode(BlankMode $mode): static
    {
        $this->blanksConfig['mode'] = $mode->value;

        return $this;
    }

    /**
     * How many blanks the statement actually contains. Always derived from the text rather than
     * from the stored answers: the teacher can add or remove a "..." at any time, and the text is
     * the single source of truth the editor's "n trous détectés" counter also reads.
     */
    public function getBlankCount(): int
    {
        return BlankTextParser::countBlanks($this->getLabel());
    }

    /** @return list<array{type: 'text'|'blank', value: string, index: int}> */
    public function getBlankSegments(): array
    {
        return BlankTextParser::segments($this->getLabel());
    }

    /**
     * Accepted variants for every blank, padded/truncated to the number of blanks the text has
     * right now - so a stale config saved before the teacher added a "..." never shifts answers
     * onto the wrong blank.
     *
     * @return list<list<string>>
     */
    public function getBlankAnswers(): array
    {
        $stored = $this->blanksConfig['blanks'] ?? [];
        $answers = [];

        for ($i = 0, $count = $this->getBlankCount(); $i < $count; ++$i) {
            $variants = $stored[$i]['answers'] ?? [];
            $answers[] = array_values(array_filter(
                array_map(static fn ($v): string => trim((string) $v), \is_array($variants) ? $variants : []),
                static fn (string $v): bool => '' !== $v,
            ));
        }

        return $answers;
    }

    /** @param array<array-key, list<string>> $answers re-indexed on the way in: this is a JSON column, and a gap in the keys would store an object */
    public function setBlankAnswers(array $answers): static
    {
        $this->blanksConfig['blanks'] = array_values(array_map(
            static fn (array $variants): array => ['answers' => array_values(array_filter(
                array_map(static fn ($v): string => trim((string) $v), $variants),
                static fn (string $v): bool => '' !== $v,
            ))],
            $answers,
        ));

        return $this;
    }

    /** @return list<string> the optional wrong words mixed into the bank - banque mode only */
    public function getDistractors(): array
    {
        if (BlankMode::Banque !== $this->getBlankMode()) {
            return [];
        }

        $stored = $this->blanksConfig['distractors'] ?? [];

        return array_values(array_filter(
            array_map(static fn ($v): string => trim((string) $v), \is_array($stored) ? $stored : []),
            static fn (string $v): bool => '' !== $v,
        ));
    }

    /** @param list<string> $distractors */
    public function setDistractors(array $distractors): static
    {
        $this->blanksConfig['distractors'] = array_values(array_filter(
            array_map(static fn ($v): string => trim((string) $v), $distractors),
            static fn (string $v): bool => '' !== $v,
        ));

        return $this;
    }

    // Screen 2b's two options. Both default the way the créa shows them for a brand new question:
    // accents/case forgiven, typos not.
    public function isIgnoreCase(): bool
    {
        return (bool) ($this->blanksConfig['ignoreCase'] ?? true);
    }

    public function setIgnoreCase(bool $ignoreCase): static
    {
        $this->blanksConfig['ignoreCase'] = $ignoreCase;

        return $this;
    }

    public function isTolerateTypo(): bool
    {
        return (bool) ($this->blanksConfig['tolerateTypo'] ?? false);
    }

    public function setTolerateTypo(bool $tolerateTypo): static
    {
        $this->blanksConfig['tolerateTypo'] = $tolerateTypo;

        return $this;
    }

    /**
     * The words offered to the student in banque mode: one per blank (the first accepted variant,
     * i.e. what the teacher typed on screen 2a) plus the distractors. Returned in definition order -
     * the shuffle is a presentation concern, done per attempt so two students never get the same
     * bank layout (see App\Service\QuizDrawService).
     *
     * @return list<string>
     */
    public function getWordBank(): array
    {
        $words = [];
        foreach ($this->getBlankAnswers() as $variants) {
            if (isset($variants[0])) {
                $words[] = $variants[0];
            }
        }

        return [...$words, ...$this->getDistractors()];
    }
}
