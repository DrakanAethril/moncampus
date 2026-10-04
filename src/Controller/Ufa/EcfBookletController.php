<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\EcfActivity;
use App\Entity\EcfBooklet;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Enum\EcfCivility;
use App\Enum\EcfPart;
use App\Enum\EcfResult;
use App\Enum\EcfVisaSlot;
use App\Enum\Feature;
use App\Enum\UfaActivityType;
use App\Repository\InternshipTutorLinkRepository;
use App\Security\Voter\EcfBookletVoter;
use App\Service\Ecf\EcfActivityType;
use App\Service\Ecf\EcfActivityTypes;
use App\Service\Ecf\EcfBookletOverview;
use App\Service\Ecf\EcfBookletPdfExporter;
use App\Service\Ecf\EcfBookletWriter;
use App\Service\Ecf\EcfCriteriaProposer;
use App\Service\Ecf\EcfMastery;
use App\Service\Ecf\EcfOverview;
use App\Service\Ecf\EcfPrintBuilder;
use App\Service\Ecf\EcfRefusal;
use App\Service\Ecf\EcfRowInput;
use App\Service\Ecf\EcfSheetInput;
use App\Service\Ecf\EcfSigner;
use App\Service\PostValue;
use App\Service\UfaActivityRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The ECF booklet of an alternance (design/validated/ecf-booklet.md §4): overview, cover, one sheet
 * per activity-type with its complementary page, synthesis, and the visas.
 *
 * Thin on purpose: who may read or write is EcfBookletVoter's, what may be written
 * EcfBookletWriter's, what may be signed EcfSigner's. Everything the voter refuses answers 404 -
 * a booklet one may not read is a booklet that does not exist.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::UfaEcf)]
class EcfBookletController extends AbstractController
{
    private const string TOKEN = 'ufa_ecf';

