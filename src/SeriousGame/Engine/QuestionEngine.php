<?php

declare(strict_types=1);

namespace App\SeriousGame\Engine;

use App\SeriousGame\SeriousGameManifest;
use App\SeriousGame\Template\SeededRandom;
use App\SeriousGame\Template\TemplateDefinition;
use App\SeriousGame\Template\TemplateInterpreter;

/**
 * Generates and corrects the questions of a game (design/validated/serious-games.md §5.5).
 *
 * - generate() / check(): one question from a template version and a seed - the same pair always
 *   gives the same question, which is how a teacher replays « la question vue ».
 * - seriesPlan(), finalPlan(), cometPlan(): the questions of a session, decided when it starts -
 *   each with **its own seed, drawn at random for this player**, so two students never get the
 *   same series; a series holds no question twice.
 * - servableUnits(): a unit whose template asks for an answer kind the game does not accept is
 *   hidden - never offered to a student.
 *
 * Holds no state and writes nothing: the sessions are App\SeriousGame\SeriousGamePlay's.
 *
 * @phpstan-import-type PlannedQuestion from \App\Entity\SeriousGameSession
 */
final class QuestionEngine
{
    /** The largest seed: what a JavaScript number still holds exactly. */
    public const int MAX_SEED = 9007199254740991;

    private const int MAX_DRAWS = 200;

    /** @var \Closure(): int */
    private \Closure $seeds;

    /**
     * @param (\Closure(): int)|null $seeds where the seeds come from; random_int() unless a test pins them
     */
    public function __construct(
        private readonly TemplateInterpreter $interpreter = new TemplateInterpreter(),
        private readonly AnswerCorrector $corrector = new AnswerCorrector(),
        ?\Closure $seeds = null,
    ) {
        $this->seeds = $seeds ?? static fn (): int => random_int(1, self::MAX_SEED);
    }

    public function interpreter(): TemplateInterpreter
    {
        return $this->interpreter;
    }

    public function generate(TemplateDefinition $template, int $seed): GeneratedQuestion
    {
        return $this->interpreter->generate($template, $seed);
    }

    public function check(TemplateDefinition $template, int $seed, string $input): CheckResult
    {
        return $this->corrector->check($this->generate($template, $seed), $input);
    }

    public function correct(GeneratedQuestion $question, string $input): CheckResult
    {
        return $this->corrector->check($question, $input);
    }

    public function newSeed(): int
    {
        return ($this->seeds)();
    }

    /**
     * The units a student may be offered: a validated template, of an answer kind the game accepts.
     *
     * @param list<string> $answerKinds
     *
     * @return list<string>
     */
    public function servableUnits(SeriousGameManifest $manifest, TemplateSource $source, array $answerKinds): array
    {
        return array_values(array_filter(
            $manifest->unitCodes(),
            static fn (string $unit): bool => null !== ($template = $source->current($unit)) && $template->servableWith($answerKinds),
        ));
    }

    /**
     * $size questions on one unit, none twice, each of a kind the game accepts - a template that
     * mixes QCM and typed answers serves only its typed ones to a game that accepts no QCM.
     *
     * With `tiers` (the manifest's `series.tiers`, Station Abysse's 200, 1 000 and 3 000 m), the
     * series climbs: so many questions of difficulty 1, then of difficulty 2, then 3 - the last tier
     * taking every difficulty above it. A template that has no question of a tier lends the tier the
     * nearest difficulty it has, easier first, so that a series is always whole.
     *
     * @param list<string>|null $kinds the kinds of answer the game accepts; null for every kind
     * @param list<int>         $tiers how many questions of each difficulty, in order; [] for none
     *
     * @return list<PlannedQuestion>
     */
    public function seriesPlan(TemplateDefinition $template, int $size, ?array $kinds = null, array $tiers = []): array
    {
        if ([] !== $tiers) {
            return $this->tieredPlan($template, $kinds, $tiers);
        }

        $plan = [];
        $seen = [];
        for ($draw = 0; \count($plan) < $size && $draw < self::MAX_DRAWS; ++$draw) {
            $seed = $this->newSeed();
            $question = $this->generate($template, $seed);
            $identity = $question->identity();
            if (isset($seen[$identity]) || !self::accepts($question, $kinds)) {
                continue;
            }
            $seen[$identity] = true;
            $plan[] = ['unit' => $template->unit, 'version' => $template->version, 'seed' => $seed];
        }

        return $plan;
    }

