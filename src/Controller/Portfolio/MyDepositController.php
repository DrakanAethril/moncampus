<?php

declare(strict_types=1);

namespace App\Controller\Portfolio;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Enum\PortfolioExam;
use App\Form\PortfolioEvidenceType;
use App\Repository\PortfolioDepositRepository;
use App\Repository\ReferentialTemplateRepository;
use App\Service\Portfolio\PortfolioContext;
use App\Service\Portfolio\PortfolioDepositor;
use App\Service\Portfolio\PortfolioEvidenceIntake;
use App\Service\Portfolio\PortfolioFileStore;
use App\Service\Portfolio\PortfolioShowcaseChecker;
use App\Service\Portfolio\PortfolioSynthesis;
use App\Service\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Mon portfolio › Dépôt » (design/validated/portfolio.md, screen 9).
 *
 * Two deposits, because the E5 and the E6 each have their dossier, their date and their conformity
 * check. The tab gathers what the dossiers need - the candidate number, the address of the online
 * portfolio the commission reads, the internship attestations - then one « Déposer » per exam.
 * Earlier deposits stay listed with their state and the reason when one was asked to regularise.
 */
#[RequiresFeature(Feature::Portfolio)]
class MyDepositController extends AbstractController
{
    use MyPortfolioTrait;
    use PortfolioDownloadTrait;

    public function __construct(
        private readonly PortfolioContext $context,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/my/portfolio/deposit', name: 'app_my_portfolio_deposit', methods: ['GET'])]
    public function index(PortfolioDepositRepository $deposits, PortfolioDepositor $depositor, PortfolioSynthesis $synthesis, PortfolioShowcaseChecker $checker, ReferentialTemplateRepository $templates): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $program = $this->context->currentProgram($this->currentUser(), $portfolio->getReferential());
        $referential = $portfolio->getReferential();

        return $this->render('portfolio/my/deposit.html.twig', [
            'portfolio' => $portfolio,
            'program' => $program,
            'deposits' => $deposits->findForPortfolio($portfolio),
            'refusals' => ['e5' => $depositor->refusals($portfolio, PortfolioExam::E5), 'e6' => $depositor->refusals($portfolio, PortfolioExam::E6)],
            'table' => $synthesis->build($portfolio),
            'e6Coverage' => $checker->check($this->context->claimableBlocks($portfolio)['showcase'], $portfolio->getShowcases()),
            'templateInService' => null !== $referential && null !== $templates->findInService($referential, $program?->getPortfolioExamSession()),
            'attestationForm' => $this->createForm(PortfolioEvidenceType::class, null, ['action' => $this->generateUrl('app_my_portfolio_attestation')]),
            'option' => $this->context->optionOfPortfolio($portfolio),
            'cursusYear' => $this->context->cursusYear($portfolio),
            'activeTab' => 'deposit',
        ]);
    }

    #[Route(path: '/my/portfolio/deposit/identity', name: 'app_my_portfolio_identity', methods: ['POST'])]
    public function identity(Request $request): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $this->assertToken($request);

        $url = PostValue::trimmed($request, 'externalUrl');
        if ('' !== $url && (false === filter_var($url, \FILTER_VALIDATE_URL) || !\in_array(strtolower((string) parse_url($url, \PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $this->addFlash('error', 'portfolioExternalUrlInvalidError');

            return $this->redirectToRoute('app_my_portfolio_deposit');
        }

        $portfolio->setCandidateNumber(mb_substr(PostValue::trimmed($request, 'candidateNumber'), 0, 30));
        $portfolio->setExternalUrl(mb_substr($url, 0, 500));
        $this->entityManager->flush();
        $this->addFlash('success', 'portfolioIdentitySavedFlashMessage');

        return $this->redirectToRoute('app_my_portfolio_deposit');
    }

    #[Route(path: '/my/portfolio/deposit/attestations', name: 'app_my_portfolio_attestation', methods: ['POST'])]
    public function addAttestation(Request $request, PortfolioEvidenceIntake $intake): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $form = $this->createForm(PortfolioEvidenceType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $evidence = $intake->fromForm($form, $portfolio);
            if (\is_string($evidence)) {
                $this->addFlash('error', $evidence);
            } else {
                $this->entityManager->persist($evidence);
                $this->entityManager->flush();
                $this->addFlash('success', 'portfolioEvidenceAddedFlashMessage');
            }
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_my_portfolio_deposit');
    }

    #[Route(path: '/my/portfolio/deposit/attestations/{evidenceId}/remove', name: 'app_my_portfolio_attestation_remove', requirements: ['evidenceId' => '\d+'], methods: ['POST'])]
    public function removeAttestation(int $evidenceId, Request $request): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $this->assertToken($request);

        foreach ($portfolio->getAttestations() as $evidence) {
            if ($evidence->getId() === $evidenceId) {
                $portfolio->removeAttestation($evidence);
                $this->entityManager->remove($evidence);
                $this->entityManager->flush();
                $this->addFlash('success', 'portfolioEvidenceRemovedFlashMessage');

                return $this->redirectToRoute('app_my_portfolio_deposit');
            }
        }

        throw $this->createNotFoundException();
    }

    #[Route(path: '/my/portfolio/deposit/{exam}', name: 'app_my_portfolio_deposit_make', requirements: ['exam' => 'e5|e6'], methods: ['POST'])]
    public function deposit(string $exam, Request $request, PortfolioDepositor $depositor): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $this->assertToken($request);

        try {
            $deposit = $depositor->deposit($portfolio, PortfolioExam::from($exam), $this->currentUser(), $this->renderView(...));
            $this->addFlash('success', $deposit->isLate() ? 'portfolioDepositLateFlashMessage' : 'portfolioDepositMadeFlashMessage');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_my_portfolio_deposit');
    }

    #[Route(path: '/my/portfolio/deposits/{id}/{kind}', name: 'app_my_portfolio_deposit_file', requirements: ['id' => '\d+', 'kind' => 'pdf|xlsx'], methods: ['GET'])]
    public function depositFile(int $id, string $kind, PortfolioDepositRepository $deposits, PortfolioFileStore $files): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $deposit = $deposits->find($id);

        if (null === $deposit || $deposit->getPortfolio()?->getId() !== $portfolio->getId()) {
            throw $this->createNotFoundException();
        }

        $key = 'pdf' === $kind ? $deposit->getPdfKey() : $deposit->getXlsxKey();
        if (null === $key) {
            throw $this->createNotFoundException();
        }

        return $this->download($files->read($key), 'depot-'.$deposit->getExam()->value.'-'.$deposit->getDepositedAt()->format('Y-m-d').'.'.$kind, 'pdf' === $kind ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