    public function __construct(
        private readonly InternshipTutorLinkRepository $tutorLinkRepository,
        private readonly EcfBookletOverview $overviewBuilder,
        private readonly EcfMastery $mastery,
        private readonly EcfSigner $signer,
        private readonly EcfBookletWriter $writer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/ufa/alternances/{id}/ecf', name: 'app_ufa_ecf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function overview(int $id): Response
    {
        [$tutorLink, $overview] = $this->load($id, EcfBookletVoter::VIEW);

        return $this->render('ufa/ecf/overview.html.twig', [
            'tutorLink' => $tutorLink,
            'ecf' => $overview,
            'canEdit' => $this->isGranted(EcfBookletVoter::EDIT, $overview->booklet),
        ]);
    }

    #[Route(path: '/ufa/alternances/{id}/ecf/cover', name: 'app_ufa_ecf_cover', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function cover(int $id, Request $request): Response
    {
        [$tutorLink, $overview] = $this->load($id, EcfBookletVoter::VIEW);
        $booklet = $overview->booklet;
        $canEdit = $this->isGranted(EcfBookletVoter::EDIT, $booklet) && !$booklet->isClosed();

        if ($request->isMethod('POST')) {
            $this->guardWrite($booklet, $request);
            $this->attempt(fn () => $this->writer->saveCover(
                $booklet,
                EcfCivility::tryFrom(PostValue::string($request, 'civility')),
                EcfRowInput::date(PostValue::string($request, 'birthDate')),
                $this->user(),
            ), 'ecfCoverSavedFlashMessage');

            return $this->redirectToRoute('app_ufa_ecf_cover', ['id' => $id]);
        }

        return $this->render('ufa/ecf/cover.html.twig', [
            'tutorLink' => $tutorLink,
            'ecf' => $overview,
            'canEdit' => $canEdit,
            'civilities' => EcfCivility::cases(),
        ]);
    }

    #[Route(path: '/ufa/alternances/{id}/ecf/activities/{code}', name: 'app_ufa_ecf_activity', requirements: ['id' => '\d+', 'code' => '[^/]+'], methods: ['GET'])]
    public function activity(int $id, string $code, EcfCriteriaProposer $proposer): Response
    {
        [$tutorLink, $overview] = $this->load($id, EcfBookletVoter::VIEW);
        $booklet = $overview->booklet;
        $type = EcfActivityTypes::find(array_map(static fn (array $row): EcfActivityType => $row['type'], $overview->rows), $code);
        $activity = $booklet->activityFor($code);
        if (null === $type && null === $activity) {
            throw $this->createNotFoundException();
        }

        $canEdit = $this->isGranted(EcfBookletVoter::EDIT, $booklet) && !$booklet->isClosed() && null !== $type;
        $canSign = $this->isGranted(EcfBookletVoter::SIGN, $booklet) && null !== $type;

        return $this->render('ufa/ecf/activity.html.twig', [
            'tutorLink' => $tutorLink,
            'ecf' => $overview,
            'type' => $type,
            'code' => $code,
            'activity' => $activity,
            'label' => $activity?->getFrozenLabel() ?? $type->label ?? $code,
            'competences' => $activity?->getFrozenCompetences() ?? $type->competences ?? [],
            'state' => $this->mastery->state($booklet, $activity),
            'main' => $this->partView($booklet, $activity, EcfPart::Main, $canEdit, $canSign),
            'complementary' => $this->partView($booklet, $activity, EcfPart::Complementary, $canEdit && $this->writer->complementaryOpen($booklet, $activity), $canSign),
            'complementaryOpen' => $this->writer->complementaryOpen($booklet, $activity) || [] !== ($activity?->rowsOf(EcfPart::Complementary) ?? []),
            'proposals' => null !== $type ? $proposer->proposalsFor($type) : [],
        ]);
    }

    #[Route(path: '/ufa/alternances/{id}/ecf/activities/{code}', name: 'app_ufa_ecf_activity_save', requirements: ['id' => '\d+', 'code' => '[^/]+'], methods: ['POST'])]
    public function saveActivity(int $id, string $code, Request $request): Response
    {
        [, $overview] = $this->load($id, EcfBookletVoter::VIEW);
        $booklet = $overview->booklet;
        $this->guardWrite($booklet, $request);
        $this->typeOf($overview, $code);
        $part = EcfPart::Complementary->value === PostValue::string($request, 'part') ? EcfPart::Complementary : EcfPart::Main;

        $input = new EcfSheetInput(
            EcfRowInput::listFromRequest($request),
            EcfResult::tryFrom(PostValue::string($request, 'result')),
            PostValue::string($request, 'attentionPoints'),
            PostValue::string($request, 'reassessNote'),
            PostValue::intList($request, 'reassessCompetences'),
            PostValue::string($request, 'observations'),
        );
        $this->attempt(fn () => $this->writer->saveSheet($booklet, $code, $part, $input, $this->user()), 'ecfSheetSavedFlashMessage');

        return $this->redirect($this->generateUrl('app_ufa_ecf_activity', ['id' => $id, 'code' => $code]).'#'.$part->value);
    }

    #[Route(path: '/ufa/alternances/{id}/ecf/synthesis', name: 'app_ufa_ecf_synthesis', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function synthesis(int $id, Request $request): Response
    {
        [$tutorLink, $overview] = $this->load($id, EcfBookletVoter::VIEW);
        $booklet = $overview->booklet;

        if ($request->isMethod('POST')) {
            $this->guardWrite($booklet, $request, allowClosed: true);
            $remittedOn = EcfRowInput::date(PostValue::string($request, 'remittedOn'));
            $this->attempt(fn () => $this->mastery->isSigned($booklet, null, EcfPart::Synthesis)
                ? $this->writer->saveRemittance($booklet, $remittedOn, $this->user())
                : $this->writer->saveSynthesis($booklet, PostValue::string($request, 'observations'), $remittedOn, $this->user()), 'ecfSynthesisSavedFlashMessage');

            return $this->redirectToRoute('app_ufa_ecf_synthesis', ['id' => $id]);
        }

        $canSign = $this->isGranted(EcfBookletVoter::SIGN, $booklet);
        $types = array_map(static fn (array $row): EcfActivityType => $row['type'], $overview->rows);
        $visas = EcfMastery::visasOf($booklet, null, EcfPart::Synthesis);

        return $this->render('ufa/ecf/synthesis.html.twig', [
            'tutorLink' => $tutorLink,
            'ecf' => $overview,
            'canEdit' => $this->isGranted(EcfBookletVoter::EDIT, $booklet),
            'signed' => [] !== $visas,
            'mastery' => array_map(fn (array $row): ?bool => $this->mastery->isMastered($booklet, $row['activity']), $overview->rows),
            'visas' => $this->visaView($booklet, $visas, EcfPart::Synthesis, $canSign, $this->signer->synthesisMissing($booklet, $types), new \DateTimeImmutable('today')),
        ]);
    }

    #[Route(path: '/ufa/alternances/{id}/ecf/read', name: 'app_ufa_ecf_read', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function read(int $id, EcfPrintBuilder $printBuilder): Response
    {
        [$tutorLink, $overview] = $this->load($id, EcfBookletVoter::VIEW);

        return $this->render('ufa/ecf/read.html.twig', [
            'tutorLink' => $tutorLink,
            'ecf' => $overview,
            'outline' => EcfPrintBuilder::outline($printBuilder->build($overview)['activities']),
        ]);
    }

    #[Route(path: '/ufa/alternances/{id}/ecf/frame', name: 'app_ufa_ecf_frame', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function frame(int $id, EcfBookletPdfExporter $exporter): Response
    {
        [, $overview] = $this->load($id, EcfBookletVoter::VIEW);

        return new Response($exporter->screen($overview));
    }

    #[Route(path: '/ufa/alternances/{id}/ecf/pdf', name: 'app_ufa_ecf_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(int $id, EcfBookletPdfExporter $exporter, SluggerInterface $slugger): Response
    {
        [$tutorLink, $overview] = $this->load($id, EcfBookletVoter::VIEW);
        $student = $tutorLink->getStudent();
        $name = strtolower($slugger->slug(sprintf('livret-ecf-%s-%s', $student?->getLastname() ?? '', $student?->getFirstname() ?? ''))->toString());

        try {
            $pdf = $exporter->export($overview);
        } catch (\Throwable $exception) {
            $this->logger->error('ECF booklet PDF export failed', ['exception' => $exception, 'tutorLink' => $id]);
            $this->addFlash('danger', 'ecfPdfFailedFlashMessage');

            return $this->redirectToRoute('app_ufa_ecf', ['id' => $id]);
        }

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name.'.pdf'),
        ]);
    }

    #[Route(path: '/ufa/alternances/{id}/ecf/sign', name: 'app_ufa_ecf_sign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sign(int $id, Request $request, UfaActivityRecorder $recorder): Response
    {
        [$tutorLink, $overview] = $this->load($id, EcfBookletVoter::VIEW);
        $booklet = $overview->booklet;
        $this->guardSign($booklet, $request);
        $part = EcfPart::tryFrom(PostValue::string($request, 'part')) ?? throw $this->createNotFoundException();
        $slot = EcfVisaSlot::tryFrom(PostValue::string($request, 'slot')) ?? throw $this->createNotFoundException();
        $evaluatedOn = EcfRowInput::date(PostValue::string($request, 'evaluatedOn'));
        $code = PostValue::string($request, 'code');
        $type = EcfPart::Synthesis === $part ? null : $this->typeOf($overview, $code);
        $types = array_map(static fn (array $row): EcfActivityType => $row['type'], $overview->rows);

        $signed = null === $evaluatedOn
            ? $this->refuse('ecfRefusalDateMissingMessage')
            : $this->attempt(fn () => $this->signer->sign($booklet, $type, $part, $slot, $this->user(), $evaluatedOn, new \DateTimeImmutable(), $types), 'ecfSignedFlashMessage');
        if ($signed) {
            $recorder->record(UfaActivityType::EcfSigned, $tutorLink, $this->user(), null, ['part' => $this->partLabel($type, $part)]);
        }

        return $this->backTo($id, $part, $code);
    }

    #[Route(path: '/ufa/alternances/{id}/ecf/unsign', name: 'app_ufa_ecf_unsign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unsign(int $id, Request $request, UfaActivityRecorder $recorder): Response
    {
        [$tutorLink, $overview] = $this->load($id, EcfBookletVoter::VIEW);
        $booklet = $overview->booklet;
        $this->guardSign($booklet, $request);
        $part = EcfPart::tryFrom(PostValue::string($request, 'part')) ?? throw $this->createNotFoundException();
        $code = PostValue::string($request, 'code');
        $activity = EcfPart::Synthesis === $part ? null : ($booklet->activityFor($code) ?? throw $this->createNotFoundException());
        $type = null === $activity ? null : EcfActivityTypes::find(array_map(static fn (array $row): EcfActivityType => $row['type'], $overview->rows), $code);

        if ($this->attempt(fn () => $this->signer->unsign($booklet, $activity, $part), 'ecfUnsignedFlashMessage')) {
            $recorder->record(UfaActivityType::EcfUnsigned, $tutorLink, $this->user(), null, ['part' => $this->partLabel($type, $part, $code)]);
        }

        return $this->backTo($id, $part, $code);
    }

    /**
     * @return array{0: InternshipTutorLink, 1: EcfOverview}
     */
    private function load(int $id, string $attribute): array
    {
        $tutorLink = $this->tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $overview = $this->overviewBuilder->build($tutorLink) ?? throw $this->createNotFoundException();
        if (!$this->isGranted($attribute, $overview->booklet)) {
            throw $this->createNotFoundException();
        }

        return [$tutorLink, $overview];
    }

    private function typeOf(EcfOverview $overview, string $code): EcfActivityType
    {
        return EcfActivityTypes::find(array_map(static fn (array $row): EcfActivityType => $row['type'], $overview->rows), $code) ?? throw $this->createNotFoundException();
    }

    private function guardWrite(EcfBooklet $booklet, Request $request, bool $allowClosed = false): void
    {
        if (!$this->isGranted(EcfBookletVoter::EDIT, $booklet)) {
            throw $this->createNotFoundException();
        }
        $this->guardToken($request);
        if (!$allowClosed && $booklet->isClosed()) {
            throw $this->createNotFoundException();
        }
    }

    private function guardSign(EcfBooklet $booklet, Request $request): void
    {
        if (!$this->isGranted(EcfBookletVoter::SIGN, $booklet)) {
            throw $this->createNotFoundException();
        }
        $this->guardToken($request);
    }

    private function guardToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /** Runs a write, flashes its success or the refusal it met, and says which. */
    private function attempt(callable $write, string $successKey): bool
    {
        try {
            $write();
        } catch (EcfRefusal $refusal) {
            return $this->refuse($refusal->key, $refusal->params);
        }
        $this->addFlash('success', $successKey);

        return true;
    }

    /** @param array<string, string> $params */
    private function refuse(string $key, array $params = []): bool
    {
        $this->addFlash('danger', $this->translator->trans($key, $params));

        return false;
    }

    private function backTo(int $id, EcfPart $part, string $code): Response
    {
        if (EcfPart::Synthesis === $part) {
            return $this->redirectToRoute('app_ufa_ecf_synthesis', ['id' => $id]);
        }

        return $this->redirect($this->generateUrl('app_ufa_ecf_activity', ['id' => $id, 'code' => $code]).'#'.$part->value);
    }

    private function partLabel(?EcfActivityType $type, EcfPart $part, string $code = ''): string
    {
        if (EcfPart::Synthesis === $part) {
            return $this->translator->trans('ecfSynthesisLabel');
        }

        $label = null !== $type ? $this->translator->trans('ecfActivityTypeNumberLabel', ['%number%' => (string) $type->number]) : $code;

        return EcfPart::Complementary === $part ? $label.' · '.$this->translator->trans('ecfComplementaryTitle') : $label;
    }

    /**
     * @return array{rows: list<\App\Entity\EcfEvaluationRow>, signed: bool, editable: bool, visas: array<string, mixed>}
     */
    private function partView(EcfBooklet $booklet, ?EcfActivity $activity, EcfPart $part, bool $canEdit, bool $canSign): array
    {
        $visas = EcfMastery::visasOf($booklet, $activity, $part);
        $rows = $activity?->rowsOf($part) ?? [];
        $lastDate = null;
        foreach ($rows as $row) {
            if (null !== $row->getEvaluatedOn() && (null === $lastDate || $row->getEvaluatedOn() > $lastDate)) {
                $lastDate = $row->getEvaluatedOn();
            }
        }
        $today = new \DateTimeImmutable('today');

        return [
            'rows' => $rows,
            'signed' => [] !== $visas,
            'editable' => $canEdit && [] === $visas,
            'visas' => $this->visaView($booklet, $visas, $part, $canSign && !$booklet->isClosed(), $this->signer->missing($booklet, $activity, $part), null !== $lastDate && $lastDate <= $today ? $lastDate : $today),
        ];
    }

    /**
     * The visa lines of a part, and what the connected person may do with them.
     *
     * @param list<\App\Entity\EcfVisa> $visas
     * @param list<string>              $missing
     *
     * @return array<string, mixed>
     */
    private function visaView(EcfBooklet $booklet, array $visas, EcfPart $part, bool $canSign, array $missing, \DateTimeImmutable $defaultDate): array
    {
        $bySlot = [];
        foreach ($visas as $visa) {
            $bySlot[$visa->getSlot()->value] = $visa;
        }
        // Every free line the connected person may sign, by EcfSigner's own rules: lines in order,
        // one person per evaluator line, the representative after the first evaluator.
        $user = $this->user();
        $evaluated = [] !== array_filter($visas, static fn ($visa): bool => $visa->getSigner() === $user && EcfVisaSlot::Representative !== $visa->getSlot());
        $offers = [];
        foreach (EcfVisaSlot::forPart($part) as $slot) {
            if (isset($bySlot[$slot->value])) {
                continue;
            }
            $afterFirst = EcfVisaSlot::Evaluator1 === $slot || isset($bySlot[EcfVisaSlot::Evaluator1->value]);
            $mayTake = EcfVisaSlot::Representative === $slot || !$evaluated;
            if ($afterFirst && $mayTake) {
                $offers[] = $slot;
            }
            if (EcfVisaSlot::Evaluator1 === $slot) {
                break;
            }
        }

        return [
            'part' => $part,
            'slots' => EcfVisaSlot::forPart($part),
            'bySlot' => $bySlot,
            'offers' => $canSign && !$booklet->isClosed() ? $offers : [],
            'canUnsign' => $this->isGranted(EcfBookletVoter::SIGN, $booklet) && [] !== $visas,
            'missing' => $missing,
            'defaultDate' => $defaultDate,
            'printedName' => EcfSigner::printedName($user),
        ];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
