<?php

declare(strict_types=1);

namespace App\SeriousGame;

use App\Entity\Program;
use App\Entity\SeriousGame;
use App\Entity\SeriousGameAnswer;
use App\Entity\SeriousGameComet;
use App\Entity\SeriousGameSession;
use App\Entity\User;
use App\Enum\SeriousGameBrightness;
use App\Enum\SeriousGameSessionMode;
use App\Repository\SeriousGameAvatarRepository;
use App\Repository\SeriousGameCometRepository;
use App\Repository\SeriousGameProgressRepository;
use App\Repository\SeriousGameSessionRepository;
use App\Repository\SeriousGameUnitStateRepository;
use App\SeriousGame\Engine\QuestionEngine;
use App\SeriousGame\Template\TemplateBench;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The teachers' reading of a class in one game (design/validated/serious-games.md §8, « Lecture des
 * résultats », design/conception_serious_games/suivi.html): what each student acquired, and above
 * all **which errors the class makes** - the information is the error, not « 7/12 ».
 *
 * Read by the teachers of the class and the administrators (SeriousGameVoter::READ_CLASS), for the
 * students' whole history. **Never sorted by score** - by name or by last activity: a ranking
 * projected on the board would be a podium. « Je ne sais pas » is counted apart: not knowing is not
 * being wrong. Nothing is sent to the students from here.
 *
 * @phpstan-type ErrorRow array{misconception: string, label: string, unit: string, unitName: string, answers: int, students: int, example: ?array{prompt: string, figure: ?string, input: string}}
 */
