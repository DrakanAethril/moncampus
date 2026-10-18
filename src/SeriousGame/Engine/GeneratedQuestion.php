<?php

declare(strict_types=1);

namespace App\SeriousGame\Engine;

use App\SeriousGame\Answer\AnswerSpec;

/**
 * One question, as a template and a seed give it back (design/validated/serious-games.md §5.5).
 *
 * It holds the answer and the typical wrong answers, because the server corrects; what the game
 * receives is publicView(), which carries neither - « jamais la réponse » (§6.2).
 *
 * @phpstan-type Wrong array{value: mixed, display: string, text: string, misconception: string}
 * @phpstan-type Choice array{value: string, html: string}
 * @phpstan-type PublicQuestion array{unit: string, version: int, seed: int, kind: string, prompt: string, figure: ?string, answerType: string, unit_label: ?string, form: ?string, difficulty: int, misconceptions: list<array{id: string, label: string}>, labels: list<string>, choices: list<Choice>}
 */
final readonly class GeneratedQuestion
{
    /**
     * @param list<Wrong>                              $wrongs         the distinct wrong answers this question recognises
     * @param list<array{id: string, label: string}> $misconceptions the template's catalogue, without values
     * @param list<Choice>                             $choices        a QCM's propositions, in the order shown: the answer among its typical wrong answers
     */
    public function __construct(
        public string $unit,
        public int $version,
        public int $seed,
        public string $kind,
        public string $prompt,
        public ?string $figure,
        public AnswerSpec $spec,
        public int $difficulty,
        public string $method,
        public mixed $answerValue,
        public string $answerDisplay,
        public string $answerText,
        public array $wrongs,
        public array $misconceptions,
        public ?string $shapeMisconception,
        public int $attempts,
        public array $choices = [],
    ) {
    }

    /** What makes two questions of one series the same question. */
    public function identity(): string
    {
        return $this->prompt.($this->figure ?? '');
    }

    /** @return list<string> the misconceptions this very question can recognise */
    public function recognisedMisconceptions(): array
    {
        $ids = array_column($this->wrongs, 'misconception');
        if (null !== $this->shapeMisconception) {
            $ids[] = $this->shapeMisconception;
        }

        return array_values(array_unique($ids));
    }

    /**
     * The statement as the game receives it: never the answer, never the wrong values - except,
     * for a QCM, the propositions themselves, among which nothing says which one is right.
     *
     * @return PublicQuestion
     */
    public function publicView(): array
    {
        return [
            'unit' => $this->unit,
            'version' => $this->version,
            'seed' => $this->seed,
            'kind' => $this->kind,
            'prompt' => $this->prompt,
            'figure' => $this->figure,
            'answerType' => $this->spec->type->value,
            'unit_label' => $this->spec->unit,
            'form' => $this->spec->form,
            'difficulty' => $this->difficulty,
            'misconceptions' => $this->misconceptions,
            'labels' => $this->spec->labels,
            'choices' => $this->choices,
        ];
    }
}
