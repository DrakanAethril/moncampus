<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\Option;
use App\Entity\Portfolio;
use App\Entity\Program;
use App\Entity\Referential;
use App\Entity\ReferentialBlock;
use App\Entity\ReferentialCompetency;
use App\Entity\User;
use App\Enum\ReferentialBlockRole;
use App\Repository\PortfolioRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Where a student's portfolio stands: its current formation, its option, the school years it spans.
 *
 * **One place answers these three questions**, for the student's screens, the validateur's rule,
 * the synthesis table and the deposits, so they cannot disagree:
 *
 * - **the current formation** is the student's formation running the portfolio whose school year
 *   holds today, else the most recent one. Its deadlines, its session and its validateurs apply;
 * - **the option** is the one of that formation's ProgramStudentOption rows that a block of the
 *   référentiel names - a student also tagged « Groupe A » is not SLAM because of it;
 * - **the year spans** are every formation running the portfolio on the same référentiel, with the
 *   cursus year each declares (App\Service\Portfolio\PortfolioSectionResolver reads them).
 *
 * Memoised per request - ResetInterface, since the worker outlives the request.
 */
class PortfolioContext implements ResetInterface
{
    /** @var array<int, list<Program>> */
    private array $programsByStudent = [];

    public function __construct(
        private readonly ProgramRepository $programs,
        private readonly ProgramStudentOptionRepository $studentOptions,
        private readonly PortfolioRepository $portfolios,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function reset(): void
    {
        $this->programsByStudent = [];
    }

    /** @return list<Program> every formation of the student running the portfolio, oldest first */
    public function programsOf(User $student): array
    {
        return $this->programsByStudent[(int) $student->getId()] ??= $this->programs->findPortfolioProgramsForStudent($student);
    }

    public function hasPortfolio(User $student): bool
    {
        return null !== $this->currentProgram($student);
    }

    public function currentProgram(User $student, ?Referential $referential = null): ?Program
    {
        $today = new \DateTimeImmutable('today');
        $latest = null;

        foreach ($this->programsOf($student) as $program) {
            if (null !== $referential && $program->getPortfolioReferential()?->getId() !== $referential->getId()) {
                continue;
            }

            $year = $program->getSchoolYear();
            if (null !== $year && null !== $year->getStartDate() && null !== $year->getEndDate()
                && $year->getStartDate() <= $today && $today <= $year->getEndDate()) {
                return $program;
            }

            $latest = $program;
        }

        return $latest;
    }

    /**
     * The student's portfolio for their current formation's référentiel, created on first visit.
     */
    public function portfolioFor(User $student): ?Portfolio
    {
        $program = $this->currentProgram($student);
        $referential = $program?->getPortfolioReferential();

        if (null === $referential) {
            return null;
        }

        $portfolio = $this->portfolios->findOneBy(['student' => $student, 'referential' => $referential]);

        if (null === $portfolio) {
            $portfolio = new Portfolio($student, $referential);
            $this->entityManager->persist($portfolio);
            $this->entityManager->flush();
        }

        return $portfolio;
    }

    /** The option of the student in a formation, as the référentiel's blocks understand it. */
    public function optionOf(User $student, ?Program $program, Referential $referential): ?Option
    {
        if (null === $program) {
            return null;
        }

        $named = [];
        foreach ($referential->getBlocks() as $block) {
            foreach ($block->getOptions() as $option) {
                $named[(int) $option->getId()] = true;
            }
        }

        foreach ($this->studentOptions->findOptionsForStudent($program, $student) as $option) {
            if (isset($named[(int) $option->getId()])) {
                return $option;
            }
        }

        return null;
    }

    /** The option of a portfolio's owner in their current formation. */
    public function optionOfPortfolio(Portfolio $portfolio): ?Option
    {
        $student = $portfolio->getStudent();
        $referential = $portfolio->getReferential();

        if (null === $student || null === $referential) {
            return null;
        }

        return $this->optionOf($student, $this->currentProgram($student, $referential), $referential);
    }

    /**
     * The options a référentiel's E6 blocks name - the options a formation's validateurs are
     * designated for.
     *
     * @return list<Option>
     */
    public function optionsOf(Referential $referential): array
    {
        $options = [];
        foreach ($referential->getBlocks() as $block) {
            if (ReferentialBlockRole::None === $block->getRole()) {
                continue;
            }

            foreach ($block->getOptions() as $option) {
                $options[(int) $option->getId()] = $option;
            }
        }

        return array_values($options);
    }

    /**
     * @return list<array{from: \DateTimeImmutable, until: \DateTimeImmutable, cursusYear: int}>
     */
    public function yearSpans(Portfolio $portfolio): array
    {
        $student = $portfolio->getStudent();
        if (null === $student) {
            return [];
        }

        $spans = [];
        foreach ($this->programsOf($student) as $program) {
            $year = $program->getSchoolYear();
            if ($program->getPortfolioReferential()?->getId() !== $portfolio->getReferential()?->getId()
                || null === $year || null === $year->getStartDate() || null === $year->getEndDate()) {
                continue;
            }

            $spans[] = [
                'from' => $year->getStartDate(),
                'until' => $year->getEndDate(),
                'cursusYear' => $program->getPortfolioCursusYear() ?? 1,
            ];
        }

        return $spans;
    }

    /** The cursus year of the student's current formation - the E6 tab opens in the second. */
    public function cursusYear(Portfolio $portfolio): int
    {
        $student = $portfolio->getStudent();

        return null === $student ? 1 : ($this->currentProgram($student, $portfolio->getReferential())?->getPortfolioCursusYear() ?? 1);
    }

    /**
     * The blocks a student may claim competencies from: the synthesis block (E5), and the E6 block
     * of their own option - a SLAM student claims no SISR competency.
     *
     * @return array{synthesis: ?ReferentialBlock, showcase: ?ReferentialBlock}
     */
    public function claimableBlocks(Portfolio $portfolio): array
    {
        $referential = $portfolio->getReferential();

        return [
            'synthesis' => $referential?->getSynthesisBlock(),
            'showcase' => $referential?->getShowcaseBlockFor($this->optionOfPortfolio($portfolio)),
        ];
    }

    /** @return list<ReferentialCompetency> */
    public function claimableCompetencies(Portfolio $portfolio): array
    {
        $competencies = [];
        foreach ($this->claimableBlocks($portfolio) as $block) {
            foreach (null === $block ? [] : $block->getCompetencies() as $competency) {
                $competencies[] = $competency;
            }
        }

        return $competencies;
    }
}
