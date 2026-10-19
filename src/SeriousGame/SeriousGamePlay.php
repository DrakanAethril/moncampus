<?php

declare(strict_types=1);

namespace App\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameAnswer;
use App\Entity\SeriousGameComet;
use App\Entity\SeriousGameProgress;
use App\Entity\SeriousGameRewardGrant;
use App\Entity\SeriousGameSession;
use App\Entity\SeriousGameUnitState;
use App\Entity\User;
use App\Enum\SeriousGameBrightness;
use App\Enum\SeriousGameSessionMode;
use App\Repository\SeriousGameAnswerRepository;
use App\Repository\SeriousGameCometRepository;
use App\Repository\SeriousGameProgressRepository;
use App\Repository\SeriousGameRewardGrantRepository;
use App\Repository\SeriousGameSessionRepository;
use App\Repository\SeriousGameTemplateRepository;
use App\Repository\SeriousGameUnitStateRepository;
use App\SeriousGame\Avatar\SeriousGameAvatars;
use App\SeriousGame\Engine\CheckResult;
use App\SeriousGame\Engine\DatabaseTemplateSource;
use App\SeriousGame\Engine\GeneratedQuestion;
use App\SeriousGame\Engine\QuestionEngine;
use App\SeriousGame\Engine\TemplateSource;
use App\SeriousGame\Rules\AcquisitionRules;
use App\SeriousGame\Rules\RewardRules;
use App\Service\Accommodation\AccommodationResolver;
use App\Service\Accommodation\ExtraTime;
use App\Service\SeriousGame\SeriousGameRules;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the game asks of the platform (design/validated/serious-games.md §6.2) - the server side of
 * the SDK. **The server generates and corrects**: the game receives a statement without its answer,
 * sends what the student typed, and receives the correction - except during the final test, which
 * says nothing before the end. What a player acquires is written here alone, by the rules of
 * AcquisitionRules and RewardRules; the game's own `saveProgress()` only keeps its display state.
 *
 * Nothing depends on time but the final test's clock (§1.1): no expiry, no decay, no streak.
 *
 * @phpstan-import-type PlannedQuestion from SeriousGameSession
 *
 * @phpstan-type UnitProgress array{code: string, label: string, chapter: string, best: ?int, lit: bool, brightness: ?string, series: int, hidden: bool}
 * @phpstan-type ChapterProgress array{code: string, label: string, topic: string, lit: int, total: int}
 * @phpstan-type RewardView array{code: string, finish: ?string, kind: string, name: string, lore: string, chapter: ?string, grantedAt?: string, raised?: bool}
 * @phpstan-type CometView array{misconception: string, label: string, unit: string, unitName: string, state: string, attempts: int, appearedAt: string}
 * @phpstan-type BestiaryEntry array{misconception: string, label: string, unit: string, unitName: string, explanation: string, solvedAt: string}
 * @phpstan-type Progress array{units: list<UnitProgress>, chapters: list<ChapterProgress>, lit: int, total: int, grade: string, finished: bool, comets: list<CometView>, bestiary: list<BestiaryEntry>, bestiaryTotal: int, final: array{code: string, label: string, unlocked: bool, best: ?int, size: int, timeLimitSeconds: int}, collects: bool, rewards: list<RewardView>, data: array<string, mixed>|\stdClass}
 */
final class SeriousGamePlay implements \Symfony\Contracts\Service\ResetInterface
{
    /** @var array<int, TemplateSource> one source per game, for the request */
    private array $sources = [];

    /** Seconds forgiven after the final test's clock, for the answer already on its way. */
    private const int CLOCK_GRACE = 5;

