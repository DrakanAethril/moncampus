<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

use App\SeriousGame\Answer\AnswerChecker;
use App\SeriousGame\Answer\AnswerCheckers;
use App\SeriousGame\Answer\AnswerSpec;
use App\SeriousGame\Answer\AnswerType;
use App\SeriousGame\Engine\GeneratedQuestion;
use App\SeriousGame\Math\MathExpression;
use App\SeriousGame\Math\MathFormat;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Parser;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * Reads a question template - the JSON format `moncampus-gabarit/1` - and draws questions from it
 * (design/validated/serious-games.md §5.1, §5.2).
 *
 * A template is a list of **variants** (or one, written at the top level), each drawn with its
 * `weight`. A variant draws its `params` in order from the question's seeded draw, computes its
 * `let`, and checks its `constraints`; a false constraint - or fewer distinct wrong answers than
 * `minWrongs` (3 by default) - draws again, at most 200 times. Then it writes the statement
 * (`prompt`, with `{{ expression }}` inside), the optional `figure`, the `answer` and the `wrongs`:
 * every typical error the template can recognise, as the value a student making it would type.
 *
 * Everything that computes is a Symfony ExpressionLanguage expression over the functions of
 * TemplateFunctions. Nothing else runs - which is what lets an administrator change a template
 * through the connector.
 *
 * The rules that decide which wrong answers a question keeps (distinctWrongs()) are the
 * reference's, to the letter: a wrong answer equal to the right one, to another wrong answer, or
 * that reads like the right one once rounded is dropped - it would name an error the student did
 * not make.
 */
final class TemplateInterpreter
{
    public const string FORMAT = 'moncampus-gabarit/1';
    public const int MAX_ATTEMPTS = 200;
    public const int DEFAULT_MIN_WRONGS = 3;

    private const array TOP_KEYS = ['format', 'unit', 'label', 'difficulty', 'misconceptions', 'params', 'let', 'constraints', 'variants', 'prompt', 'figure', 'answer', 'wrongs', 'method', 'minWrongs', 'notes'];
    private const array VARIANT_KEYS = ['weight', 'label', 'difficulty', 'params', 'let', 'constraints', 'prompt', 'figure', 'answer', 'wrongs', 'method', 'minWrongs'];
    private const array ANSWER_KEYS = ['type', 'value', 'display', 'text', 'tolerance', 'unit', 'form', 'anyClosure', 'vars', 'target', 'shapeMisconception', 'labels', 'choices'];
    private const array WRONG_KEYS = ['misconception', 'value', 'display', 'text', 'when'];
    private const array RESERVED = ['item', 'index', TemplateFunctions::CONTEXT, 'true', 'false', 'null'];

    private ExpressionLanguage $language;

    /** @var array<string, true> documents already validated, by hash */
    private array $validated = [];

    /** @var \Closure(string): string */
    private \Closure $sanitize;

    /**
     * @param (\Closure(string): string)|null $sanitize the HTML sanitizer of the statements; none in unit tests
     */
    public function __construct(private readonly TemplateFunctions $functions = new TemplateFunctions(), ?\Closure $sanitize = null)
    {
        // Parsed expressions are kept as objects: the default ArrayAdapter serialises every entry,
        // and a template evaluates a few dozen expressions per question.
        $this->language = new ExpressionLanguage(new ArrayAdapter(0, false), [$this->functions]);
        $this->sanitize = $sanitize ?? static fn (string $html): string => $html;
    }

    public function functions(): TemplateFunctions
    {
        return $this->functions;
    }

