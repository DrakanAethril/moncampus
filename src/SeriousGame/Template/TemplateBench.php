<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

use App\SeriousGame\Answer\AnswerCheckers;
use App\SeriousGame\Engine\AnswerCorrector;
use App\SeriousGame\Engine\GeneratedQuestion;
use App\SeriousGame\Engine\QuestionEngine;
use App\SeriousGame\Math\MathFormat;

/**
 * The bench of a template (design/validated/serious-games.md §5.3) - what makes a version
 * validatable. Over a fixed list of seeds, every question must hold the same invariants as the
 * reference's JavaScript bench:
 *
 * - the expected answer is accepted, as typed and as displayed;
 * - every wrong value is refused **and** recognised as its own typical error, not another one;
 * - the wrong values of a question are distinct from one another and from the right answer;
 * - « je ne sais pas » is accepted, attributed to no error;
 * - no NaN, infinity or `{{` left in the statement, the method or the answer;
 * - the same seed and the same version give the same question;
 * - fewer than 200 rejected draws per question;
 *
 * and, over a few series, a series of 12 holds no question twice, and every typical error gets
 * the questions of its comet. The report is JSON, stored on the version (`bench_report`).
 *
 * @phpstan-type BenchReport array{green: bool, unit: string, version: int, seeds: int, questions: int, wrongs: int, recognised: int, maxAttempts: int, failures: list<array{seed: int|null, rule: string, detail: string}>, neverSeen: list<string>, engine: string, ranAt: string}
 */
final class TemplateBench
{
    public const string ENGINE_VERSION = '2.0.0';
    private const int MAX_FAILURES = 25;
    private const int SERIES = 5;

    public function __construct(private readonly TemplateInterpreter $interpreter = new TemplateInterpreter())
    {
    }

    /**
     * The seeds the bench reads: fixed, so a report can be reproduced.
     *
     * @return list<int>
     */
    public static function seeds(int $count): array
    {
        return array_map(static fn (int $i): int => ($i * 2654435761 + 1) % QuestionEngine::MAX_SEED, range(1, max(1, $count)));
    }

    /**
     * @return BenchReport
     */
    public function run(TemplateDefinition $template, int $seeds = 1000): array
    {
        $failures = [];
        $fail = static function (?int $seed, string $rule, string $detail) use (&$failures): void {
            if (\count($failures) < self::MAX_FAILURES) {
                $failures[] = ['seed' => $seed, 'rule' => $rule, 'detail' => mb_substr($detail, 0, 300)];
            }
        };

        $questions = 0;
        $wrongCount = 0;
        $recognised = 0;
        $maxAttempts = 0;
        $seen = [];
        $corrector = new AnswerCorrector();

        try {
            $this->interpreter->validate($template->document);
        } catch (TemplateFormatException $e) {
            $fail(null, 'format', $e->getMessage());

            return $this->report($template, $seeds, 0, 0, 0, 0, $failures, []);
        }

        foreach (self::seeds($seeds) as $seed) {
            try {
                $question = $this->interpreter->generate($template, $seed);
            } catch (TemplateExhaustedException $e) {
                $fail($seed, 'exhausted', $e->getMessage());

                continue;
            } catch (TemplateFormatException $e) {
                $fail($seed, 'format', $e->getMessage());

                break;
            }

            ++$questions;
            $maxAttempts = max($maxAttempts, $question->attempts);
            $summary = MathFormat::plain($question->prompt);

            foreach (['prompt' => $question->prompt, 'method' => $question->method, 'answer' => $question->answerDisplay.' '.$question->answerText, 'figure' => (string) $question->figure] as $part => $text) {
                if (preg_match('/NaN|\bINF\b|Infinity|\{\{|\}\}|\[object/', $text)) {
                    $fail($seed, 'bad-token', $part.' : '.mb_substr(MathFormat::plain($text), 0, 160));
                }
            }

            $typed = $corrector->check($question, $question->answerText);
            if (!$typed->ok) {
                $fail($seed, 'answer-typed', '« '.$question->answerText.' » refusée '.self::why($typed).' | '.$summary);
            }
            $shown = trim(MathFormat::plain($question->answerDisplay));
            $displayed = $corrector->check($question, $shown);
            if (!$displayed->ok) {
                $fail($seed, 'answer-displayed', '« '.$shown.' » refusée '.self::why($displayed).' | '.$summary);
            }

            $dontKnow = $corrector->check($question, AnswerCorrector::DONT_KNOW);
            if ($dontKnow->ok || !$dontKnow->dontKnow || null !== $dontKnow->misconception) {
                $fail($seed, 'dont-know', 'mal traité');
            }

            $checker = AnswerCheckers::for($question->spec->type);
            foreach ($question->wrongs as $i => $wrong) {
                ++$wrongCount;
                $seen[$wrong['misconception']] = true;
                $result = $corrector->check($question, $wrong['text']);
                if ($result->ok) {
                    $fail($seed, 'wrong-accepted', '« '.$wrong['text'].' » ('.$wrong['misconception'].') acceptée | '.$summary);
                } elseif ($result->misconception !== $wrong['misconception']) {
                    $fail($seed, 'wrong-not-recognised', '« '.$wrong['text'].' » lue '.($result->misconception ?? 'sans erreur').self::why($result).', attendu '.$wrong['misconception'].' | '.$summary);
                } else {
                    ++$recognised;
                }

                if ($checker->same($wrong['value'], $question->answerValue, $question->spec, $question->spec->tolerance && TemplateInterpreter::isNumeric($question->spec->type) ? min($question->spec->tolerance, .049) : null)) {
                    $fail($seed, 'wrong-equals-answer', $wrong['misconception']);
                }
                foreach (\array_slice($question->wrongs, $i + 1) as $other) {
                    if ($checker->same($wrong['value'], $other['value'], $question->spec)) {
                        $fail($seed, 'wrongs-not-distinct', $wrong['misconception'].' / '.$other['misconception']);
                    }
                }
            }
            if (null !== $question->shapeMisconception) {
                $seen[$question->shapeMisconception] = true;
            }

            $again = $this->interpreter->generate($template, $seed);
            if ($again->identity() !== $question->identity() || $again->answerText !== $question->answerText) {
                $fail($seed, 'not-deterministic', $summary);
            }
        }

        $this->benchSeries($template, $fail);
        $neverSeen = array_values(array_diff(array_column($template->misconceptions(), 'id'), array_keys($seen)));

        return $this->report($template, $seeds, $questions, $wrongCount, $recognised, $maxAttempts, $failures, $neverSeen);
    }

