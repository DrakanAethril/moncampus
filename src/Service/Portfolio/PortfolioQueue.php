<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\Option;
use App\Entity\Portfolio;
use App\Entity\PortfolioAchievement;
use App\Entity\PortfolioShowcase;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\PortfolioState;
use App\Repository\PortfolioRepository;
use App\Repository\ProgramStudentOptionRepository;

/**
 * « Outils › Portfolios › À valider » - the validateur's queue (design/validated/portfolio.md, screen 4).
 *
 * The pieces waiting for a decision among the students of the (class, option) pairs the teacher is
 * designated for, oldest first - with one exception: a piece whose student asked for this teacher
 * by name comes first in their queue, and stays open to the option's other validateurs.
 *
 * Réalisations and E6 fiches are mixed, and typed. A student counts for a pair only if the pair's
 * class is their **current** formation (last year's validateurs are not this year's) and they hold
 * its option - or no option of the référentiel at all (§14, question 2).
 */
class PortfolioQueue
{
    public function __construct(
        private readonly PortfolioValidators $validators,
        private readonly PortfolioContext $context,
        private readonly PortfolioRepository $portfolios,
        private readonly ProgramStudentOptionRepository $studentOptions,
    ) {
    }

    /**
     * The teacher's designated pairs, deduplicated.
     *
     * @return list<array{program: Program, option: Option, key: string}>
     */
    public function pairsOf(User $teacher): array
    {
        $pairs = [];
        foreach ($this->validators->designationsOf($teacher) as $row) {
            $program = $row->getProgram();
            $option = $row->getOption();
            if (null === $program || null === $option || !$program->hasPortfolio()) {
                continue;
            }
            $key = $program->getId().'-'.$option->getId();
            $pairs[$key] = ['program' => $program, 'option' => $option, 'key' => $key];
        }

        return array_values($pairs);
    }

    /**
     * @return list<array{piece: PortfolioAchievement|PortfolioShowcase, portfolio: Portfolio, program: Program, option: Option, requested: bool}>
     */
    public function pendingFor(User $teacher, ?string $pairKey = null): array
    {
        $items = [];
        $seen = [];

        foreach ($this->pairsOf($teacher) as $pair) {
            if (null !== $pairKey && '' !== $pairKey && $pair['key'] !== $pairKey) {
                continue;
            }

            foreach ($this->portfoliosOf($pair['program'], $pair['option']) as $portfolio) {
                foreach ($this->pendingPieces($portfolio) as $piece) {
                    $id = ($piece instanceof PortfolioShowcase ? 's' : 'a').$piece->getId();
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;

                    $items[] = [
                        'piece' => $piece,
                        'portfolio' => $portfolio,
                        'program' => $pair['program'],
                        'option' => $pair['option'],
                        'requested' => $piece->getRequestedReviewer()?->getId() === $teacher->getId(),
                    ];
                }
            }
        }

        usort($items, static fn (array $a, array $b): int => [!$a['requested'], $a['piece']->getSubmittedAt()] <=> [!$b['requested'], $b['piece']->getSubmittedAt()]);

        return $items;
    }

    public function countFor(User $teacher): int
    {
        return \count($this->pendingFor($teacher));
    }

    /**
     * The portfolios of a class and option - the students who hold the option, plus those who hold
     * none of the référentiel's options - keyed by student id. Students whose current formation is
     * another class are left out.
     *
     * @return array<int, Portfolio>
     */
    public function portfoliosOf(Program $program, ?Option $option): array
    {
        $referential = $program->getPortfolioReferential();

        if (null === $referential) {
            return [];
        }

        $students = [];
        foreach ($this->studentsOf($program, $option) as $student) {
            if ($this->context->currentProgram($student, $referential)?->getId() === $program->getId()) {
                $students[] = $student;
            }
        }

        return $this->portfolios->findForStudents($referential, $students);
    }

    /**
     * The students of a class for one option: those holding it, and those holding none of the
     * référentiel's options. A null option is the whole class.
     *
     * @return list<User>
     */
    public function studentsOf(Program $program, ?Option $option): array
    {
        $referential = $program->getPortfolioReferential();
        $students = $program->getStudents()->toArray();

        if (null === $option || null === $referential) {
            return array_values($students);
        }

        $optionIds = array_map(static fn (Option $candidate): int => (int) $candidate->getId(), $this->context->optionsOf($referential));
        $tags = $this->studentOptions->findOptionsByStudentForProgram($program);

        return array_values(array_filter($students, static function (User $student) use ($tags, $optionIds, $option): bool {
            $own = array_intersect(array_map(static fn (Option $candidate): int => (int) $candidate->getId(), $tags[(int) $student->getId()] ?? []), $optionIds);

            return [] === $own || \in_array((int) $option->getId(), $own, true);
        }));
    }

    /**
     * @return list<PortfolioAchievement|PortfolioShowcase>
     */
    private function pendingPieces(Portfolio $portfolio): array
    {
        $pieces = [];
        foreach ($portfolio->getAchievements() as $achievement) {
            if (PortfolioState::Submitted === $achievement->getState()) {
                $pieces[] = $achievement;
            }
        }
        foreach ($portfolio->getShowcases() as $showcase) {
            if (PortfolioState::Submitted === $showcase->getState()) {
                $pieces[] = $showcase;
            }
        }

        return $pieces;
    }
}
