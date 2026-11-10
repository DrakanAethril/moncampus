<?php

declare(strict_types=1);

namespace App\Controller\SeatingPlan;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\SeatingFurniture;
use App\Enum\SeatingRuleType;
use App\Repository\SeatingPlanRepository;
use App\Repository\SeatingRoomLayoutRepository;
use App\Repository\UserRepository;
use App\Security\Voter\SeatingPlanVoter;
use App\Service\JsonRequestPayload;
use App\Service\QueryValue;
use App\Service\SeatingPlan\InvalidSeatingPlanException;
use App\Service\SeatingPlan\SeatingPlanDocument;
use App\Service\SeatingPlan\SeatingPlanPrintView;
use App\Service\SeatingPlan\SeatingPlans;
use App\Service\SeatingPlan\SeatingRoom;
use App\Service\SeatingPlan\SeatingRoomTemplates;
use App\Service\SeatingPlan\SeatingStudent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One seating plan (design/validated/plans-de-classe.md, §3 to §8): the three steps - the room,
 * the rules, the plan - on one page, and the requests that page makes. The page keeps the
 * document in hand and sends it whole; every request reads it again through
 * SeatingPlanDocument::fromArray(), so what the server checks, proposes or saves is never a
 * document it has not validated.
 *
 * Nothing is saved but by « Enregistrer »: proposing and checking answer, they write nothing.
 */
#[RequiresFeature(Feature::SeatingPlans)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'), statusCode: 404)]
class PlanController extends AbstractController
{
    use SeatingPlanControllerTrait;

    private const array STEPS = ['room', 'rules', 'plan'];

    #[Route(path: '/tools/seating-plans/{id}', name: 'app_seating_plan_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request, SeatingPlanRepository $repository, SeatingPlans $seatingPlans, SeatingRoomLayoutRepository $layouts, SeatingRoomTemplates $templates, TranslatorInterface $translator): Response
    {
        $plan = $this->findPlan($id, $repository);
        $roster = $seatingPlans->roster($plan);
        $document = $seatingPlans->document($plan, $roster);
        $canEdit = $this->isGranted(SeatingPlanVoter::EDIT, $plan);

        $step = QueryValue::trimmed($request, 'step');
        if (!$canEdit || !\in_array($step, self::STEPS, true)) {
            $step = 'plan';
        }

        $roomLayouts = [];
        foreach ($layouts->findForActiveRooms() as $layout) {
            try {
                $room = SeatingPlanDocument::room($layout->getLayout());
            } catch (InvalidSeatingPlanException) {
                continue;
            }
            $roomLayouts[] = [
                'label' => $translator->trans('seatingRoomLayoutLabel', ['%room%' => $layout->getRoom()->getName()]),
                'description' => $translator->trans('seatingRoomLayoutBy', ['%name%' => $layout->getUpdatedBy()?->getDisplayName() ?? $layout->getUpdatedBy()?->getUsername() ?? '—']),
                'seats' => \count($room->seats()),
                'room' => $room->toArray(),
            ];
        }

        $options = array_values(array_unique(array_filter(array_map(static fn (SeatingStudent $student): ?string => $student->option, $roster))));
        sort($options);

        return $this->render('seating_plan/show.html.twig', [
            'plan' => $plan,
            'step' => $step,
            'canEdit' => $canEdit,
            'canManage' => $this->isGranted(SeatingPlanVoter::MANAGE, $plan),
            'options' => $options,
            'config' => [
                'document' => $document->toArray(),
                'roster' => array_values(array_map(static fn (SeatingStudent $student): array => $student->toArray(), $roster)),
                'options' => $options,
                'check' => $seatingPlans->check($document, $roster),
                'revision' => $plan->getRevision(),
                'step' => $step,
                'canEdit' => $canEdit,
                'canManage' => $this->isGranted(SeatingPlanVoter::MANAGE, $plan),
                'hasRoom' => null !== $plan->getRoom(),
                'furniture' => array_map(static fn (SeatingFurniture $furniture): array => [
                    'type' => $furniture->value,
                    'label' => $translator->trans($furniture->labelKey()),
                    'width' => $furniture->width(),
                    'height' => $furniture->height(),
                    'seats' => $furniture->seatCount(),
                    'canRotate' => $furniture->canRotate(),
                ], SeatingFurniture::cases()),
                'ruleTypes' => array_map(static fn (SeatingRuleType $type): array => [
                    'type' => $type->value,
                    'label' => $translator->trans($type->labelKey()),
                    'description' => $translator->trans($type->descriptionKey()),
                    'need' => $translator->trans($type->needKey(), ['%max%' => $type->maxStudents()]),
                    'tone' => $type->badgeTone()->value,
                    'min' => $type->minStudents(),
                    'max' => $type->maxStudents(),
                ], SeatingRuleType::cases()),
                'templates' => array_values(array_map(static fn (array $template): array => [
                    'label' => $translator->trans($template['labelKey']),
                    'description' => $translator->trans($template['descriptionKey']),
                    'seats' => \count($template['room']->seats()),
                    'room' => $template['room']->toArray(),
                ], $templates->all())),
                'roomLayouts' => $roomLayouts,
                'limits' => ['minColumns' => SeatingRoom::MIN_COLUMNS, 'maxColumns' => SeatingRoom::MAX_COLUMNS, 'minRows' => SeatingRoom::MIN_ROWS, 'maxRows' => SeatingRoom::MAX_ROWS],
            ],
        ]);
    }

