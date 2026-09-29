<?php

declare(strict_types=1);

namespace App\Controller\Portfolio;

use App\Attribute\RequiresFeature;
use App\Entity\Option;
use App\Entity\Program;
use App\Enum\Feature;
use App\Enum\PortfolioExam;
use App\Repository\OptionRepository;
use App\Repository\PortfolioDepositRepository;
use App\Repository\PortfolioShowcaseRepository;
use App\Repository\ProgramRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\PortfolioVoter;
use App\Service\Portfolio\PortfolioFileStore;
use App\Service\Portfolio\PortfolioPdfExporter;
use App\Service\Portfolio\PortfolioQueue;
use App\Service\Portfolio\PortfolioValidators;
use App\Service\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The CCF side of a deposit (design/validated/portfolio.md §6 screens 6 and 9, R9): the conformity
 * check of annexe VI-2 or VII-2 and the endorsement, by a validateur of the student's option (§14,
 * question 1); and what goes to the commission - always read from the last **endorsed** deposit.
 *
 * The commission has no access to the platform (§1): these exports are the files the équipe hands
 * it.
 */
#[RequiresFeature(Feature::Portfolio)]
class DepositReviewController extends AbstractController
{
    use PortfolioReviewTrait;
    use PortfolioDownloadTrait;

    public function __construct(
        private readonly PortfolioDepositRepository $deposits,
        private readonly PortfolioFileStore $files,
        private readonly PortfolioValidators $validators,
        private readonly StructureAccessChecker $access,
    ) {
    }

    #[Route(path: '/portfolios/deposits/{id}', name: 'app_portfolios_deposit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $deposit = $this->deposits->find($id) ?? throw $this->createNotFoundException();
        $portfolio = $deposit->getPortfolio() ?? throw $this->createNotFoundException();

        if (!$this->isGranted(PortfolioVoter::VIEW, $portfolio)) {
            throw $this->createNotFoundException();
        }

