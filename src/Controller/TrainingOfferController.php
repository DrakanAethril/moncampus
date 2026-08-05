<?php

namespace App\Controller;

use App\Entity\Group;
use App\Entity\TrainingOffer;
use App\Entity\User;
use App\Repository\GroupRepository;
use App\Repository\TrainingOfferRepository;
use App\Repository\UserRepository;
use App\Service\FileUploadService;
use App\Service\PdfUploadValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Offres fictives" - the practice offers students apply to
 * (design_handoff_workflow_postulation, screens 7b and 7c), under Gestion > Stages / Alternance.
 *
 * An offer is three decisions: what it says (a title and a PDF), who reviews the applications made
 * on it, and who gets to see it. The last one is expressed in groups, because that is the vocabulary
 * the school already sorts people by - "sio-1 et sio-2" is a sentence a teacher can write without
 * knowing anything about our data model.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class TrainingOfferController extends AbstractController
{
    public function __construct(
        private readonly TrainingOfferRepository $offerRepository,
        private readonly GroupRepository $groupRepository,
        private readonly UserRepository $userRepository,
        private readonly FileUploadService $fileUploadService,
        private readonly PdfUploadValidator $pdfValidator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/gestion/stages-alternance/offres-fictives', name: 'app_training_offers', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('training_offer/index.html.twig', [
            'offers' => $this->offerRepository->findAllOrdered(),
        ]);
    }

    #[Route(path: '/gestion/stages-alternance/offres-fictives/nouvelle', name: 'app_training_offer_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->edit($request, new TrainingOffer());
    }

    #[Route(path: '/gestion/stages-alternance/offres-fictives/{id}', name: 'app_training_offer_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editExisting(Request $request, TrainingOffer $offer): Response
    {
        return $this->edit($request, $offer);
    }

    #[Route(path: '/gestion/stages-alternance/offres-fictives/{id}/supprimer', name: 'app_training_offer_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, TrainingOffer $offer): Response
    {
        if (!$this->isCsrfTokenValid('training_offer_delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($offer);
        $this->entityManager->flush();
        $this->addFlash('success', 'trainingOfferDeletedFlash');

        return $this->redirectToRoute('app_training_offers');
    }

    /** Teachers matching what was typed, for the validators picker. */
    #[Route(path: '/gestion/stages-alternance/offres-fictives/enseignants/recherche', name: 'app_training_offer_teachers', methods: ['GET'])]
    public function teachers(Request $request): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));

        if (mb_strlen($term) < 2) {
            return $this->json(['results' => [], 'pagination' => ['more' => false]]);
        }

        // Teachers, but also staff and admins: this school's staff teach too, and a validator who
        // cannot be named because of their role label would be a rule invented here for nothing.
        $teachers = $this->userRepository->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :teacher OR u.roles LIKE :staff OR u.roles LIKE :admin')
            ->andWhere('u.inactiveDate IS NULL')
            ->andWhere('u.firstname LIKE :term OR u.lastname LIKE :term OR u.username LIKE :term')
            ->setParameter('teacher', '%ROLE_TEACHER%')
            ->setParameter('staff', '%ROLE_STAFF%')
            ->setParameter('admin', '%ROLE_ADMIN%')
            ->setParameter('term', '%'.$term.'%')
            ->orderBy('u.lastname', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return $this->json([
            'results' => array_map(static fn (User $teacher): array => [
                'id' => $teacher->getId(),
                // First name and last name, nothing else: the mockup names its validators, and a
                // login in a chip says nothing about who will read the application.
                'text' => trim(($teacher->getFirstname() ?? '').' '.($teacher->getLastname() ?? '')) ?: $teacher->getUsername(),
            ], $teachers),
            'pagination' => ['more' => false],
        ]);
    }

    private function edit(Request $request, TrainingOffer $offer): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('training_offer', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $error = $this->apply($request, $offer);

            if (null === $error) {
                $this->addFlash('success', 'trainingOfferSavedFlash');

                return $this->redirectToRoute('app_training_offers');
            }

            return $this->renderForm($offer, $error, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderForm($offer);
    }

    private function apply(Request $request, TrainingOffer $offer): ?string
    {
        $title = trim((string) $request->request->get('title', ''));

        if ('' === $title) {
            return 'trainingOfferTitleRequiredError';
        }

        $offer->setTitle($title);

        if (null === $offer->getId()) {
            /** @var User $author */
            $author = $this->getUser();
            $offer->setCreatedBy($author);
        }

        $document = $request->files->get('document');

        if (null !== $document) {
            $documentError = $this->pdfValidator->validate($document);

            if (null !== $documentError) {
                return $documentError;
            }

            $offer
                ->setDocumentKey($this->fileUploadService->upload('training-offers/', $document->getClientOriginalName(), $document))
                ->setDocumentName($document->getClientOriginalName());
        }

        // Validators and groups are replaced wholesale rather than diffed: the form always submits
        // the full picture, and a partial update would silently keep somebody who was removed.
        foreach ($offer->getValidators()->toArray() as $validator) {
            $offer->removeValidator($validator);
        }

        foreach ($request->request->all('validators') as $validatorId) {
            $validator = $this->userRepository->find((int) $validatorId);

            if (null !== $validator) {
                $offer->addValidator($validator);
            }
        }

        foreach ($offer->getVisibilityGroups()->toArray() as $group) {
            $offer->removeVisibilityGroup($group);
        }

        foreach ($request->request->all('groups') as $groupId) {
            $group = $this->groupRepository->find((int) $groupId);

            if ($group instanceof Group) {
                $offer->addVisibilityGroup($group);
            }
        }

        if ($offer->getValidators()->isEmpty()) {
            return 'trainingOfferValidatorRequiredError';
        }

        if ($offer->getVisibilityGroups()->isEmpty()) {
            return 'trainingOfferGroupRequiredError';
        }

        if (null === $offer->getId()) {
            $this->entityManager->persist($offer);
        }

        $this->entityManager->flush();

        return null;
    }

    private function renderForm(TrainingOffer $offer, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('training_offer/form.html.twig', [
            'offer' => $offer,
            'error' => $error,
            // Grouped by type, exactly as the mockup lays the checkboxes out: "Transversaux",
            // "BTS SIO"... The bucketing is the directory's own, borrowed rather than rebuilt.
            'groupsByType' => $this->groupRepository->findAllActiveGroupedByType(),
        ], new Response(status: $status));
    }
}