final readonly class SeriousGameClassReading
{
    /** « Inactifs depuis 14 jours » - a reading, never a consequence for anybody. */
    public const int INACTIVE_DAYS = 14;

    public function __construct(
        private SeriousGamePlay $play,
        private QuestionEngine $engine,
        private SeriousGameUnitStateRepository $states,
        private SeriousGameProgressRepository $progress,
        private SeriousGameSessionRepository $sessions,
        private SeriousGameCometRepository $comets,
        private SeriousGameAvatarRepository $avatars,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function forClass(Program $program, SeriousGame $game): array
    {
        $manifest = SeriousGamePlay::manifest($game);
        /** @var list<User> $students */
        $students = array_values($program->getStudents()->toArray());
        usort($students, static fn (User $a, User $b): int => [mb_strtolower((string) $a->getLastname()), mb_strtolower((string) $a->getFirstname())] <=> [mb_strtolower((string) $b->getLastname()), mb_strtolower((string) $b->getFirstname())]);

        $states = $this->states->findForPlayers($students, $game);
        $progress = $this->progress->findForPlayers($students, $game);
        $avatars = $this->avatars->findForUsers($students);
        $sessions = $this->sessions->findEndedForPlayers($students, $game);
        $now = new \DateTimeImmutable();
        $units = $manifest->units();

        $rows = [];
        foreach ($students as $student) {
            $id = (int) $student->getId();
            $cells = [];
            $lit = 0;
            $discrete = 0;
            foreach ($units as $unit) {
                $state = $states[$id][$unit['code']] ?? null;
                $brightness = SeriousGameBrightness::fromBest($state?->getBest());
                $cells[$unit['code']] = null !== $brightness ? $brightness->value : (null !== $state && $state->getSeriesCount() > 0 ? 'tried' : 'never');
                $lit += null !== $brightness ? 1 : 0;
                $discrete += SeriousGameBrightness::Discrete === $brightness ? 1 : 0;
            }
            $lastPlayed = $progress[$id] ?? null;
            $played = isset($sessions[$id]);
            $rows[] = [
                'student' => $student,
                'avatar' => $avatars[$id] ?? null,
                'cells' => $cells,
                'lit' => $lit,
                'lastPlayedAt' => $lastPlayed?->getLastPlayedAt(),
                'series' => \count(array_filter($sessions[$id] ?? [], static fn (SeriousGameSession $s): bool => SeriousGameSessionMode::Series === $s->getMode())),
                // « Peu d'éclat »: played and lit nothing, or at least half of what is lit is discrète.
                'dim' => ($played && 0 === $lit) || ($lit > 0 && 2 * $discrete >= $lit),
                'inactive' => null === $lastPlayed || $lastPlayed->getLastPlayedAt() < $now->modify('-'.self::INACTIVE_DAYS.' days'),
            ];
        }

        $byUnit = [];
        foreach ($units as $unit) {
            $litCount = \count(array_filter($rows, static fn (array $r): bool => !\in_array($r['cells'][$unit['code']], ['tried', 'never'], true)));
            $tried = \count(array_filter($rows, static fn (array $r): bool => 'tried' === $r['cells'][$unit['code']]));
            $byUnit[$unit['code']] = ['unit' => $unit, 'lit' => $litCount, 'tried' => $tried, 'never' => \count($rows) - $litCount - $tried, 'share' => [] === $rows ? 0 : $litCount / \count($rows)];
        }
        $synthesis = array_values($byUnit);
        usort($synthesis, static fn (array $a, array $b): int => $a['share'] <=> $b['share']);

        $litCounts = array_column($rows, 'lit');
        sort($litCounts);
        $weekAgo = $now->modify('-7 days');
        $allSessions = array_merge(...array_values($sessions) ?: [[]]);

        return [
            'chapters' => $manifest->chapters(),
            'rows' => $rows,
            'byUnit' => $byUnit,
            'synthesis' => $synthesis,
            'errors' => $this->errors($game, $students),
            'kpis' => [
                'students' => \count($rows),
                'playedThisWeek' => \count(array_filter($rows, static fn (array $r): bool => null !== $r['lastPlayedAt'] && $r['lastPlayedAt'] >= $weekAgo)),
                'medianLit' => [] === $litCounts ? 0 : $litCounts[intdiv(\count($litCounts), 2)],
                'seriesThisWeek' => \count(array_filter($allSessions, static fn (SeriousGameSession $s): bool => SeriousGameSessionMode::Series === $s->getMode() && $s->getEndedAt() >= $weekAgo)),
                'cometsInView' => \count(array_filter($this->comets->findForPlayers($students, $game), static fn (SeriousGameComet $c): bool => $c->isInView())),
            ],
        ];
    }

    /**
     * The errors a group of students makes, the most frequent first, each with a question
     * regenerated where it occurs - and the « je ne sais pas », counted apart.
     *
     * @param list<User> $students
     *
     * @return array{rows: list<ErrorRow>, dontKnow: int, dontKnowStudents: int}
     */
    public function errors(SeriousGame $game, array $students): array
    {
        if ([] === $students) {
            return ['rows' => [], 'dontKnow' => 0, 'dontKnowStudents' => 0];
        }

        /** @var list<array{player: int|string, unit: string, misconception: string|null, dontKnow: bool|int|string, n: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(s.player) AS player', 'a.unitCode AS unit', 'a.misconception', 'a.dontKnow', 'COUNT(a.id) AS n')
            ->from(SeriousGameAnswer::class, 'a')
            ->innerJoin('a.session', 's')
            ->andWhere('s.game = :game')
            ->andWhere('s.player IN (:players)')
            ->andWhere('a.answeredAt IS NOT NULL')
            ->andWhere('a.misconception IS NOT NULL OR a.dontKnow = true')
            ->setParameter('game', $game)
            ->setParameter('players', $students)
            ->groupBy('player', 'unit', 'a.misconception', 'a.dontKnow')
            ->getQuery()
            ->getArrayResult();

        $manifest = SeriousGamePlay::manifest($game);
        $source = $this->play->source($game);
        $errors = [];
        $dontKnow = 0;
        $dontKnowStudents = [];
        foreach ($rows as $row) {
            if ((bool) $row['dontKnow']) {
                $dontKnow += (int) $row['n'];
                $dontKnowStudents[(int) $row['player']] = true;

                continue;
            }
            $key = (string) $row['misconception'];
            $errors[$key] ??= ['misconception' => $key, 'unit' => $row['unit'], 'answers' => 0, 'players' => []];
            $errors[$key]['answers'] += (int) $row['n'];
            $errors[$key]['players'][(int) $row['player']] = true;
        }

        $out = [];
        foreach ($errors as $error) {
            $template = $source->current($error['unit']);
            $out[] = [
                'misconception' => $error['misconception'],
                'label' => $template?->misconceptionLabel($error['misconception']) ?? $error['misconception'],
                'unit' => $error['unit'],
                'unitName' => $manifest->unit($error['unit'])['label'] ?? $error['unit'],
                'answers' => $error['answers'],
                'students' => \count($error['players']),
                'example' => null === $template ? null : $this->example($template, $error['misconception']),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['students'], $b['answers']] <=> [$a['students'], $a['answers']]);

        return ['rows' => $out, 'dontKnow' => $dontKnow, 'dontKnowStudents' => \count($dontKnowStudents)];
    }

    /**
     * A question of the unit where the error occurs, regenerated from fixed seeds - so the example
     * is the same at every reading - with the wrong answer a student making it would type.
     *
     * @return array{prompt: string, figure: ?string, input: string}|null
     */
    private function example(Template\TemplateDefinition $template, string $misconception): ?array
    {
        foreach (TemplateBench::seeds(40) as $seed) {
            $question = $this->engine->generate($template, $seed);
            foreach ($question->wrongs as $wrong) {
                if ($wrong['misconception'] === $misconception) {
                    return ['prompt' => $question->prompt, 'figure' => $question->figure, 'input' => $wrong['text']];
                }
            }
        }

        return null;
    }
}