    public function __construct(
        private readonly QuestionEngine $engine,
        private readonly SeriousGameTemplateRepository $templates,
        private readonly SeriousGameUnitStateRepository $states,
        private readonly SeriousGameSessionRepository $sessions,
        private readonly SeriousGameAnswerRepository $answers,
        private readonly SeriousGameCometRepository $comets,
        private readonly SeriousGameProgressRepository $progress,
        private readonly SeriousGameRewardGrantRepository $grants,
        private readonly SeriousGameAvatars $avatars,
        private readonly AccommodationResolver $accommodations,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function user(User $user): array
    {
        $percent = $this->accommodations->forUser($user)->quizExtraTimePercent;

        return [
            'firstName' => $user->getFirstname() ?? $user->getDisplayName() ?? $user->getUsername(),
            'avatar' => $this->avatars->configFor($user),
            'collects' => SeriousGameRules::canCollect($user->getRoles()),
            'extraTimePercent' => $percent,
        ];
    }

    /** @return Progress */
    public function progress(User $player, SeriousGame $game): array
    {
        $manifest = self::manifest($game);
        $source = $this->source($game);
        $servable = $this->engine->servableUnits($manifest, $source, $game->getAnswerKinds());
        $states = $this->states->findForPlayer($player, $game);

        $units = [];
        $chapters = [];
        $lit = 0;
        foreach ($manifest->chapters() as $chapter) {
            $chapterLit = 0;
            $chapterServable = 0;
            foreach ($chapter['units'] as $unit) {
                $state = $states[$unit['code']] ?? null;
                $best = $state?->getBest();
                $isLit = AcquisitionRules::isLit($best, $manifest->litAt());
                $hidden = !\in_array($unit['code'], $servable, true);
                $units[] = [
                    'code' => $unit['code'],
                    'label' => $unit['label'],
                    'chapter' => $chapter['code'],
                    'best' => $best,
                    'lit' => $isLit,
                    'brightness' => SeriousGameBrightness::fromBest($best)?->value,
                    'series' => $state?->getSeriesCount() ?? 0,
                    'hidden' => $hidden,
                ];
                if (!$hidden) {
                    ++$chapterServable;
                    $chapterLit += $isLit ? 1 : 0;
                }
            }
            $lit += $chapterLit;
            $chapters[] = ['code' => $chapter['code'], 'label' => $chapter['label'], 'topic' => $chapter['topic'], 'lit' => $chapterLit, 'total' => $chapterServable];
        }

        $collects = SeriousGameRules::canCollect($player->getRoles());
        $bestFinal = $this->sessions->bestFinal($player, $game);
        $progress = $this->progress->findOneFor($player, $game);

        return [
            'units' => $units,
            'chapters' => $chapters,
            'lit' => $lit,
            'total' => \count($servable),
            'grade' => $manifest->gradeFor($lit),
            'finished' => AcquisitionRules::finished($lit, \count($servable)),
            'comets' => array_map(fn (SeriousGameComet $comet): array => $this->cometView($game, $comet), $this->comets->findInView($player, $game)),
            'bestiary' => $this->bestiary($player, $game),
            'bestiaryTotal' => $this->knownMisconceptions($game, $servable),
            'final' => [
                'code' => $manifest->finalCode(),
                'label' => $manifest->finalLabel(),
                'unlocked' => AcquisitionRules::finished($lit, \count($servable)),
                'best' => $bestFinal,
                'size' => $manifest->finalSize(),
                'timeLimitSeconds' => $this->finalTimeLimit($player, $manifest),
            ],
            'collects' => $collects,
            'rewards' => $collects ? $this->rewardsView($player, $game) : [],
            'data' => null === $progress ? new \stdClass() : ($progress->getData() ?: new \stdClass()),
        ];
    }

    /** @return array<string, mixed> */
    public function startSeries(User $player, SeriousGame $game, string $unit): array
    {
        $manifest = self::manifest($game);
        $source = $this->source($game);
        if (!\in_array($unit, $this->engine->servableUnits($manifest, $source, $game->getAnswerKinds()), true)) {
            throw new SeriousGamePlayRefused('unit-unavailable');
        }

        $template = $source->current($unit) ?? throw new SeriousGamePlayRefused('unit-unavailable');
        $session = new SeriousGameSession($player, $game, SeriousGameSessionMode::Series, $this->engine->seriesPlan($template, $manifest->seriesSize(), $game->getAnswerKinds(), $manifest->seriesTiers()));
        $session->setUnitCode($unit);
        $this->entityManager->persist($session);
        $this->touch($player, $game);
        $this->entityManager->flush();

        return $this->sessionView($session);
    }

    /** @return array<string, mixed> */
    public function startFinal(User $player, SeriousGame $game): array
    {
        $manifest = self::manifest($game);
        $source = $this->source($game);
        $servable = $this->engine->servableUnits($manifest, $source, $game->getAnswerKinds());
        $states = $this->states->findForPlayer($player, $game);
        $lit = \count(array_filter($servable, static fn (string $unit): bool => AcquisitionRules::isLit(($states[$unit] ?? null)?->getBest(), $manifest->litAt())));
        if (!AcquisitionRules::finished($lit, \count($servable))) {
            throw new SeriousGamePlayRefused('final-locked');
        }

        $session = new SeriousGameSession($player, $game, SeriousGameSessionMode::Final, $this->engine->finalPlan($manifest, $source, $servable, $game->getAnswerKinds()));
        $session->setTimeLimitSeconds($this->finalTimeLimit($player, $manifest));
        $this->entityManager->persist($session);
        $this->touch($player, $game);
        $this->entityManager->flush();

        return $this->sessionView($session);
    }

    /** @return array<string, mixed> the explanation, and the session of three questions */
    public function studyComet(User $player, SeriousGame $game, string $misconception): array
    {
        $comet = $this->comets->findOneInView($player, $game, $misconception) ?? throw new SeriousGamePlayRefused('comet-not-in-view');
        $manifest = self::manifest($game);
        $template = $this->source($game)->current($comet->getUnitCode()) ?? throw new SeriousGamePlayRefused('unit-unavailable');

        $plan = $this->engine->cometPlan($template, $misconception, $manifest->cometQuestions(), $game->getAnswerKinds());
        $session = new SeriousGameSession($player, $game, SeriousGameSessionMode::Comet, $plan);
        $session->setUnitCode($comet->getUnitCode());
        $session->setMisconception($misconception);
        $this->entityManager->persist($session);
        $this->touch($player, $game);
        $this->entityManager->flush();

        $example = [] === $plan ? null : $this->engine->generate($template, $plan[0]['seed']);

        return $this->sessionView($session) + [
            'comet' => $this->cometView($game, $comet),
            'explanation' => $this->engine->cometIntroduction($template, $misconception, $example),
        ];
    }

    /**
     * The next question of a session: the statement, never the answer.
     *
     * @return array<string, mixed>
     */
    public function nextQuestion(User $player, SeriousGameSession $session): array
    {
        $this->assertOpen($session);
        $answers = $this->answers->findForSession($session);
        foreach ($session->getPlan() as $position => $planned) {
            $answer = $answers[$position] ?? null;
            if (null !== $answer && $answer->isAnswered()) {
                continue;
            }

            if (null === $answer) {
                $answer = new SeriousGameAnswer($session, $position, $planned['unit'], $planned['version'], $planned['seed']);
                $this->entityManager->persist($answer);
                $this->touch($player, $session->getGame());
                $this->entityManager->flush();
            }

            $question = $this->question($session->getGame(), $planned);

            return [
                'done' => false,
                'position' => $position,
                'size' => $session->getSize(),
                'deadline' => $session->getDeadline()?->format(\DATE_ATOM),
                'serverTime' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'question' => $question->publicView() + ['unitName' => self::manifest($session->getGame())->unit($planned['unit'])['label'] ?? $planned['unit']],
            ];
        }

        return ['done' => true, 'position' => null, 'size' => $session->getSize()];
    }

    /**
     * Corrects the answer to the question in progress.
     *
     * @return array<string, mixed>
     */
    public function answer(User $player, SeriousGameSession $session, string $input): array
    {
        $this->assertOpen($session);
        $current = null;
        foreach ($this->answers->findForSession($session) as $answer) {
            if (!$answer->isAnswered()) {
                $current = $answer;
                break;
            }
        }
        if (null === $current) {
            throw new SeriousGamePlayRefused('no-question-served');
        }

        $planned = $session->plannedAt($current->getPosition()) ?? throw new SeriousGamePlayRefused('no-question-served');
        $question = $this->question($session->getGame(), $planned);
        $result = $this->engine->correct($question, $input);
        $final = $session->isFinal();

        if ($final && null !== ($deadline = $session->getDeadline()) && new \DateTimeImmutable() > $deadline->modify('+'.self::CLOCK_GRACE.' seconds')) {
            throw new SeriousGamePlayRefused('time-is-up');
        }

        // A text that does not read is written again; in a series a right value in the wrong shape
        // too (« développer et réduire » left in brackets). In the final test the shape is part of
        // the answer, as on the épreuve, and saying so would tell the value was right.
        if ($result->unreadable || (!$final && null !== $result->formIssue)) {
            return ['recorded' => false, 'unreadable' => $result->unreadable, 'formIssue' => $result->formIssue];
        }

        $current->answer(self::stored($input), $result->ok, $result->misconception, $result->dontKnow);
        $this->touch($player, $session->getGame());
        $this->entityManager->flush();

        if ($final) {
            return ['recorded' => true];
        }

        return ['recorded' => true] + $this->correction($session->getGame(), $question, $result, $input);
    }

    /**
     * Ends a session: the score, what it lit, the comets it raised, the pieces it gave.
     *
     * @return array<string, mixed>
     */
    public function end(User $player, SeriousGameSession $session): array
    {
        if ($session->isEnded()) {
            throw new SeriousGamePlayRefused('session-ended');
        }

        $game = $session->getGame();
        $manifest = self::manifest($game);
        $answers = $this->answers->findForSession($session);
        $oks = array_map(static fn (SeriousGameAnswer $a): bool => $a->isAnswered() && $a->isOk(), array_values($answers));
        $answered = \count(array_filter($answers, static fn (SeriousGameAnswer $a): bool => $a->isAnswered()));

        $timedOut = false;
        if ($session->isFinal()) {
            $timedOut = $answered < $session->getSize();
        } elseif ($answered < $session->getSize()) {
            throw new SeriousGamePlayRefused('session-unfinished');
        }

        $score = AcquisitionRules::score($oks);
        $session->end($score, $timedOut);
        $out = ['score' => $score, 'size' => $session->getSize(), 'mode' => $session->getMode()->value];

        if (SeriousGameSessionMode::Series === $session->getMode()) {
            $unit = (string) $session->getUnitCode();
            $state = $this->states->findOneFor($player, $game, $unit);
            if (null === $state) {
                $state = new SeriousGameUnitState($player, $game, $unit);
                $this->entityManager->persist($state);
            }
            $before = $state->getBrightness();
            $newlyLit = $state->recordSeries($score);
            $out += [
                'unit' => $unit,
                'best' => $state->getBest(),
                'brightness' => $state->getBrightness()?->value,
                'brighter' => $state->getBrightness() !== $before && null !== $state->getBrightness(),
                'newlyLit' => $newlyLit,
            ];
        }

        if (SeriousGameSessionMode::Comet === $session->getMode()) {
            $comet = $this->comets->findOneInView($player, $game, (string) $session->getMisconception());
            $solved = AcquisitionRules::cometSolved($oks, $manifest->cometSolvedAt());
            $comet?->recordStudy($solved);
            $out += ['misconception' => $session->getMisconception(), 'solved' => $solved];
        }

        if ($session->isFinal()) {
            $out += ['timedOut' => $timedOut, 'review' => $this->review($session, $answers)];
        }

        $this->touch($player, $game);
        $this->entityManager->flush();

        $out['comets'] = array_map(fn (SeriousGameComet $comet): array => $this->cometView($game, $comet), $this->raiseComets($player, $game));
        $out['rewards'] = SeriousGameRules::canCollect($player->getRoles()) ? $this->grantRewards($player, $game) : [];
        if ($session->isFinal()) {
            $out['best'] = $this->sessions->bestFinal($player, $game);
        }
        $this->entityManager->flush();

        return $out;
    }

    /**
     * The game's display state - never the progress, which the server alone writes.
     *
     * @param array<string, mixed> $data
     */
    public function saveProgress(User $player, SeriousGame $game, array $data): void
    {
        $encoded = json_encode($data);
        if (false === $encoded || \strlen($encoded) > 20000) {
            throw new SeriousGamePlayRefused('data-too-large');
        }

        $this->progressFor($player, $game)->setData($data)->touch();
        $this->entityManager->flush();
    }

    /** The session a player opens in a game, or a refusal. */
    public function ownedSession(User $player, SeriousGame $game, int $id): SeriousGameSession
    {
        return $this->sessions->findOwned($id, $player, $game) ?? throw new SeriousGamePlayRefused('session-unknown');
    }

    /**
     * A question replayed exactly - for the teachers' reading.
     *
     * @param PlannedQuestion $planned
     */
    public function question(SeriousGame $game, array $planned): GeneratedQuestion
    {
        $template = $this->source($game)->version($planned['unit'], $planned['version']) ?? throw new SeriousGamePlayRefused('template-missing');

        return $this->engine->generate($template, $planned['seed']);
    }

    public static function manifest(SeriousGame $game): SeriousGameManifest
    {
        return new SeriousGameManifest($game->getManifest());
    }

    /** @return array<string, mixed> */
    private function correction(SeriousGame $game, GeneratedQuestion $question, CheckResult $result, string $input): array
    {
        $template = $this->source($game)->version($question->unit, $question->version);

        return [
            'ok' => $result->ok,
            'dontKnow' => $result->dontKnow,
            'misconception' => $result->misconception,
            'misconceptionLabel' => null === $result->misconception ? null : $template?->misconceptionLabel($result->misconception),
            'expected' => $question->answerDisplay,
            'expectedText' => $question->answerText,
            'unit_label' => $question->spec->unit,
            'method' => $question->method,
            'input' => self::stored($input),
        ] + self::cells($question, $input);
    }

    /**
     * For a table to fill, which cells were right and what each one expected - the correction marks
     * the cells, after the answer, never before. Items put in order are read the same way, one
     * place per cell.
     *
     * @return array{cells?: list<bool>, expectedCells?: list<string>}
     */
    private static function cells(GeneratedQuestion $question, ?string $input): array
    {
        if (Answer\AnswerType::Order === $question->spec->type && \is_array($question->answerValue)) {
            /** @var list<string> $order */
            $order = array_values(array_filter($question->answerValue, is_string(...)));
            $sent = null === $input || Engine\AnswerCorrector::DONT_KNOW === $input ? [] : array_map('trim', explode('|', $input));

            return ['cells' => Answer\OrderChecker::places($sent, $order), 'expectedCells' => $order];
        }
        if (Answer\AnswerType::Forms !== $question->spec->type || !\is_array($question->answerValue)) {
            return [];
        }
        /** @var list<list<string>> $expected */
        $expected = array_values(array_filter($question->answerValue, is_array(...)));
        $typed = null === $input || Engine\AnswerCorrector::DONT_KNOW === $input ? [] : array_map('trim', explode('|', $input));

        return [
            'cells' => Answer\FormsChecker::cells($typed, $expected),
            'expectedCells' => array_map(static fn (array $alternatives): string => (string) ($alternatives[0] ?? ''), $expected),
        ];
    }

    /**
     * @param array<int, SeriousGameAnswer> $answers
     *
     * @return list<array<string, mixed>>
     */
    private function review(SeriousGameSession $session, array $answers): array
    {
        $review = [];
        foreach ($session->getPlan() as $position => $planned) {
            $answer = $answers[$position] ?? null;
            $question = $this->question($session->getGame(), $planned);
            $template = $this->source($session->getGame())->version($planned['unit'], $planned['version']);
            $review[] = [
                'position' => $position,
                'unit' => $planned['unit'],
                'unitName' => self::manifest($session->getGame())->unit($planned['unit'])['label'] ?? $planned['unit'],
                'prompt' => $question->prompt,
                'figure' => $question->figure,
                'input' => $answer?->getInput(),
                'answered' => null !== $answer && $answer->isAnswered(),
                'ok' => null !== $answer && $answer->isOk(),
                'dontKnow' => null !== $answer && $answer->isDontKnow(),
                'misconception' => $answer?->getMisconception(),
                'misconceptionLabel' => null === $answer?->getMisconception() ? null : $template?->misconceptionLabel((string) $answer->getMisconception()),
                'expected' => $question->answerDisplay,
                'method' => $question->method,
                'answerType' => $question->spec->type->value,
                'labels' => $question->spec->labels,
            ] + self::cells($question, $answer?->getInput());
        }

        return $review;
    }

    /** @return list<SeriousGameComet> the comets raised now */
    private function raiseComets(User $player, SeriousGame $game): array
    {
        $manifest = self::manifest($game);
        $inView = array_map(static fn (SeriousGameComet $c): string => $c->getMisconception(), $this->comets->findInView($player, $game));
        $raised = [];
        foreach (AcquisitionRules::cometsToRaise($this->answers->lastFor($player, $game, $manifest->cometWindow()), $inView, $this->comets->solvedAt($player, $game), $manifest->cometWindow(), $manifest->cometRepeat()) as $misconception) {
            $unit = $this->unitOf($game, $misconception);
            if (null === $unit) {
                continue;
            }
            $comet = new SeriousGameComet($player, $game, $misconception, $unit);
            $this->entityManager->persist($comet);
            $raised[] = $comet;
        }

        return $raised;
    }

    /** @return list<array<string, mixed>> the pieces granted or raised now */
    private function grantRewards(User $player, SeriousGame $game): array
    {
        $manifest = self::manifest($game);
        $progress = $this->progress($player, $game);
        $chapters = [];
        foreach ($progress['chapters'] as $chapter) {
            $chapters[$chapter['code']] = [$chapter['lit'], $chapter['total']];
        }
        $bestFinal = $this->sessions->bestFinal($player, $game);
        $earned = RewardRules::earned($game->getSlug(), $chapters, $bestFinal, null !== $bestFinal, $manifest->trophy(), $manifest->pin(), $manifest->finalCode());

        $granted = [];
        foreach ($earned as $code => $finish) {
            $grant = $this->grants->findOneFor($player, $code);
            if (null === $grant) {
                $grant = new SeriousGameRewardGrant($player, $code, $finish);
                $this->entityManager->persist($grant);
                $granted[] = ['code' => $code, 'finish' => $finish?->value, 'raised' => false] + SeriousGameRewardCatalog::describe($manifest, $game->getSlug(), $code);
            } elseif (null !== $finish && $grant->raiseFinish($finish)) {
                $granted[] = ['code' => $code, 'finish' => $finish->value, 'raised' => true] + SeriousGameRewardCatalog::describe($manifest, $game->getSlug(), $code);
            }
        }

        return $granted;
    }

    /** @return list<RewardView> */
    private function rewardsView(User $player, SeriousGame $game): array
    {
        $manifest = self::manifest($game);
        $codes = SeriousGameRewardCatalog::codes($manifest, $game->getSlug());
        $out = [];
        foreach ($this->grants->findForStudent($player) as $grant) {
            if (\in_array($grant->getRewardCode(), $codes, true)) {
                $out[] = ['code' => $grant->getRewardCode(), 'finish' => $grant->getFinish()?->value, 'grantedAt' => $grant->getGrantedAt()->format(\DATE_ATOM)] + SeriousGameRewardCatalog::describe($manifest, $game->getSlug(), $grant->getRewardCode());
            }
        }

        return $out;
    }

    /**
     * Every typical error the player has elucidated in the game, once each, the latest first - with
     * the explanation its study opened on. An error elucidated again after coming back keeps its
     * latest date; one back in view stays in the bestiary, since it was elucidated once.
     *
     * @return list<BestiaryEntry>
     */
    private function bestiary(User $player, SeriousGame $game): array
    {
        $manifest = self::manifest($game);
        $out = [];
        foreach ($this->comets->findSolved($player, $game) as $comet) {
            $misconception = $comet->getMisconception();
            if (isset($out[$misconception])) {
                continue;
            }
            $template = $this->source($game)->current($comet->getUnitCode());
            $explanation = '';
            if (null !== $template) {
                // a fixed seed per error: the bestiary reads the same at every opening
                $example = $this->engine->generate($template, crc32($misconception) ?: 1);
                $explanation = $this->engine->cometIntroduction($template, $misconception, $example);
            }
            $out[$misconception] = [
                'misconception' => $misconception,
                'label' => $template?->misconceptionLabel($misconception) ?? $misconception,
                'unit' => $comet->getUnitCode(),
                'unitName' => $manifest->unit($comet->getUnitCode())['label'] ?? $comet->getUnitCode(),
                'explanation' => $explanation,
                'solvedAt' => ($comet->getSolvedAt() ?? $comet->getAppearedAt())->format(\DATE_ATOM),
            ];
        }

        return array_values($out);
    }

    /**
     * How many typical errors the servable units know - what the bestiary is counted against.
     *
     * @param list<string> $servable
     */
    private function knownMisconceptions(SeriousGame $game, array $servable): int
    {
        $ids = [];
        foreach ($servable as $unit) {
            foreach ($this->source($game)->current($unit)?->misconceptions() ?? [] as $entry) {
                $ids[$entry['id']] = true;
            }
        }

        return \count($ids);
    }

    /** @return CometView */
    private function cometView(SeriousGame $game, SeriousGameComet $comet): array
    {
        $template = $this->source($game)->current($comet->getUnitCode());

        return [
            'misconception' => $comet->getMisconception(),
            'label' => $template?->misconceptionLabel($comet->getMisconception()) ?? $comet->getMisconception(),
            'unit' => $comet->getUnitCode(),
            'unitName' => self::manifest($game)->unit($comet->getUnitCode())['label'] ?? $comet->getUnitCode(),
            'state' => $comet->getState()->value,
            'attempts' => $comet->getAttempts(),
            'appearedAt' => $comet->getAppearedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function sessionView(SeriousGameSession $session): array
    {
        return [
            'session' => $session->getId(),
            'mode' => $session->getMode()->value,
            'unit' => $session->getUnitCode(),
            'size' => $session->getSize(),
            'timeLimitSeconds' => $session->getTimeLimitSeconds(),
            'deadline' => $session->getDeadline()?->format(\DATE_ATOM),
            // The host page's countdown reads the deadline against this, not against the
            // player's own clock (design/validated/serious-games.md §6.1).
            'serverTime' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
    }

    private function unitOf(SeriousGame $game, string $misconception): ?string
    {
        $source = $this->source($game);
        foreach (self::manifest($game)->unitCodes() as $unit) {
            if (null !== $source->current($unit)?->misconceptionLabel($misconception)) {
                return $unit;
            }
        }

        return null;
    }

    private function finalTimeLimit(User $player, SeriousGameManifest $manifest): int
    {
        return ExtraTime::apply($manifest->finalTimeLimit(), $this->accommodations->forUser($player)->quizExtraTimePercent) ?? $manifest->finalTimeLimit();
    }

    private function assertOpen(SeriousGameSession $session): void
    {
        if ($session->isEnded()) {
            throw new SeriousGamePlayRefused('session-ended');
        }
    }

    private function touch(User $player, SeriousGame $game): void
    {
        $this->progressFor($player, $game)->touch();
    }

    private function progressFor(User $player, SeriousGame $game): SeriousGameProgress
    {
        $progress = $this->progress->findOneFor($player, $game);
        if (null === $progress) {
            foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
                if ($scheduled instanceof SeriousGameProgress && $scheduled->getPlayer() === $player && $scheduled->getGame() === $game) {
                    return $scheduled;
                }
            }
            $progress = new SeriousGameProgress($player, $game);
            $this->entityManager->persist($progress);
        }

        return $progress;
    }

    public function source(SeriousGame $game): TemplateSource
    {
        return $this->sources[(int) $game->getId()] ??= new DatabaseTemplateSource($game, $this->templates);
    }

    public function reset(): void
    {
        $this->sources = [];
    }

    /** What is kept of what the student typed - « je ne sais pas » is a flag, not an input. */
    private static function stored(string $input): ?string
    {
        return Engine\AnswerCorrector::DONT_KNOW === $input ? null : mb_substr(trim($input), 0, 500);
    }
}