    /**
     * Refuses the document whole, at its first error, naming where it is.
     *
     * @param array<array-key, mixed> $document
     *
     * @throws TemplateFormatException
     */
    public function validate(array $document): void
    {
        $hash = md5((string) json_encode($document));
        if (isset($this->validated[$hash])) {
            return;
        }

        if (self::FORMAT !== ($document['format'] ?? null)) {
            throw new TemplateFormatException('format', 'attendu « '.self::FORMAT.' »');
        }
        self::onlyKeys($document, self::TOP_KEYS, '');
        if (!\is_string($document['unit'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $document['unit'])) {
            throw new TemplateFormatException('unit', 'identifiant d’unité attendu (minuscules, chiffres, tirets)');
        }
        if (!\is_string($document['label'] ?? null) || '' === trim($document['label'])) {
            throw new TemplateFormatException('label', 'libellé de l’unité attendu');
        }

        $ids = [];
        foreach (self::listOf($document['misconceptions'] ?? [], 'misconceptions') as $i => $entry) {
            $path = 'misconceptions['.$i.']';
            if (!\is_array($entry)) {
                throw new TemplateFormatException($path, 'objet { id, label } attendu');
            }
            self::onlyKeys($entry, ['id', 'label', 'explanation'], $path);
            if (!\is_string($entry['id'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $entry['id'])) {
                throw new TemplateFormatException($path.'.id', 'identifiant d’erreur attendu (minuscules, chiffres, tirets)');
            }
            if (isset($ids[$entry['id']])) {
                throw new TemplateFormatException($path.'.id', 'erreur « '.$entry['id'].' » déclarée deux fois');
            }
            if (!\is_string($entry['label'] ?? null) || '' === trim($entry['label'])) {
                throw new TemplateFormatException($path.'.label', 'libellé attendu');
            }
            if (isset($entry['explanation']) && !\is_string($entry['explanation'])) {
                throw new TemplateFormatException($path.'.explanation', 'texte attendu');
            }
            $ids[$entry['id']] = true;
        }

        $this->validateDifficulty($document['difficulty'] ?? null, 'difficulty');
        $this->validateBindings($document, '');

        if (isset($document['variants'])) {
            $variants = self::listOf($document['variants'], 'variants');
            if ([] === $variants) {
                throw new TemplateFormatException('variants', 'au moins une variante');
            }
            foreach (['prompt', 'answer', 'wrongs', 'method', 'figure'] as $key) {
                if (isset($document[$key])) {
                    throw new TemplateFormatException($key, 'avec des variantes, « '.$key.' » s’écrit dans chaque variante');
                }
            }
            foreach ($variants as $i => $variant) {
                $path = 'variants['.$i.']';
                if (!\is_array($variant)) {
                    throw new TemplateFormatException($path, 'objet attendu');
                }
                self::onlyKeys($variant, self::VARIANT_KEYS, $path);
                if (isset($variant['weight']) && (!is_numeric($variant['weight']) || $variant['weight'] <= 0)) {
                    throw new TemplateFormatException($path.'.weight', 'poids positif attendu');
                }
                $this->validateDifficulty($variant['difficulty'] ?? null, $path.'.difficulty');
                $this->validateBindings($variant, $path.'.');
                $this->validateBody($variant, $path.'.', $ids);
            }
        } else {
            $this->validateBody($document, '', $ids);
        }

        $this->validated[$hash] = true;
    }

    /**
     * Draws the question of this seed.
     *
     * @throws TemplateFormatException   when the template is not valid, or fails while drawing
     * @throws TemplateExhaustedException when 200 draws in a row are rejected
     */
    public function generate(TemplateDefinition $template, int $seed): GeneratedQuestion
    {
        $document = $template->document;
        $this->validate($document);

        $context = new TemplateContext(new SeededRandom($seed, $template->unit), $this->language);
        $variants = isset($document['variants']) && \is_array($document['variants']) ? array_values($document['variants']) : [$document];

        $misconceptions = array_map(static fn (array $m): array => ['id' => $m['id'], 'label' => $m['label']], $template->misconceptions());

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            /** @var array<string, mixed> $variant */
            $variant = \count($variants) > 1 ? $this->chooseVariant($variants, $context->random) : $variants[0];
            $variables = [TemplateFunctions::CONTEXT => $context];
            $question = null;

            try {
                $context->drawing = true;
                $variables = $this->bind($document['params'] ?? [], $variables, 'params');
                if ($variant !== $document) {
                    $variables = $this->bind($variant['params'] ?? [], $variables, 'variant.params');
                }
                $context->drawing = false;
                $variables = $this->bind($document['let'] ?? [], $variables, 'let');
                if ($variant !== $document) {
                    $variables = $this->bind($variant['let'] ?? [], $variables, 'variant.let');
                }

                if (!$this->holds($document['constraints'] ?? [], $variables) || ($variant !== $document && !$this->holds($variant['constraints'] ?? [], $variables))) {
                    continue;
                }

                $question = $this->build($template, $seed, $document, $variant, $variables, $misconceptions, $attempt);
            } catch (\DivisionByZeroError|\ArithmeticError|RejectedDraw) {
                continue;
            } finally {
                $context->drawing = false;
            }

            if (null !== $question) {
                return $question;
            }
        }

        throw new TemplateExhaustedException($template->unit, $seed);
    }

    /**
     * @param array<string, mixed>                     $document
     * @param array<string, mixed>                     $variant
     * @param array<string, mixed>                     $variables
     * @param list<array{id: string, label: string}> $misconceptions
     */
    private function build(TemplateDefinition $template, int $seed, array $document, array $variant, array $variables, array $misconceptions, int $attempt): ?GeneratedQuestion
    {
        /** @var array<string, mixed> $answer */
        $answer = $variant['answer'];
        $spec = AnswerSpec::fromArray($this->resolveAnswer($answer, $variables));
        $checker = AnswerCheckers::for($spec->type);
        $numeric = self::isNumeric($spec->type);

        $value = $this->valueOf($checker, $spec, $this->evaluate($answer['value'], $variables, 'answer.value'), 'answer.value');
        if ($numeric && (!\is_float($value) || !is_finite($value))) {
            return null;
        }
        $display = isset($answer['display']) ? $this->string($answer['display'], $variables, 'answer.display') : $this->defaultDisplay($spec, $value);
        $text = isset($answer['text']) ? $this->string($answer['text'], $variables, 'answer.text') : self::sentText($spec, $value) ?? MathFormat::plain($display);

        $wrongs = [];
        foreach (\is_array($variant['wrongs'] ?? null) ? $variant['wrongs'] : [] as $i => $wrong) {
            if (!\is_array($wrong) || !\is_string($wrong['misconception'] ?? null)) {
                continue;
            }
            $path = 'wrongs['.$i.']';
            if (isset($wrong['when']) && true !== $this->evaluate($wrong['when'], $variables, $path.'.when')) {
                continue;
            }

            $raw = $this->evaluate($wrong['value'] ?? null, $variables, $path.'.value');
            try {
                $wrongValue = null === $raw ? null : $checker->value($raw, $spec);
            } catch (\InvalidArgumentException) {
                $wrongValue = null;
            }
            if (null === $wrongValue) {
                continue;
            }
            $wrongDisplay = isset($wrong['display']) ? $this->string($wrong['display'], $variables, $path.'.display') : $this->defaultDisplay($spec, $wrongValue);
            $wrongs[] = [
                'value' => $wrongValue,
                'display' => $wrongDisplay,
                'text' => isset($wrong['text']) ? $this->string($wrong['text'], $variables, $path.'.text') : self::sentText($spec, $wrongValue) ?? MathFormat::plain($wrongDisplay),
                'misconception' => $wrong['misconception'],
            ];
        }

        $wrongs = self::distinctWrongs($checker, $spec, $value, $display, $wrongs);
        $minWrongs = $variant['minWrongs'] ?? $document['minWrongs'] ?? self::DEFAULT_MIN_WRONGS;
        $minWrongs = \is_int($minWrongs) ? $minWrongs : self::DEFAULT_MIN_WRONGS;
        if (\count($wrongs) < $minWrongs) {
            return null;
        }

        // A QCM shows the answer and as many typical wrong answers as it has room for, in the order
        // of the seed: only a proposition the student can choose is an error they can be
        // recognised making, so the others are dropped.
        $choices = [];
        if (AnswerType::Choice === $spec->type) {
            if (\count($wrongs) < $spec->choices - 1) {
                return null;
            }
            $wrongs = \array_slice($wrongs, 0, $spec->choices - 1);
            $options = [['value' => $text, 'html' => $display]];
            foreach ($wrongs as $wrong) {
                $options[] = ['value' => $wrong['text'], 'html' => $wrong['display']];
            }
            $choices = (new SeededRandom($seed, $template->unit.'#choices'))->shuffle($options);
        }
        // Items to put in order go to the game shuffled, never in the order of the answer - nor in
        // the order of a typical wrong answer, which would hand it over.
        if (AnswerType::Order === $spec->type && \is_array($value)) {
            $items = array_map(static fn (string $item): array => ['value' => $item, 'html' => htmlspecialchars($item, \ENT_NOQUOTES)], array_values(array_filter($value, is_string(...))));
            $random = new SeededRandom($seed, $template->unit.'#order');
            $forbidden = [array_column($items, 'value'), ...array_map(static fn (array $w): mixed => $w['value'], $wrongs)];
            for ($i = 0; $i < 20; ++$i) {
                $choices = $random->shuffle($items);
                if (!\in_array(array_column($choices, 'value'), $forbidden, true)) {
                    break;
                }
            }
        }

        $figure = null;
        if (isset($variant['figure']) && \is_array($variant['figure'])) {
            $figure = $this->figure($variant['figure'], $variables);
        }

        $difficulty = $variant['difficulty'] ?? $document['difficulty'] ?? 1;
        $difficulty = \is_string($difficulty) ? $this->evaluate($difficulty, $variables, 'difficulty') : $difficulty;

        return new GeneratedQuestion(
            unit: $template->unit,
            version: $template->version,
            seed: $seed,
            kind: $spec->type->kind(),
            prompt: ($this->sanitize)($this->render(self::stringAt($variant, 'prompt'), $variables, 'prompt')),
            figure: $figure,
            spec: $spec,
            difficulty: \is_int($difficulty) || \is_float($difficulty) ? (int) $difficulty : 1,
            method: ($this->sanitize)($this->render(self::stringAt($variant, 'method'), $variables, 'method')),
            answerValue: $value,
            answerDisplay: ($this->sanitize)($display),
            answerText: $text,
            wrongs: array_map(fn (array $w): array => ['display' => ($this->sanitize)($w['display'])] + $w, $wrongs),
            misconceptions: $misconceptions,
            shapeMisconception: \is_string($answer['shapeMisconception'] ?? null) ? $answer['shapeMisconception'] : null,
            attempts: $attempt,
            choices: array_map(fn (array $c): array => ['value' => $c['value'], 'html' => ($this->sanitize)($c['html'])], $choices),
        );
    }

    /**
     * The settings of an answer that may change from one question to the next, evaluated:
     * `tolerance` and `vars` when they are expressions, and a formula's `target` - always an
     * expression, so a fixed letter is written `"'R'"`.
     *
     * @param array<string, mixed> $answer
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>
     */
    private function resolveAnswer(array $answer, array $variables): array
    {
        if (\is_string($answer['tolerance'] ?? null)) {
            $answer['tolerance'] = $this->evaluate($answer['tolerance'], $variables, 'answer.tolerance');
        }
        if (\is_string($answer['vars'] ?? null)) {
            $answer['vars'] = $this->evaluate($answer['vars'], $variables, 'answer.vars');
        }
        if (\is_string($answer['target'] ?? null)) {
            $answer['target'] = $this->evaluate($answer['target'], $variables, 'answer.target');
        }
        if (\is_string($answer['labels'] ?? null)) {
            $answer['labels'] = $this->evaluate($answer['labels'], $variables, 'answer.labels');
        }

        return $answer;
    }

    /**
     * The reference's distinctWrongs(): the wrong answers a question keeps.
     *
     * @param list<array{value: mixed, display: string, text: string, misconception: string}> $wrongs
     *
     * @return list<array{value: mixed, display: string, text: string, misconception: string}>
     */
    public static function distinctWrongs(AnswerChecker $checker, AnswerSpec $spec, mixed $value, string $display, array $wrongs): array
    {
        $numeric = self::isNumeric($spec->type);
        $kept = [];
        $plainDisplay = trim(MathFormat::plain($display));

        foreach ($wrongs as $wrong) {
            $w = $wrong['value'];
            if ($numeric && (!\is_float($w) || !is_finite($w))) {
                continue;
            }
            $tolerance = $numeric && null !== $spec->tolerance ? min($spec->tolerance, .049) : null;
            if ($checker->same($w, $value, $spec, $tolerance)) {
                continue;
            }
            foreach ($kept as $k) {
                if ($checker->same($k['value'], $w, $spec) || $k['display'] === $wrong['display']) {
                    continue 2;
                }
            }
            if ($wrong['display'] === $display) {
                continue;
            }
            if ($numeric && str_contains($wrong['display'], '≈') && \is_float($value) && abs($w - $value) <= .051) {
                continue;
            }
            if (trim(str_replace('≈', '', MathFormat::plain($wrong['display']))) === $plainDisplay) {
                continue;
            }
            if ($numeric) {
                foreach ($kept as $k) {
                    if ((str_contains($k['display'], '≈') || str_contains($wrong['display'], '≈')) && \is_float($k['value']) && abs($k['value'] - $w) <= .102) {
                        continue 2;
                    }
                }
            }
            $kept[] = $wrong;
        }

        return $kept;
    }

    /**
     * What the game sends for a value that is not typed: items put in order joined by « | », the
     * place of the word pointed at (« correct » for a correct line; the first word for « any
     * word »). Null for the typed types, whose text is their display.
     */
    private static function sentText(AnswerSpec $spec, mixed $value): ?string
    {
        return match ($spec->type) {
            AnswerType::Order => \is_array($value) ? implode('|', array_map(static fn (mixed $item): string => \is_string($item) ? $item : '', $value)) : null,
            AnswerType::Spot => \is_int($value) ? match (true) {
                -1 === $value => 'correct',
                $value < -1 => '0',
                default => (string) $value,
            } : null,
            default => null,
        };
    }

    /** Whether an answer type is read as a number, where the closest wrong value wins. */
    public static function isNumeric(AnswerType $type): bool
    {
        return \in_array($type, [AnswerType::Number, AnswerType::Fraction, AnswerType::Power], true);
    }

    /**
     * @param list<mixed> $variants
     */
    private function chooseVariant(array $variants, SeededRandom $random): array
    {
        $weights = array_map(static fn (mixed $v): float => \is_array($v) && is_numeric($v['weight'] ?? null) ? (float) $v['weight'] : 1.0, $variants);
        $roll = $random->float() * array_sum($weights);
        foreach ($variants as $i => $variant) {
            $roll -= $weights[$i];
            if ($roll < 0 && \is_array($variant)) {
                return $variant;
            }
        }

        $last = $variants[\count($variants) - 1];

        return \is_array($last) ? $last : [];
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>
     */
    private function bind(mixed $bindings, array $variables, string $path): array
    {
        foreach (\is_array($bindings) ? $bindings : [] as $name => $expression) {
            $variables[(string) $name] = $this->evaluate($expression, $variables, $path.'.'.$name);
        }

        return $variables;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function holds(mixed $constraints, array $variables): bool
    {
        foreach (\is_array($constraints) ? $constraints : [] as $i => $constraint) {
            if (true !== $this->evaluate($constraint, $variables, 'constraints['.$i.']')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluates one expression. A JSON number, boolean or list is taken as it is - writing `3` or
     * `[1, 2]` is the same as writing the expression.
     *
     * @param array<string, mixed> $variables
     */
    private function evaluate(mixed $expression, array $variables, string $path): mixed
    {
        if (!\is_string($expression)) {
            return $expression;
        }

        try {
            return $this->language->evaluate($expression, $variables);
        } catch (\DivisionByZeroError|\ArithmeticError $e) {
            throw $e;
        } catch (TemplateFormatException $e) {
            throw new TemplateFormatException($path, $e->getMessage(), $e);
        } catch (SyntaxError $e) {
            throw new TemplateFormatException($path, 'expression illisible « '.$expression.' » : '.$e->getMessage(), $e);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Division by zero') || str_contains($e->getMessage(), 'Modulo by zero') || str_contains($e->getMessage(), 'Power of base 0')) {
                throw new RejectedDraw();
            }
            throw new TemplateFormatException($path, 'l’expression « '.$expression.' » échoue : '.$e->getMessage(), $e);
        }
    }

    /** @param array<string, mixed> $variables */
    private function string(mixed $expression, array $variables, string $path): string
    {
        $value = $this->evaluate($expression, $variables, $path);

        return self::write($value, $path);
    }

    /**
     * A text with `{{ expression }}` inside: each expression is evaluated and written - a number
     * the French way, a text as it is.
     *
     * @param array<string, mixed> $variables
     */
    private function render(string $template, array $variables, string $path): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/s',
            fn (array $m): string => self::write($this->evaluate($m[1], $variables, $path), $path),
            $template,
        );
    }

    private static function write(mixed $value, string $path): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => MathFormat::fmt($value),
            null === $value, \is_bool($value) => '',
            default => throw new TemplateFormatException($path, 'une valeur à écrire était attendue, '.get_debug_type($value).' reçu'),
        };
    }

    private function valueOf(AnswerChecker $checker, AnswerSpec $spec, mixed $raw, string $path): mixed
    {
        try {
            return $checker->value($raw, $spec);
        } catch (\InvalidArgumentException $e) {
            if (self::isNumeric($spec->type) && null === $raw) {
                throw new RejectedDraw();
            }
            throw new TemplateFormatException($path, $e->getMessage().' pour le type « '.$spec->type->value.' »', $e);
        }
    }

    private function defaultDisplay(AnswerSpec $spec, mixed $value): string
    {
        return match ($spec->type) {
            AnswerType::Set => MathFormat::setHtml(\is_array($value) ? array_values(array_filter($value, is_float(...))) : []),
            AnswerType::Interval => \is_array($value) && isset($value['lo'], $value['hi']) && \is_float($value['lo']) && \is_float($value['hi'])
                ? MathFormat::intervalHtml(['lo' => $value['lo'], 'hi' => $value['hi'], 'lc' => true === ($value['lc'] ?? false), 'hc' => true === ($value['hc'] ?? false)])
                : '',
            AnswerType::Scientific => \is_array($value) && isset($value['m'], $value['e']) && \is_float($value['m']) && \is_int($value['e']) ? MathFormat::sci($value['m'], $value['e']) : '',
            // a Latin form in italics, its first accepted writing; a table, its cells in order
            AnswerType::Latin => \is_array($value) && \is_string($value[0] ?? null) ? '<i>'.htmlspecialchars($value[0], \ENT_NOQUOTES).'</i>' : '',
            AnswerType::Text => \is_array($value) && \is_string($value[0] ?? null) ? htmlspecialchars($value[0], \ENT_NOQUOTES) : '',
            AnswerType::Sequence => \is_string($value) ? '<span class="q-seq">'.htmlspecialchars(trim((string) preg_replace('/(.{3})/', '$1 ', $value)), \ENT_NOQUOTES).'</span>' : '',
            AnswerType::Order => \is_array($value) ? implode(' → ', array_map(static fn (mixed $item): string => \is_string($item) ? htmlspecialchars($item, \ENT_NOQUOTES) : '', $value)) : '',
            AnswerType::Spot => \is_int($value) ? ($value < 0 ? 'La phrase est correcte.' : '<b>'.htmlspecialchars($spec->labels[$value] ?? '', \ENT_NOQUOTES).'</b>') : '',
            AnswerType::Forms => \is_array($value) ? implode(' · ', array_map(static fn (mixed $cell): string => \is_array($cell) && \is_string($cell[0] ?? null) ? '<i>'.htmlspecialchars($cell[0], \ENT_NOQUOTES).'</i>' : '', $value)) : '',
            default => \is_int($value) || \is_float($value) ? MathFormat::fmt($value) : (\is_string($value) ? $value : ''),
        };
    }

    /**
     * @param array<string, mixed> $figure
     * @param array<string, mixed> $variables
     */
    private function figure(array $figure, array $variables): ?string
    {
        if (isset($figure['when']) && true !== $this->evaluate($figure['when'], $variables, 'figure.when')) {
            return null;
        }

        $evaluated = ['type' => $figure['type'] ?? null];
        foreach ($figure as $key => $value) {
            if (\in_array($key, ['type', 'when', 'items'], true)) {
                continue;
            }
            $evaluated[$key] = $this->evaluate($value, $variables, 'figure.'.$key);
        }

        $items = [];
        foreach (\is_array($figure['items'] ?? null) ? $figure['items'] : [] as $i => $item) {
            if (!\is_array($item)) {
                continue;
            }
            if (isset($item['when']) && true !== $this->evaluate($item['when'], $variables, 'figure.items['.$i.'].when')) {
                continue;
            }
            $drawn = [];
            foreach ($item as $key => $value) {
                $drawn[$key] = \in_array($key, ['draw', 'color', 'dashed', 'when'], true) ? $value : $this->evaluate($value, $variables, 'figure.items['.$i.'].'.$key);
            }
            $items[] = $drawn;
        }
        $evaluated['items'] = $items;

        return TemplateFigures::render($evaluated);
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, true>     $ids
     */
    private function validateBody(array $body, string $path, array $ids): void
    {
        foreach (['prompt', 'method'] as $key) {
            if (!\is_string($body[$key] ?? null) || '' === trim($body[$key])) {
                throw new TemplateFormatException($path.$key, 'texte attendu');
            }
            $this->lintTemplate($body[$key], $path.$key);
        }

        if (!\is_array($body['answer'] ?? null)) {
            throw new TemplateFormatException($path.'answer', 'objet { type, value } attendu');
        }
        $answer = $body['answer'];
        self::onlyKeys($answer, self::ANSWER_KEYS, $path.'answer');
        foreach (['tolerance', 'vars', 'target', 'labels'] as $key) {
            $this->lint($answer[$key] ?? null, $path.'answer.'.$key);
        }
        try {
            // Settings computed per question are checked for their presence here, their value
            // when a question is drawn.
            $spec = AnswerSpec::fromArray([
                'tolerance' => \is_string($answer['tolerance'] ?? null) ? null : ($answer['tolerance'] ?? null),
                'vars' => \is_string($answer['vars'] ?? null) ? ['x'] : ($answer['vars'] ?? null),
                'target' => isset($answer['target']) ? 'x' : null,
                'labels' => \is_string($answer['labels'] ?? null) ? ['a', 'b'] : ($answer['labels'] ?? null),
            ] + $answer);
        } catch (TemplateFormatException $e) {
            throw new TemplateFormatException($path.$e->path, str_replace($e->path.' : ', '', $e->getMessage()));
        }
        if (AnswerType::Polynomial === $spec->type && null === $spec->form) {
            throw new TemplateFormatException($path.'answer.form', 'un polynôme précise sa forme (developed, factored, factoredMax)');
        }
        if (!\array_key_exists('value', $answer)) {
            throw new TemplateFormatException($path.'answer.value', 'valeur attendue');
        }
        foreach (['value', 'display', 'text'] as $key) {
            $this->lint($answer[$key] ?? null, $path.'answer.'.$key);
        }
        if (isset($answer['shapeMisconception']) && (!\is_string($answer['shapeMisconception']) || !isset($ids[$answer['shapeMisconception']]))) {
            throw new TemplateFormatException($path.'answer.shapeMisconception', 'erreur non déclarée dans « misconceptions »');
        }

        foreach (self::listOf($body['wrongs'] ?? [], $path.'wrongs') as $i => $wrong) {
            $wrongPath = $path.'wrongs['.$i.']';
            if (!\is_array($wrong)) {
                throw new TemplateFormatException($wrongPath, 'objet { misconception, value } attendu');
            }
            self::onlyKeys($wrong, self::WRONG_KEYS, $wrongPath);
            if (!\is_string($wrong['misconception'] ?? null) || !isset($ids[$wrong['misconception']])) {
                throw new TemplateFormatException($wrongPath.'.misconception', 'erreur non déclarée dans « misconceptions »');
            }
            if (!\array_key_exists('value', $wrong)) {
                throw new TemplateFormatException($wrongPath.'.value', 'valeur fautive attendue');
            }
            foreach (['value', 'display', 'text', 'when'] as $key) {
                $this->lint($wrong[$key] ?? null, $wrongPath.'.'.$key);
            }
        }

        if (isset($body['minWrongs']) && (!\is_int($body['minWrongs']) || $body['minWrongs'] < 1)) {
            throw new TemplateFormatException($path.'minWrongs', 'entier positif attendu');
        }

        if (isset($body['figure'])) {
            $this->validateFigure($body['figure'], $path.'figure');
        }
    }

    private function validateFigure(mixed $figure, string $path): void
    {
        if (!\is_array($figure)) {
            throw new TemplateFormatException($path, 'objet attendu');
        }

        $type = $figure['type'] ?? null;
        if (\is_string($type) && isset(TemplateFigures::KEYS[$type])) {
            self::onlyKeys($figure, ['type', 'when', ...TemplateFigures::KEYS[$type]], $path);
            foreach (['when', ...TemplateFigures::KEYS[$type]] as $key) {
                $this->lint($figure[$key] ?? null, $path.'.'.$key);
            }

            return;
        }

        if ('grid' !== $type) {
            throw new TemplateFormatException($path.'.type', 'figure inconnue (attendu : '.implode(', ', TemplateFigures::TYPES).')');
        }

        self::onlyKeys($figure, ['type', 'when', 'x', 'y', 'unit', 'items'], $path);
        foreach (['x', 'y', 'unit', 'when'] as $key) {
            $this->lint($figure[$key] ?? null, $path.'.'.$key);
        }
        foreach (self::listOf($figure['items'] ?? [], $path.'.items') as $i => $item) {
            $itemPath = $path.'.items['.$i.']';
            if (!\is_array($item) || !\is_string($item['draw'] ?? null) || !isset(TemplateFigures::GRID_ITEMS[$item['draw']])) {
                throw new TemplateFormatException($itemPath.'.draw', 'élément inconnu (attendu : '.implode(', ', array_keys(TemplateFigures::GRID_ITEMS)).')');
            }
            foreach (TemplateFigures::GRID_ITEMS[$item['draw']] as $required) {
                if (!\array_key_exists($required, $item)) {
                    throw new TemplateFormatException($itemPath.'.'.$required, 'attendu pour « '.$item['draw'].' »');
                }
            }
            foreach ($item as $key => $value) {
                if (!\in_array($key, ['draw', 'color', 'dashed'], true)) {
                    $this->lint($value, $itemPath.'.'.$key);
                }
            }
        }
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function validateBindings(array $node, string $path): void
    {
        foreach (['params', 'let'] as $key) {
            if (!isset($node[$key])) {
                continue;
            }
            if (!\is_array($node[$key]) || array_is_list($node[$key]) && [] !== $node[$key]) {
                throw new TemplateFormatException($path.$key, 'objet { nom: expression } attendu');
            }
            foreach ($node[$key] as $name => $expression) {
                if (!\is_string($name) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) || \in_array($name, self::RESERVED, true)) {
                    throw new TemplateFormatException($path.$key.'.'.$name, 'nom de valeur invalide ou réservé');
                }
                $this->lint($expression, $path.$key.'.'.$name);
            }
        }

        foreach (self::listOf($node['constraints'] ?? [], $path.'constraints') as $i => $constraint) {
            if (!\is_string($constraint)) {
                throw new TemplateFormatException($path.'constraints['.$i.']', 'expression attendue');
            }
            $this->lint($constraint, $path.'constraints['.$i.']');
        }
    }

    private function validateDifficulty(mixed $difficulty, string $path): void
    {
        if (null === $difficulty || \is_int($difficulty)) {
            return;
        }

        if (!\is_string($difficulty)) {
            throw new TemplateFormatException($path, 'entier ou expression attendu');
        }
        $this->lint($difficulty, $path);
    }

    private function lint(mixed $expression, string $path): void
    {
        if (!\is_string($expression)) {
            return;
        }

        try {
            $this->language->lint($expression, [], Parser::IGNORE_UNKNOWN_VARIABLES);
        } catch (SyntaxError $e) {
            throw new TemplateFormatException($path, 'expression illisible « '.$expression.' » : '.$e->getMessage(), $e);
        }
    }

    private function lintTemplate(string $template, string $path): void
    {
        preg_match_all('/\{\{\s*(.+?)\s*\}\}/s', $template, $matches);
        foreach ($matches[1] as $expression) {
            $this->lint($expression, $path);
        }
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string>            $allowed
     */
    private static function onlyKeys(array $node, array $allowed, string $path): void
    {
        foreach (array_keys($node) as $key) {
            if (!\in_array($key, $allowed, true)) {
                throw new TemplateFormatException(('' === $path ? '' : $path.'.').$key, 'clé inconnue (attendu : '.implode(', ', $allowed).')');
            }
        }
    }

    /** @return list<mixed> */
    private static function listOf(mixed $value, string $path): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new TemplateFormatException($path, 'liste attendue');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $node */
    private static function stringAt(array $node, string $key): string
    {
        return \is_string($node[$key] ?? null) ? $node[$key] : '';
    }

    /** The parser of expressions in x, exposed for the corrector. */
    public static function expectedTerms(string $value): int
    {
        return MathExpression::coefficientCount($value);
    }
}