    #[Route(path: '/tools/seating-plans/{id}/save', name: 'app_seating_plan_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function save(int $id, Request $request, SeatingPlanRepository $repository, SeatingPlans $seatingPlans, EntityManagerInterface $entityManager, LoggerInterface $logger): JsonResponse
    {
        $this->assertJsonCsrf($request);
        $plan = $this->findPlan($id, $repository, SeatingPlanVoter::EDIT);
        $payload = JsonRequestPayload::fromRequest($request);

        if ($payload->int('revision') !== $plan->getRevision()) {
            return $this->json(['error' => 'stale_revision'], Response::HTTP_CONFLICT);
        }

        $roster = $seatingPlans->roster($plan);
        try {
            $document = SeatingPlanDocument::fromArray($payload->object('document')->toArray(), array_keys($roster));
        } catch (InvalidSeatingPlanException $exception) {
            $logger->warning('Seating plan refused: {reason}', ['reason' => $exception->getMessage(), 'plan' => $plan->getId()]);

            return $this->json(['error' => $exception->reasonKey], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The name is the author's to change; a colleague who may modify the plan moves students,
        // not the title the author files it under.
        if ($this->isGranted(SeatingPlanVoter::MANAGE, $plan)) {
            $name = mb_substr(trim($payload->string('name')), 0, 255);
            if ('' === $name) {
                return $this->json(['error' => 'seatingPlanEmptyNameError'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $plan->setName($name);
        }

        $plan->replaceDocument($document->toArray(), $this->currentUser());
        $entityManager->flush();

        return $this->json(['revision' => $plan->getRevision(), 'name' => $plan->getName(), 'savedAt' => $plan->getUpdatedAt()->format('H:i')]);
    }

    #[Route(path: '/tools/seating-plans/{id}/propose', name: 'app_seating_plan_propose', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function propose(int $id, Request $request, SeatingPlanRepository $repository, SeatingPlans $seatingPlans): JsonResponse
    {
        $this->assertJsonCsrf($request);
        $plan = $this->findPlan($id, $repository, SeatingPlanVoter::EDIT);
        $roster = $seatingPlans->roster($plan);

        $document = $this->readDocument($request, array_keys($roster));
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $seed = $document->seed + 1;
        $proposed = $document->withSeats($seatingPlans->propose($document, $roster, $seed), $seed);

        return $this->json(['seats' => (object) $proposed->seats, 'seed' => $seed, 'check' => $seatingPlans->check($proposed, $roster)]);
    }

    #[Route(path: '/tools/seating-plans/{id}/check', name: 'app_seating_plan_check', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function check(int $id, Request $request, SeatingPlanRepository $repository, SeatingPlans $seatingPlans): JsonResponse
    {
        $this->assertJsonCsrf($request);
        $plan = $this->findPlan($id, $repository, SeatingPlanVoter::EDIT);
        $roster = $seatingPlans->roster($plan);

        $document = $this->readDocument($request, array_keys($roster));
        if ($document instanceof JsonResponse) {
            return $document;
        }

        return $this->json(['check' => $seatingPlans->check($document, $roster)]);
    }

    // « Enregistrer comme disposition de la salle »: the room as this plan draws it becomes the
    // starting point of the next plans made in that room, for every colleague.
    #[Route(path: '/tools/seating-plans/{id}/room-layout', name: 'app_seating_plan_room_layout', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function roomLayout(int $id, Request $request, SeatingPlanRepository $repository, SeatingPlans $seatingPlans, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->assertJsonCsrf($request);
        $plan = $this->findPlan($id, $repository, SeatingPlanVoter::EDIT);
        $room = $plan->getRoom();
        if (null === $room) {
            return $this->json(['error' => 'seatingPlanNoRoomError'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $seatingRoom = SeatingPlanDocument::room(JsonRequestPayload::fromRequest($request)->object('room')->toArray());
        } catch (InvalidSeatingPlanException $exception) {
            return $this->json(['error' => $exception->reasonKey], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $layout = $seatingPlans->redrawRoom($room, $seatingRoom, $this->currentUser());
        $entityManager->persist($layout);
        $entityManager->flush();

        return $this->json(['room' => $room->getName()]);
    }

    #[Route(path: '/tools/seating-plans/{id}/share', name: 'app_seating_plan_share', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function share(int $id, Request $request, SeatingPlanRepository $repository, UserRepository $users, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $plan = $this->findPlan($id, $repository, SeatingPlanVoter::MANAGE);

        $ids = array_values(array_filter(array_map('intval', $request->request->all('teachers')), static fn (int $teacherId): bool => $teacherId > 0));
        $teachers = array_filter(
            [] === $ids ? [] : $users->findByIds($ids),
            static fn (User $teacher): bool => \in_array('ROLE_TEACHER', $teacher->getRoles(), true),
        );

        $plan->shareWith($teachers, 'edit' === $request->request->getString('right'));
        $plan->markModified($this->currentUser());
        $entityManager->flush();
        $this->addFlash('success', [] === $teachers ? 'seatingPlanUnsharedFlashMessage' : 'seatingPlanSharedFlashMessage');

        return $this->redirectToRoute('app_seating_plan_show', ['id' => $plan->getId()]);
    }

    /** The colleague picker of « Partager » - tomselect + ajax, the platform's rule for picking people. */
    #[Route(path: '/tools/seating-plans/colleagues', name: 'app_seating_plan_colleagues', methods: ['GET'])]
    public function colleagues(Request $request, UserRepository $users): JsonResponse
    {
        $me = $this->currentUser();

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], \array_slice($users->findActiveMatchingRoles(['ROLE_TEACHER'], [(int) $me->getId()], QueryValue::trimmed($request, 'q')), 0, 20)),
            'pagination' => ['more' => false],
        ]);
    }

    #[Route(path: '/tools/seating-plans/{id}/print', name: 'app_seating_plan_print', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function printPlan(int $id, Request $request, SeatingPlanRepository $repository, SeatingPlans $seatingPlans, SeatingPlanPrintView $printView): Response
    {
        $plan = $this->findPlan($id, $repository);
        $roster = $seatingPlans->roster($plan);
        $fromDesk = 'desk' === QueryValue::trimmed($request, 'view');
        $fullNames = QueryValue::bool($request, 'full');

        return $this->render('seating_plan/print.html.twig', [
            'plan' => $plan,
            'layout' => $printView->layout($seatingPlans->document($plan, $roster), $roster, $fromDesk, $fullNames),
            'fromDesk' => $fromDesk,
            'fullNames' => $fullNames,
            'studentsViewUrl' => $this->printUrl($plan->getId(), false, $fullNames),
            'deskViewUrl' => $this->printUrl($plan->getId(), true, $fullNames),
            'namesToggleUrl' => $this->printUrl($plan->getId(), $fromDesk, !$fullNames),
        ]);
    }

    private function printUrl(?int $id, bool $fromDesk, bool $fullNames): string
    {
        return $this->generateUrl('app_seating_plan_print', array_filter(['id' => $id, 'view' => $fromDesk ? 'desk' : null, 'full' => $fullNames ? '1' : null]));
    }

    /**
     * @param list<int> $rosterIds
     */
    private function readDocument(Request $request, array $rosterIds): SeatingPlanDocument|JsonResponse
    {
        try {
            return SeatingPlanDocument::fromArray(JsonRequestPayload::fromRequest($request)->object('document')->toArray(), $rosterIds);
        } catch (InvalidSeatingPlanException $exception) {
            return $this->json(['error' => $exception->reasonKey], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function assertJsonCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