        return $this->render('portfolio/review/deposit.html.twig', [
            'deposit' => $deposit,
            'portfolio' => $portfolio,
            'program' => $deposit->getProgram(),
            'canEndorse' => $this->isGranted(PortfolioVoter::ENDORSE, $portfolio),
            'history' => $this->deposits->findForPortfolio($portfolio),
        ]);
    }

    #[Route(path: '/portfolios/deposits/{id}/check', name: 'app_portfolios_deposit_check', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function check(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $deposit = $this->deposits->find($id) ?? throw $this->createNotFoundException();
        $portfolio = $deposit->getPortfolio() ?? throw $this->createNotFoundException();

        if (!$this->isGranted(PortfolioVoter::ENDORSE, $portfolio)) {
            throw $this->createNotFoundException();
        }

        $this->assertToken($request);
        $ticked = PostValue::all($request, 'checklist');
        $checklist = [];
        foreach ($deposit->getExam()->checklistKeys() as $key) {
            $checklist[$key] = '1' === ($ticked[$key] ?? null);
        }

        try {
            $deposit->check($this->currentUser(), $checklist, PostValue::trimmed($request, 'comment'));
            $entityManager->flush();
            $this->addFlash('success', $deposit->isEndorsed() ? 'portfolioDepositEndorsedFlashMessage' : 'portfolioDepositToRegulariseFlashMessage');
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'portfolioDepositReasonRequiredError');
        }

        return $this->redirectToRoute('app_portfolios_deposit', ['id' => $deposit->getId()]);
    }

    #[Route(path: '/portfolios/deposits/{id}/{kind}', name: 'app_portfolios_deposit_file', requirements: ['id' => '\d+', 'kind' => 'pdf|xlsx'], methods: ['GET'])]
    public function depositFile(int $id, string $kind): Response
    {
        $deposit = $this->deposits->find($id) ?? throw $this->createNotFoundException();
        $portfolio = $deposit->getPortfolio() ?? throw $this->createNotFoundException();

        if (!$this->isGranted(PortfolioVoter::VIEW, $portfolio)) {
            throw $this->createNotFoundException();
        }

        $key = 'pdf' === $kind ? $deposit->getPdfKey() : $deposit->getXlsxKey();
        if (null === $key) {
            throw $this->createNotFoundException();
        }

        $student = $portfolio->getStudent();

        return $this->download($this->files->read($key), $deposit->getExam()->value.'-'.self::slug($student?->getDisplayName() ?? 'portfolio').'.'.$kind, 'pdf' === $kind ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    #[Route(path: '/portfolios/showcases/{id}/pdf', name: 'app_portfolios_showcase_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function showcasePdf(int $id, PortfolioShowcaseRepository $showcases, PortfolioPdfExporter $pdf): Response
    {
        $showcase = $showcases->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isGranted(PortfolioVoter::VIEW, $showcase)) {
            throw $this->createNotFoundException();
        }

        return $this->download($pdf->showcase($showcase, $this->renderView(...)), 'fiche-e6-'.$showcase->getNumber().'.pdf', 'application/pdf');
    }

    /**
     * « Exporter les tableaux E5 de la classe (.zip) » - one table per student of the option, from
     * their last endorsed E5 deposit, and a note naming the students who have none.
     */
    #[Route(path: '/portfolios/classes/{programId}/{optionId}/e5.zip', name: 'app_portfolios_class_e5_zip', requirements: ['programId' => '\d+', 'optionId' => '\d+'], methods: ['GET'])]
    public function classE5Zip(int $programId, int $optionId, ProgramRepository $programs, OptionRepository $options, PortfolioQueue $queue): Response
    {
        [$program, $option] = $this->classPair($programId, $optionId, $programs, $options);
        $zipPath = tempnam(sys_get_temp_dir(), 'e5zip');
        $zip = new \ZipArchive();

        if (false === $zipPath || true !== $zip->open($zipPath, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Could not open a temporary archive.');
        }

        $missing = [];
        $portfolios = $queue->portfoliosOf($program, $option);
        foreach ($queue->studentsOf($program, $option) as $student) {
            $portfolio = $portfolios[(int) $student->getId()] ?? null;
            $name = self::slug($student->getLastname().'-'.$student->getFirstname());
            $deposit = null === $portfolio ? null : $this->deposits->latestFor($portfolio, PortfolioExam::E5, true);
            if (null === $deposit) {
                $missing[] = $student->getDisplayName() ?? $student->getUsername();
                continue;
            }
            if (null !== $deposit->getXlsxKey()) {
                $zip->addFromString($name.'.xlsx', $this->files->read($deposit->getXlsxKey()));
            }
            if (null !== $deposit->getPdfKey()) {
                $zip->addFromString($name.'.pdf', $this->files->read($deposit->getPdfKey()));
            }
        }

        $zip->addFromString('LISEZ-MOI.txt', "Tableaux de synthèse E5 - dernier dépôt visé de chaque étudiant.\n\n".([] === $missing ? "Tous les étudiants ont un dépôt visé.\n" : "Sans dépôt visé :\n- ".implode("\n- ", $missing)."\n"));
        $zip->close();

        try {
            return $this->download((string) file_get_contents($zipPath), 'tableaux-e5-'.self::slug($program->getDisplayShortName().'-'.$option->getShortName()).'.zip', 'application/zip');
        } finally {
            @unlink($zipPath);
        }
    }

    /**
     * « Dossiers E6 de la classe (PDF) » - one PDF, the last endorsed E6 deposit of each student of
     * the option, preceded by a page naming the students who have none.
     */
    #[Route(path: '/portfolios/classes/{programId}/{optionId}/e6.pdf', name: 'app_portfolios_class_e6_pdf', requirements: ['programId' => '\d+', 'optionId' => '\d+'], methods: ['GET'])]
    public function classE6Pdf(int $programId, int $optionId, ProgramRepository $programs, OptionRepository $options, PortfolioQueue $queue, PortfolioPdfExporter $pdf): Response
    {
        [$program, $option] = $this->classPair($programId, $optionId, $programs, $options);
        $pdfs = [];
        $missing = [];

        $portfolios = $queue->portfoliosOf($program, $option);
        foreach ($queue->studentsOf($program, $option) as $student) {
            $portfolio = $portfolios[(int) $student->getId()] ?? null;
            $deposit = null === $portfolio ? null : $this->deposits->latestFor($portfolio, PortfolioExam::E6, true);
            if (null === $deposit || null === $deposit->getPdfKey()) {
                $missing[] = $student->getDisplayName() ?? $student->getUsername();
                continue;
            }
            $pdfs[] = $this->files->read($deposit->getPdfKey());
        }

        array_unshift($pdfs, $pdf->plainPage($this->renderView('portfolio/pdf/class_e6_cover.html.twig', [
            'program' => $program,
            'option' => $option,
            'count' => \count($pdfs),
            'missing' => $missing,
        ])));

        return $this->download($pdf->merge($pdfs), 'dossiers-e6-'.self::slug($program->getDisplayShortName().'-'.$option->getShortName()).'.pdf', 'application/pdf');
    }

    /**
     * @return array{0: Program, 1: Option}
     */
    private function classPair(int $programId, int $optionId, ProgramRepository $programs, OptionRepository $options): array
    {
        $this->assertReviewArea($this->validators, $this->access);
        $program = $programs->find($programId) ?? throw $this->createNotFoundException();
        $option = $options->find($optionId) ?? throw $this->createNotFoundException();

        if (!$this->access->isStaff() && !$this->validators->covers($this->currentUser(), $program, $option)) {
            throw $this->createNotFoundException();
        }

        return [$program, $option];
    }
}
