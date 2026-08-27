<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\GameAlias;
use App\Entity\GameFigure;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\GameAliasRepository;
use App\Repository\GameFigureRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Drawing three figures, and what happens when nobody chooses (§4, decision 8).
 *
 * The draw excludes what is already taken in the class this period and what this student has already
 * carried - so the cursus offers twelve different names rather than the same three four times.
 * **Uniqueness itself is the database's job**, not this class's: the unique index on
 * `(program, period, figure)` is what settles two simultaneous choices landing on Lovelace, and no
 * application check can.
 *
 * At J+7 the first of the three is attributed. A period that starts with no name is a ranking nobody
 * can read, and the student is told which one they were given.
 */
final class GameAliasDrawer
{
    /** How many are offered, and how long the offer stands. */
    public const int OFFERED = 3;
    public const int CHOICE_DAYS = 7;

    public function __construct(
        private readonly GameFigureRepository $figures,
        private readonly GameAliasRepository $aliases,
        private readonly GameTrackResolver $tracks,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The student's alias for this period, drawn if it does not exist yet.
     *
     * Returns null when the student's filière cannot be resolved, or when its catalogue is empty: the game
     * then simply runs without pseudonyms, and the ranking shows real names to nobody because it
     * shows nothing at all (the settings screen can switch the ranking off for the same reason).
     */
    public function aliasFor(User $student, Program $program): ?GameAlias
    {
        $existing = $this->aliases->findOneFor($student, $program);

        if (null !== $existing) {
            return $existing;
        }

        // Per student, not per formation: in a SIO class the SLAM students draw from the SLAM
        // catalogue and the SISR ones from theirs.
        $track = $this->tracks->forStudent($student, $program);

        if (null === $track) {
            return null;
        }

        $available = $this->figures->availableFor($track, $program, $student);

        if ([] === $available) {
            return null;
        }

        $offered = $this->pick($available, self::OFFERED);

        $alias = new GameAlias($student, $program, array_map(static fn (GameFigure $figure): int => (int) $figure->getId(), $offered));
        $this->entityManager->persist($alias);
        $this->entityManager->flush();

        return $alias;
    }

    /**
     * The student chooses one of the three offered - and only one of the three.
     *
     * A figure taken between the draw and the click is refused by the unique index; the caller offers
     * a fresh draw rather than silently substituting one, because the three cards are what the
     * student read.
     */
    public function choose(GameAlias $alias, GameFigure $figure): bool
    {
        if ($alias->isChosen() || !\in_array((int) $figure->getId(), $alias->getOfferedFigures(), true)) {
            return false;
        }

        $alias->choose($figure);

        try {
            $this->entityManager->flush();
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * J+7: attribute the first of the three to everybody who did not choose.
     *
     * @return int how many were attributed
     */
    public function attributeLapsed(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $deadline = $now->modify('-'.self::CHOICE_DAYS.' days');
        $attributed = 0;

        foreach ($this->aliases->findLapsed($deadline) as $alias) {
            $offered = $this->figures->findByIds($alias->getOfferedFigures());

            foreach ($alias->getOfferedFigures() as $id) {
                $figure = $offered[$id] ?? null;

                if (null === $figure) {
                    continue;
                }

                $alias->choose($figure, true, $now);

                try {
                    $this->entityManager->flush();
                    ++$attributed;

                    break;
                } catch (\Throwable) {
                    // Taken in the meantime: try the next of the three rather than leaving the
                    // student nameless.
                    $this->entityManager->clear();

                    break;
                }
            }
        }

        return $attributed;
    }

    /**
     * Draw the whole class's aliases - what a closure does for the period it opens.
     *
     * @return int how many were drawn
     */
    public function drawForClass(Program $program): int
    {
        $drawn = 0;

        foreach ($program->getStudents() as $student) {
            if (null !== $this->aliasFor($student, $program)) {
                ++$drawn;
            }
        }

        return $drawn;
    }

    /**
     * @param list<GameFigure> $available
     *
     * @return list<GameFigure>
     */
    private function pick(array $available, int $count): array
    {
        if (\count($available) <= $count) {
            return $available;
        }

        $keys = array_rand($available, $count);

        return array_values(array_map(static fn (int $key): GameFigure => $available[$key], (array) $keys));
    }
}
