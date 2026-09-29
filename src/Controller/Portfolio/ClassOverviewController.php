<?php

declare(strict_types=1);

namespace App\Controller\Portfolio;

use App\Attribute\RequiresFeature;
use App\Entity\Option;
use App\Entity\Program;
use App\Enum\Feature;
use App\Repository\OptionRepository;
use App\Repository\PortfolioRepository;
use App\Repository\ProgramRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\PortfolioVoter;
use App\Service\Portfolio\PortfolioClassOverview;
use App\Service\Portfolio\PortfolioContext;
use App\Service\Portfolio\PortfolioQueue;
use App\Service\Portfolio\PortfolioSynthesis;
use App\Service\Portfolio\PortfolioValidators;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Outils › Portfolios › Par classe » and a student's portfolio read by the équipe
 * (design/validated/portfolio.md, screen 6).
 *
 * A validateur sees the (class, option) pairs they are designated for - a SISR validateur sees the
 * SISR students; the administration reads every class and decides nothing.
 */
#[RequiresFeature(Feature::Portfolio)]
class ClassOverviewController extends AbstractController
{
    use PortfolioReviewTrait;

    public function __construct(
        private readonly PortfolioValidators $validators,
        private readonly StructureAccessChecker $access,
        private readonly PortfolioQueue $queue,
        private readonly PortfolioContext $context,
    ) {
    }

    #[Route(path: '/portfolios/classes', name: 'app_portfolios_classes', methods: ['GET'])]
    public function classes(ProgramRepository $programs): Response
    {
        $this->assertReviewArea($this->validators, $this->access);
        $teacher = $this->currentUser();
        $pairs = $this->queue->pairsOf($teacher);

        // The administration reads every class that runs the portfolio, option by option.
        if ($this->access->isStaff()) {
            $all = [];
            foreach ($programs->findActiveWithPortfolio() as $program) {
                $referential = $program->getPortfolioReferential();
                foreach (null === $referential ? [] : $this->context->optionsOf($referential) as $option) {
                    $key = $program->getId().'-'.$option->getId();
                    $all[$key] = ['program' => $program, 'option' => $option, 'key' => $key];
                }
            }
            foreach ($pairs as $pair) {
                $all[$pair['key']] = $pair;
            }
            $pairs = array_values($all);
        }

        return $this->render('portfolio/review/classes.html.twig', [
            'pairs' => $pairs,
            'total' => $this->validators->isValidator($teacher) ? $this->queue->countFor($teacher) : null,
            'activeTab' => 'classes',
        ]);
    }

    #[Route(path: '/portfolios/classes/{programId}/{optionId}', name: 'app_portfolios_class', requirements: ['programId' => '\d+', 'optionId' => '\d+'], methods: ['GET'])]
    public function class(int $programId, int $optionId, ProgramRepository $programs, OptionRepository $options, PortfolioClassOverview $overview): Response
    {
        $this->assertReviewArea($this->validators, $this->access);
        $program = $programs->find($programId) ?? throw $this->createNotFoundException();
        $option = $options->find($optionId) ?? throw $this->createNotFoundException();
        $this->assertClassVisible($program, $option);

        return $this->render('portfolio/review/class.html.twig', [
            'program' => $program,
            'option' => $option,
            'overview' => $overview->build($program, $option),
            'canEndorse' => $this->validators->covers($this->currentUser(), $program, $option),
            'total' => $this->validators->isValidator($this->currentUser()) ? $this->queue->countFor($this->currentUser()) : null,
            'activeTab' => 'classes',
        ]);
    }

    #[Route(path: '/portfolios/{id}', name: 'app_portfolios_student', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function student(int $id, PortfolioRepository $portfolios, PortfolioSynthesis $synthesis): Response
    {
        $portfolio = $portfolios->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isGranted(PortfolioVoter::VIEW, $portfolio)) {
            throw $this->createNotFoundException();
        }

        $student = $portfolio->getStudent();

        return $this->render('portfolio/review/student.html.twig', [
            'portfolio' => $portfolio,
            'program' => null === $student ? null : $this->context->currentProgram($student, $portfolio->getReferential()),
            'option' => $this->context->optionOfPortfolio($portfolio),
            'table' => $synthesis->build($portfolio, true),
        ]);
    }

    private function assertClassVisible(Program $program, Option $option): void
    {
        if ($this->access->isStaff() || $this->validators->covers($this->currentUser(), $program, $option)) {
            return;
        }

        throw $this->createNotFoundException();
    }
}