    /**
     * @param \Closure(?int, string, string): void $fail
     */
    private function benchSeries(TemplateDefinition $template, \Closure $fail): void
    {
        $seeds = self::seeds(400);
        $engine = new QuestionEngine($this->interpreter, new AnswerCorrector(), static function () use (&$seeds): int {
            return array_shift($seeds) ?? random_int(1, QuestionEngine::MAX_SEED);
        });

        try {
            for ($s = 0; $s < self::SERIES; ++$s) {
                $plan = $engine->seriesPlan($template, 12);
                if (12 !== \count($plan)) {
                    $fail(null, 'series-length', 'une série de 12 n’a donné que '.\count($plan).' questions distinctes');
                }
            }

            foreach ($template->misconceptions() as $entry) {
                if (3 !== \count($engine->cometPlan($template, $entry['id'], 3))) {
                    $fail(null, 'comet-short', 'la comète '.$entry['id'].' n’a pas ses 3 questions');
                }
            }
        } catch (TemplateExhaustedException|TemplateFormatException $e) {
            $fail(null, 'series', $e->getMessage());
        }
    }

    private static function why(\App\SeriousGame\Engine\CheckResult $result): string
    {
        return match (true) {
            $result->unreadable => ' (illisible)',
            null !== $result->formIssue => ' (forme : '.$result->formIssue.')',
            null !== $result->misconception => ' (lue '.$result->misconception.')',
            default => '',
        };
    }

    /**
     * @param list<array{seed: int|null, rule: string, detail: string}> $failures
     * @param list<string>                                               $neverSeen
     *
     * @return BenchReport
     */
    private function report(TemplateDefinition $template, int $seeds, int $questions, int $wrongs, int $recognised, int $maxAttempts, array $failures, array $neverSeen): array
    {
        return [
            'green' => [] === $failures && $questions > 0,
            'unit' => $template->unit,
            'version' => $template->version,
            'seeds' => $seeds,
            'questions' => $questions,
            'wrongs' => $wrongs,
            'recognised' => $recognised,
            'maxAttempts' => $maxAttempts,
            'failures' => $failures,
            'neverSeen' => $neverSeen,
            'engine' => self::ENGINE_VERSION,
            'ranAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
    }

    /** One question as the bench and the connector show it: statement, answer, wrong answers. */
    public static function describe(GeneratedQuestion $question): array
    {
        return [
            'seed' => $question->seed,
            'prompt' => MathFormat::plain($question->prompt),
            'hasFigure' => null !== $question->figure,
            'answer' => $question->answerText,
            'wrongs' => array_map(static fn (array $w): array => ['misconception' => $w['misconception'], 'input' => $w['text']], $question->wrongs),
            'method' => MathFormat::plain($question->method),
        ];
    }
}
