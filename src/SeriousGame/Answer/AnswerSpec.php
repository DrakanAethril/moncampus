<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

use App\SeriousGame\Template\TemplateFormatException;

/**
 * What a template says about the answer it expects, beside its value: the type, the tolerance of a
 * number, the unit shown after the field, the shape a polynomial must take, whether an interval's
 * brackets matter, the letters of a formula, the fields of a table to fill (`labels`) and the
 * number of propositions of a QCM (`choices`, the answer included).
 */
final readonly class AnswerSpec
{
    public const array FORMS = ['developed', 'factored', 'factoredMax'];
    public const int DEFAULT_CHOICES = 4;

    /**
     * @param list<string> $vars
     * @param list<string> $labels
     */
    public function __construct(
        public AnswerType $type,
        public ?float $tolerance = null,
        public ?string $unit = null,
        public ?string $form = null,
        public bool $anyClosure = false,
        public array $vars = [],
        public ?string $target = null,
        public array $labels = [],
        public int $choices = self::DEFAULT_CHOICES,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = AnswerType::tryFrom(\is_string($data['type'] ?? null) ? $data['type'] : '')
            ?? throw new TemplateFormatException('answer.type', 'type de réponse inconnu');

        $form = isset($data['form']) && \is_string($data['form']) ? $data['form'] : null;
        if (null !== $form && !\in_array($form, self::FORMS, true)) {
            throw new TemplateFormatException('answer.form', 'forme inconnue « '.$form.' » (attendu : '.implode(', ', self::FORMS).')');
        }

        $vars = [];
        foreach (\is_array($data['vars'] ?? null) ? $data['vars'] : [] as $var) {
            if (\is_string($var)) {
                $vars[] = $var;
            }
        }

        if (AnswerType::Formula === $type && ([] === $vars || !\is_string($data['target'] ?? null))) {
            throw new TemplateFormatException('answer', 'une formule déclare ses lettres (« vars ») et la lettre à isoler (« target »)');
        }

        $labels = [];
        foreach (\is_array($data['labels'] ?? null) ? $data['labels'] : [] as $label) {
            if (\is_string($label)) {
                $labels[] = $label;
            }
        }
        if (AnswerType::Forms === $type && \count($labels) < 2) {
            throw new TemplateFormatException('answer.labels', 'un tableau à compléter nomme ses cases (« labels », au moins deux)');
        }

        $choices = $data['choices'] ?? self::DEFAULT_CHOICES;
        if (!\is_int($choices) || $choices < 2 || $choices > 6) {
            throw new TemplateFormatException('answer.choices', 'nombre de propositions entre 2 et 6 attendu');
        }

        $tolerance = $data['tolerance'] ?? null;

        return new self(
            $type,
            \is_int($tolerance) || \is_float($tolerance) ? (float) $tolerance : null,
            isset($data['unit']) && \is_string($data['unit']) ? $data['unit'] : null,
            $form,
            true === ($data['anyClosure'] ?? false),
            $vars,
            \is_string($data['target'] ?? null) ? $data['target'] : null,
            $labels,
            $choices,
        );
    }
}
