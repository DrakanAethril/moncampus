<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\Option;
use App\Entity\Portfolio;
use App\Entity\PortfolioDeposit;
use App\Entity\Program;
use App\Entity\ReferentialCompetency;
use App\Entity\User;
use App\Enum\PortfolioClaimState;
use App\Enum\PortfolioExam;
use App\Enum\PortfolioSetting;
use App\Enum\PortfolioState;
use App\Repository\PortfolioDepositRepository;
use App\Repository\PortfolioRepository;

/**
 * « Outils › Portfolios › Par classe » - one line per student, one column per competency of the
 * synthesis block (design/validated/portfolio.md, screen 6).
 *
 * A cell counts the **validated** réalisations retaining the competency; when there is none, it
 * says whether one is at least waiting (blue) or nothing is (red). The three last columns prepare
 * the examination: workplace réalisations, the E6 fiches against the option's bloc 2, the deposits.
 */
class PortfolioClassOverview
{
    public function __construct(
        private readonly PortfolioQueue $queue,
        private readonly PortfolioRepository $portfolios,
        private readonly PortfolioDepositRepository $deposits,
        private readonly PortfolioShowcaseChecker $showcaseChecker,
        private readonly PortfolioContext $context,
    ) {
    }

    /**
     * @return array{columns: list<ReferentialCompetency>, rows: list<array{student: User, portfolio: ?Portfolio, cells: array<int, array{validated: int, pending: int}>, workplace: int, e6: ?array{rows: list<mixed>, complete: bool, count: int}, e6Pending: int, deposits: array<string, ?PortfolioDeposit>}>}
     */
    public function build(Program $program, ?Option $option): array
    {
        $referential = $program->getPortfolioReferential();
        $columns = null === $referential || null === $referential->getSynthesisBlock() ? [] : array_values($referential->getSynthesisBlock()->getCompetencies()->toArray());
        $students = $this->queue->studentsOf($program, $option);
        usort($students, static fn (User $a, User $b): int => [$a->getLastname(), $a->getFirstname()] <=> [$b->getLastname(), $b->getFirstname()]);

        $portfolios = null === $referential ? [] : $this->portfolios->findForStudents($referential, $students);
        $latest = $this->deposits->latestByPortfolio(array_values($portfolios));
        $rows = [];

        foreach ($students as $student) {
            $portfolio = $portfolios[(int) $student->getId()] ?? null;
            $cells = [];
            foreach ($columns as $column) {
                $cells[(int) $column->getId()] = ['validated' => 0, 'pending' => 0];
            }
            $workplace = 0;
            $e6 = null;
            $e6Pending = 0;

            if (null !== $portfolio) {
                foreach ($portfolio->getAchievements() as $achievement) {
                    $validated = PortfolioState::Validated === $achievement->getState();
                    if ($validated && PortfolioSetting::Workplace === $achievement->getSetting()) {
                        ++$workplace;
                    }

                    foreach ($achievement->getClaims() as $claim) {
                        $id = (int) $claim->getCompetency()?->getId();
                        if (!isset($cells[$id])) {
                            continue;
                        }
                        if ($validated && PortfolioClaimState::Retained === $claim->getState()) {
                            ++$cells[$id]['validated'];
                        } elseif (PortfolioState::Submitted === $achievement->getState()) {
                            ++$cells[$id]['pending'];
                        }
                    }
                }

                $block = $referential?->getShowcaseBlockFor($this->context->optionOf($student, $program, $referential));
                $e6 = $this->showcaseChecker->check($block, $portfolio->getShowcases());
                foreach ($portfolio->getShowcases() as $showcase) {
                    $e6Pending += PortfolioState::Submitted === $showcase->getState() ? 1 : 0;
                }
            }

            $rows[] = [
                'student' => $student,
                'portfolio' => $portfolio,
                'cells' => $cells,
                'workplace' => $workplace,
                'e6' => $e6,
                'e6Pending' => $e6Pending,
                'deposits' => [
                    PortfolioExam::E5->value => null === $portfolio ? null : ($latest[(int) $portfolio->getId()][PortfolioExam::E5->value] ?? null),
                    PortfolioExam::E6->value => null === $portfolio ? null : ($latest[(int) $portfolio->getId()][PortfolioExam::E6->value] ?? null),
                ],
            ];
        }

        return ['columns' => $columns, 'rows' => $rows];
    }
}
