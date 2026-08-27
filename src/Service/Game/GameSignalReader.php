<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Program;
use App\Entity\User;
use App\Enum\AttemptStatus;
use App\Enum\QuizMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Everything the automatic rules read, as plain rows, in one query per source.
 *
 * The queries live here rather than in eight repositories for one reason: they are the game's
 * reading of other features, not those features' own business. `App\Entity\WikiRevision` has no
 * opinion about points, and nothing in the wiki should gain a mapping towards the ledger.
 *
 * Every method is scoped to one student and one date range - the period being collected - and
 * returns arrays, never entities: the collector needs an id, a date and a number, and hydrating a
 * thousand objects to read three fields of each is how a nightly closure becomes a slow one.
 *
 * @phpstan-type QuizRow array{id: int, instance: int, submittedAt: \DateTimeImmutable, mode: string, correct: string|null, total: int|null, attemptNumber: int}
 * @phpstan-type DatedRow array{id: int, at: \DateTimeImmutable}
 * @phpstan-type RevisionRow array{id: int, node: int, createdAt: \DateTimeImmutable, length: int}
 */
final class GameSignalReader
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Concluded attempts of one student in the window, whatever the mode - the collector sorts the
     * évaluation ones (which pay their 20 points into « travail ») from the entraînement ones
     * (which pay only when the score went up).
     *
     * @return list<QuizRow>
     */
    public function quizAttempts(User $student, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{id: int, instance: int, submittedAt: \DateTimeImmutable, mode: QuizMode, correct: string|null, total: int|null, attemptNumber: int}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT a.id AS id, IDENTITY(a.quizInstance) AS instance, a.submittedAt AS submittedAt,'
            .' i.mode AS mode, a.correctCount AS correct, a.questionTotal AS total, a.attemptNumber AS attemptNumber'
            .' FROM App\Entity\QuizAttempt a JOIN a.quizInstance i'
            .' WHERE a.student = :student AND a.status = :status'
            .' AND a.submittedAt >= :from AND a.submittedAt <= :to'
            .' ORDER BY a.submittedAt ASC, a.id ASC'
        )
            ->setParameter('student', $student)
            ->setParameter('status', AttemptStatus::Termine)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'instance' => (int) $row['instance'],
            'submittedAt' => $row['submittedAt'],
            'mode' => $row['mode']->value,
            'correct' => $row['correct'],
            'total' => null === $row['total'] ? null : (int) $row['total'],
            'attemptNumber' => (int) $row['attemptNumber'],
        ], $rows);
    }

    /**
     * The best score already reached on each instance **before** the window - what a retry has to
     * beat for the progression rule to pay (§5.3, « refait avec progression du score »).
     *
     * @return array<int, float> instance id => best percentage
     */
    public function bestScoresBefore(User $student, \DateTimeImmutable $before): array
    {
        /** @var list<array{instance: int, correct: string|null, total: int|null}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT IDENTITY(a.quizInstance) AS instance, a.correctCount AS correct, a.questionTotal AS total'
            .' FROM App\Entity\QuizAttempt a'
            .' WHERE a.student = :student AND a.status = :status AND a.submittedAt < :before'
        )
            ->setParameter('student', $student)
            ->setParameter('status', AttemptStatus::Termine)
            ->setParameter('before', $before)
            ->getArrayResult();

        $best = [];
        foreach ($rows as $row) {
            $percent = $this->percent($row['correct'], null === $row['total'] ? null : (int) $row['total']);

            if (null === $percent) {
                continue;
            }

            $instance = (int) $row['instance'];
            $best[$instance] = max($best[$instance] ?? 0.0, $percent);
        }

        return $best;
    }

    /** A percentage off the two stored counters, null when the attempt scored nothing readable. */
    public function percent(?string $correct, ?int $total): ?float
    {
        if (null === $correct || null === $total || 0 === $total) {
            return null;
        }

        return (float) $correct / $total * 100;
    }

    /**
     * Self-assessments validated in the window, with the deadline they answered - the rule pays the
     * ones filled **before** the deadline.
     *
     * @return list<array{id: int, assignment: int, at: \DateTimeImmutable}>
     */
    public function selfAssessments(User $student, Program $program, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{id: int, assignment: int, at: \DateTimeImmutable}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT s.id AS id, IDENTITY(s.assignment) AS assignment, s.validatedAt AS at'
            .' FROM App\Entity\SelfAssessment s JOIN s.assignment g'
            .' WHERE s.student = :student AND g.program = :program AND s.validatedAt IS NOT NULL'
            .' AND s.validatedAt >= :from AND s.validatedAt <= :to'
        )
            ->setParameter('student', $student)
            ->setParameter('program', $program)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'assignment' => (int) $row['assignment'],
            'at' => $row['at'],
        ], $rows);
    }

    /**
     * Optional surveys answered in the window.
     *
     * Read off App\Entity\SurveyTarget rather than off the response, deliberately: on an anonymous
     * campaign there is no name stored on the answer, and there must never be. The target says that
     * this person answered without saying what they answered, which is exactly and only what the
     * rule needs.
     *
     * « Facultatif » is « not carried by an assignment »: a survey attached to a travail à faire is
     * a deadline, and it is already paid once, by the work family.
     *
     * @return list<DatedRow>
     */
    public function optionalSurveyAnswers(User $student, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{id: int, at: \DateTimeImmutable}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT t.id AS id, t.respondedAt AS at'
            .' FROM App\Entity\SurveyTarget t'
            .' WHERE t.user = :student AND t.respondedAt IS NOT NULL'
            .' AND t.respondedAt >= :from AND t.respondedAt <= :to'
            .' AND NOT EXISTS (SELECT g.id FROM App\Entity\Assignment g WHERE g.surveyCampaign = t.campaign)'
        )
            ->setParameter('student', $student)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getArrayResult();

        return $rows;
    }

    /**
     * Sign-ups this student actually turned up to.
     *
     * The registration alone pays nothing (§4, decision 4): what is paid is the one thing that
     * remains after the gesture, which is having been there - and somebody had to say so.
     *
     * @return list<DatedRow>
     */
    public function attendedSignups(User $student, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{id: int, at: \DateTimeImmutable}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT r.id AS id, r.attendedAt AS at'
            .' FROM App\Entity\SignupListRegistration r'
            .' WHERE r.user = :student AND r.attended = true AND r.attendedAt IS NOT NULL'
            .' AND r.attendedAt >= :from AND r.attendedAt <= :to'
        )
            ->setParameter('student', $student)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getArrayResult();

        return $rows;
    }

    /**
     * Wiki revisions written in the window, with the length of what they left behind.
     *
     * The threshold is applied by the collector against the previous revision of the same node, so
     * both are read here - a revision is substantial relative to what it replaced, and a first
     * revision is measured against nothing.
     *
     * @return list<RevisionRow>
     */
    public function wikiRevisions(User $student, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{node: int}> $nodes */
        $nodes = $this->entityManager->createQuery(
            'SELECT DISTINCT IDENTITY(r.node) AS node FROM App\Entity\WikiRevision r'
            .' WHERE r.author = :student AND r.createdAt >= :from AND r.createdAt <= :to'
        )
            ->setParameter('student', $student)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getArrayResult();

        $nodeIds = array_map(static fn (array $row): int => (int) $row['node'], $nodes);

        if ([] === $nodeIds) {
            return [];
        }

        /** @var list<array{id: int, node: int, createdAt: \DateTimeImmutable, author: int|null, length: int|string|null}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT r.id AS id, IDENTITY(r.node) AS node, r.createdAt AS createdAt,'
            .' IDENTITY(r.author) AS author, LENGTH(r.body) AS length'
            .' FROM App\Entity\WikiRevision r'
            .' WHERE IDENTITY(r.node) IN (:nodes) AND r.createdAt <= :to'
            .' ORDER BY r.node ASC, r.createdAt ASC, r.id ASC'
        )
            ->setParameter('nodes', $nodeIds)
            ->setParameter('to', $to)
            ->getArrayResult();

        $studentId = (int) $student->getId();
        $revisions = [];
        $previousLength = [];

        foreach ($rows as $row) {
            $node = (int) $row['node'];
            $length = (int) ($row['length'] ?? 0);
            $before = $previousLength[$node] ?? 0;
            $previousLength[$node] = $length;

            if ((int) ($row['author'] ?? 0) !== $studentId || $row['createdAt'] < $from) {
                continue;
            }

            $revisions[] = [
                'id' => (int) $row['id'],
                'node' => $node,
                'createdAt' => $row['createdAt'],
                // What this revision actually changed, in characters - the collector's threshold.
                'length' => abs($length - $before),
            ];
        }

        return $revisions;
    }

    /**
     * Applications filed in the window, from both sides of the house - a training offer applied to,
     * and a démarche of the job search.
     *
     * @return list<array{type: string, id: int, at: \DateTimeImmutable}>
     */
    public function applications(User $student, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $applications = [];

        /** @var list<array{id: int, at: \DateTimeImmutable}> $training */
        $training = $this->entityManager->createQuery(
            'SELECT a.id AS id, a.createdAt AS at FROM App\Entity\TrainingApplication a'
            .' WHERE a.student = :student AND a.createdAt >= :from AND a.createdAt <= :to'
        )
            ->setParameter('student', $student)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getArrayResult();

        foreach ($training as $row) {
            $applications[] = ['type' => 'TrainingApplication', 'id' => (int) $row['id'], 'at' => $row['at']];
        }

        /** @var list<array{id: int, at: \DateTimeImmutable}> $jobs */
        $jobs = $this->entityManager->createQuery(
            'SELECT a.id AS id, a.createdAt AS at FROM App\Entity\JobApplication a'
            .' WHERE a.student = :student AND a.createdAt >= :from AND a.createdAt <= :to'
        )
            ->setParameter('student', $student)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getArrayResult();

        foreach ($jobs as $row) {
            $applications[] = ['type' => 'JobApplication', 'id' => (int) $row['id'], 'at' => $row['at']];
        }

        usort($applications, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $applications;
    }

    /**
     * Resources this person put in the class's shared space during the window.
     *
     * @return list<DatedRow>
     */
    public function sharedResources(User $student, Program $program, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{id: int, at: \DateTimeImmutable}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT d.id AS id, d.creationDate AS at FROM App\Entity\SharedDocument d'
            .' WHERE d.teacher = :student AND d.program = :program'
            .' AND d.creationDate >= :from AND d.creationDate <= :to'
        )
            ->setParameter('student', $student)
            ->setParameter('program', $program)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getArrayResult();

        return $rows;
    }
}