    /**
     * @param list<string>|null $kinds
     * @param list<int>         $tiers
     *
     * @return list<PlannedQuestion>
     */
    private function tieredPlan(TemplateDefinition $template, ?array $kinds, array $tiers): array
    {
        $levels = \count($tiers);
        $buckets = array_fill(1, $levels, []);
        $seen = [];
        $needed = array_sum($tiers);
        for ($draw = 0; $draw < 2 * self::MAX_DRAWS; ++$draw) {
            $full = true;
            foreach ($tiers as $i => $count) {
                if (\count($buckets[$i + 1]) < $count) {
                    $full = false;
                }
            }
            // a tier the template cannot fill is not waited for longer than a plain series would be
            if ($full || $draw >= self::MAX_DRAWS && array_sum(array_map(count(...), $buckets)) >= $needed) {
                break;
            }
            $seed = $this->newSeed();
            $question = $this->generate($template, $seed);
            if (isset($seen[$question->identity()]) || !self::accepts($question, $kinds)) {
                continue;
            }
            $seen[$question->identity()] = true;
            $level = max(1, min($levels, $question->difficulty));
            $buckets[$level][] = ['unit' => $template->unit, 'version' => $template->version, 'seed' => $seed];
        }

        // Each tier takes its own questions, then borrows from the nearest tiers, easier first.
        $plan = [];
        $used = array_fill(1, $levels, 0);
        foreach ($tiers as $i => $count) {
            $level = $i + 1;
            $taken = 0;
            for ($distance = 0; $taken < $count && $distance < $levels; ++$distance) {
                foreach (array_unique([$level - $distance, $level + $distance]) as $from) {
                    while ($taken < $count && isset($buckets[$from]) && $used[$from] < \count($buckets[$from])) {
                        $plan[] = $buckets[$from][$used[$from]++];
                        ++$taken;
                    }
                }
            }
        }

        return \array_slice($plan, 0, $needed);
    }

    /**
     * The final test: from each chapter, as many distinct units as its share of the
     * distribution (3 + 3 + 2 + 2 + 2, shuffled between chapters), one question each, the whole
     * shuffled.
     *
     * @param list<string>      $servable the units that may be served
     * @param list<string>|null $kinds    the kinds of answer the game accepts; null for every kind
     *
     * @return list<PlannedQuestion>
     */
    public function finalPlan(SeriousGameManifest $manifest, TemplateSource $source, array $servable, ?array $kinds = null): array
    {
        $random = new SeededRandom($this->newSeed(), 'final');
        $counts = $random->shuffle($manifest->finalPerChapter());
        $units = [];
        foreach ($manifest->chapters() as $i => $chapter) {
            $codes = array_values(array_filter(array_column($chapter['units'], 'code'), static fn (string $code): bool => \in_array($code, $servable, true)));
            foreach (\array_slice($random->shuffle($codes), 0, $counts[$i] ?? 0) as $code) {
                $units[] = $code;
            }
        }

        $plan = [];
        foreach ($random->shuffle($units) as $unit) {
            $template = $source->current($unit);
            if (null === $template) {
                continue;
            }
            for ($draw = 0; $draw < self::MAX_DRAWS; ++$draw) {
                $seed = $this->newSeed();
                if (self::accepts($this->generate($template, $seed), $kinds)) {
                    $plan[] = ['unit' => $unit, 'version' => $template->version, 'seed' => $seed];
                    break;
                }
            }
        }

        return $plan;
    }

    /**
     * $size questions of the unit a typical error belongs to, each one able to show that very
     * error - or, for an error read from the shape of an answer, plain questions of the unit.
     *
     * @param list<string>|null $kinds the kinds of answer the game accepts; null for every kind
     *
     * @return list<PlannedQuestion>
     */
    public function cometPlan(TemplateDefinition $template, string $misconception, int $size, ?array $kinds = null): array
    {
        $plan = [];
        $seen = [];
        $fallback = [];
        for ($draw = 0; \count($plan) < $size && $draw < 2 * self::MAX_DRAWS; ++$draw) {
            $seed = $this->newSeed();
            $question = $this->generate($template, $seed);
            if (isset($seen[$question->identity()]) || !self::accepts($question, $kinds)) {
                continue;
            }
            $seen[$question->identity()] = true;
            $planned = ['unit' => $template->unit, 'version' => $template->version, 'seed' => $seed];
            if (\in_array($misconception, array_column($question->wrongs, 'misconception'), true)) {
                $plan[] = $planned;
            } elseif (\count($fallback) < $size) {
                $fallback[] = $planned;
            }
        }

        return [...$plan, ...\array_slice($fallback, 0, $size - \count($plan))];
    }

    /** @param list<string>|null $kinds */
    private static function accepts(GeneratedQuestion $question, ?array $kinds): bool
    {
        return null === $kinds || \in_array($question->kind, $kinds, true);
    }

    /**
     * The two sentences that open a comet's study: the template's own explanation when it writes
     * one, else the reference's - the error named, then the method of the first question.
     */
    public function cometIntroduction(TemplateDefinition $template, string $misconception, ?GeneratedQuestion $example): string
    {
        foreach ($template->misconceptions() as $entry) {
            if ($entry['id'] === $misconception) {
                if (null !== $entry['explanation']) {
                    return $entry['explanation'];
                }

                $label = $entry['label'];

                return 'L’erreur qui revient : '.mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1).'. '.(null === $example ? '' : $example->method);
            }
        }

        return null === $example ? '' : $example->method;
    }
}
