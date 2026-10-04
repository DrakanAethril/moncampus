<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\UfaActivityType;
use App\Repository\InternshipTutorLinkRepository;
use App\Security\Voter\EcfBookletVoter;
use App\Service\Ecf\EcfBookletLocator;
use App\Service\Ecf\EcfBookletOverview;
use App\Service\Ecf\EcfBookletPdfExporter;
use App\Service\Ecf\EcfCandidateSignature;
use App\Service\Ecf\EcfOverview;
use App\Service\Ecf\EcfPrintBuilder;
use App\Service\Ecf\EcfRefusal;
use App\Service\StudentAlternanceProgramResolver;
use App\Service\UfaActivityRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Mon alternance » › Livret ECF: the student reads the booklet the administration handed them and
 * signs it « pour information » (design/validated/ecf-booklet.md §12). Nothing exists here before
 * the offer: EcfBookletVoter::CANDIDATE_READ answers for the student's own booklet once offered,
 * and anything else is a 404.
 */
#[RequiresFeature(Feature::MyAlternance)]
#[IsGranted('ROLE_STUDENT')]
class MyEcfBookletController extends AbstractController
{
    private const string TOKEN = 'my_ecf_sign';

    public function __construct(
        private readonly StudentAlternanceProgramResolver $programResolver,
        private readonly InternshipTutorLinkRepository $tutorLinkRepository,
        private readonly EcfBookletLocator $locator,
        private readonly EcfBookletOverview $overviewBuilder,
    ) {
    }

    #[Route(path: '/my/alternance/ecf', name: 'app_my_alternance_ecf', methods: ['GET'])]
    public function read(EcfPrintBuilder $printBuilder): Response
    {
        [$tutorLink, $overview] = $this->load();

        return $this->render('my_alternance/ecf.html.twig', [
            'tutorLink' => $tutorLink,
            'booklet' => $overview->booklet,
            'outline' => EcfPrintBuilder::outline($printBuilder->build($overview)['activities']),
            'canSign' => $this->isGranted(EcfBookletVoter::CANDIDATE_SIGN, $overview->booklet),
        ]);
    }

    #[Route(path: '/my/alternance/ecf/frame', name: 'app_my_alternance_ecf_frame', methods: ['GET'])]
    public function frame(EcfBookletPdfExporter $exporter): Response
    {
        [, $overview] = $this->load();

        return new Response($exporter->screen($overview));
    }

    #[Route(path: '/my/alternance/ecf/pdf', name: 'app_my_alternance_ecf_pdf', methods: ['GET'])]
    public function pdf(EcfBookletPdfExporter $exporter, SluggerInterface $slugger, LoggerInterface $logger): Response
    {
        [, $overview] = $this->load();

        try {
            $pdf = $exporter->export($overview);
        } catch (\Throwable $exception) {
            $logger->error('ECF booklet PDF export failed', ['exception' => $exception]);
            $this->addFlash('danger', 'ecfPdfFailedFlashMessage');

            return $this->redirectToRoute('app_my_alternance_ecf');
        }

        $student = $overview->booklet->getStudent();

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, strtolower($slugger->slug(sprintf('livret-ecf-%s-%s', $student->getLastname() ?? '', $student->getFirstname() ?? ''))->toString()).'.pdf'),
        ]);
    }

    #[Route(path: '/my/alternance/ecf/sign', name: 'app_my_alternance_ecf_sign', methods: ['POST'])]
    public function sign(Request $request, EcfCandidateSignature $candidateSignature, UfaActivityRecorder $recorder, TranslatorInterface $translator): Response
    {
        [$tutorLink, $overview] = $this->load();
        $booklet = $overview->booklet;
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!$this->isGranted(EcfBookletVoter::CANDIDATE_SIGN, $booklet)) {
            $this->addFlash('danger', $translator->trans('ecfRefusalCandidateCannotSignMessage'));

            return $this->redirectToRoute('app_my_alternance_ecf');
        }

        try {
            $candidateSignature->sign($booklet, $this->student(), new \DateTimeImmutable());
        } catch (EcfRefusal $refusal) {
            $this->addFlash('danger', $translator->trans($refusal->key, $refusal->params));

            return $this->redirectToRoute('app_my_alternance_ecf');
        }
        $recorder->record(UfaActivityType::EcfCandidateSigned, $tutorLink, $this->student());
        $this->addFlash('success', 'ecfCandidateSignedFlashMessage');

        return $this->redirectToRoute('app_my_alternance_ecf');
    }

    /**
     * @return array{0: InternshipTutorLink, 1: EcfOverview}
     */
    private function load(): array
    {
        $student = $this->student();
        $program = $this->programResolver->resolve($student) ?? throw $this->createNotFoundException();
        $tutorLink = $this->tutorLinkRepository->findOneForStudentAndProgram($student, $program) ?? throw $this->createNotFoundException();
        $booklet = $this->locator->find($tutorLink);
        if (null === $booklet || !$this->isGranted(EcfBookletVoter::CANDIDATE_READ, $booklet)) {
            throw $this->createNotFoundException();
        }

        return [$tutorLink, $this->overviewBuilder->build($tutorLink) ?? throw $this->createNotFoundException()];
    }

    private function student(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
