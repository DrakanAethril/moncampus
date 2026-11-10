<?php

declare(strict_types=1);

namespace App\Controller\SeatingPlan;

use App\Attribute\RequiresFeature;
use App\Entity\SeatingPlan;
use App\Enum\Feature;
use App\Repository\RoomRepository;
use App\Repository\SeatingPlanRepository;
use App\Repository\SeatingRoomLayoutRepository;
use App\Security\Voter\SeatingPlanVoter;
use App\Service\ClassBoard\ClassBoardPrograms;
use App\Service\QueryValue;
use App\Service\SeatingPlan\SeatingPlans;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Plans de classe », the list (design/validated/plans-de-classe.md, §2): one's own plans, the
 * ones colleagues shared, and the gestures that need no plan open - create, duplicate, delete.
 * Plain forms answered by a redirect.
 *
 * A teacher's tool, like the rest of « Animer la classe »: whoever neither teaches nor works for the
 * administration is answered 404 even where the feature has been lit for them.
 */
#[RequiresFeature(Feature::SeatingPlans)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'), statusCode: 404)]
class ListController extends AbstractController
{
    use SeatingPlanControllerTrait;

    #[Route(path: '/tools/seating-plans', name: 'app_seating_plan_index', methods: ['GET'])]
    public function index(Request $request, SeatingPlanRepository $repository, SeatingRoomLayoutRepository $layouts, RoomRepository $rooms, ClassBoardPrograms $programs): Response
    {
        $user = $this->currentUser();
        $mine = $repository->findForOwner($user);
        $shared = $repository->findSharedWith($user);

        // « Toutes les classes » or one class: the filter offers the classes one teaches and the
        // ones plans are made for - a class one no longer teaches still has plans to find.
        $classes = [];
        foreach ([...$programs->linkable($user), ...array_map(static fn (SeatingPlan $plan) => $plan->getProgram(), [...$mine, ...$shared])] as $program) {
            $classes[(int) $program->getId()] = $program;
        }
        uasort($classes, static fn ($a, $b): int => strcasecmp($a->getDisplayShortName(), $b->getDisplayShortName()));

        $filter = QueryValue::nullableInt($request, 'class');
        if (null !== $filter) {
            $mine = array_values(array_filter($mine, static fn (SeatingPlan $plan): bool => $plan->getProgram()->getId() === $filter));
            $shared = array_values(array_filter($shared, static fn (SeatingPlan $plan): bool => $plan->getProgram()->getId() === $filter));
        }

        $layoutsByRoom = [];
        foreach ($layouts->findForActiveRooms() as $layout) {
            $layoutsByRoom[(int) $layout->getRoom()->getId()] = $layout;
        }

        return $this->render('seating_plan/index.html.twig', [
            'plans' => $mine,
            'sharedPlans' => $shared,
            'classes' => array_values($classes),
            'filter' => $filter,
            'linkablePrograms' => $programs->linkable($user),
            'rooms' => $rooms->findAllActiveOrderedByName(),
            'layoutsByRoom' => $layoutsByRoom,
        ]);
    }

    #[Route(path: '/tools/seating-plans', name: 'app_seating_plan_create', methods: ['POST'])]
    public function create(Request $request, ClassBoardPrograms $programs, RoomRepository $rooms, SeatingPlans $seatingPlans, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $this->assertCsrf($request);
        $user = $this->currentUser();

        $programField = $request->request->getString('program');
        $program = $programs->find($user, ctype_digit($programField) ? (int) $programField : null);
        $roomField = $request->request->getString('room');
        $room = ctype_digit($roomField) ? $rooms->find((int) $roomField) : null;
        if (null === $program || null === $room || null !== $room->getInactiveDate()) {
            $this->addFlash('error', 'seatingPlanChooseClassAndRoomFlashMessage');

            return $this->redirectToRoute('app_seating_plan_index');
        }

        $name = mb_substr(trim($request->request->getString('name')), 0, 255);
        if ('' === $name) {
            $name = $translator->trans('seatingPlanProposedName', ['%class%' => $program->getDisplayShortName(), '%room%' => $room->getName()]);
        }

        $plan = $seatingPlans->start($user, $program, $room, $name);
        $entityManager->persist($plan);
        $entityManager->flush();

        return $this->redirectToRoute('app_seating_plan_show', ['id' => $plan->getId(), 'step' => 'room']);
    }

    // A copy is always one's own, shared with nobody - which is also how a colleague's plan, read
    // only, becomes one they may change.
    #[Route(path: '/tools/seating-plans/{id}/duplicate', name: 'app_seating_plan_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(int $id, Request $request, SeatingPlanRepository $repository, SeatingPlans $seatingPlans, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $this->assertCsrf($request);
        $plan = $this->findPlan($id, $repository);

        $copy = $seatingPlans->duplicate($plan, $this->currentUser(), mb_substr($translator->trans('seatingPlanCopyName', ['%name%' => $plan->getName()]), 0, 255));
        $entityManager->persist($copy);
        $entityManager->flush();
        $this->addFlash('success', 'seatingPlanDuplicatedFlashMessage');

        return $this->redirectToRoute('app_seating_plan_show', ['id' => $copy->getId()]);
    }

    #[Route(path: '/tools/seating-plans/{id}/delete', name: 'app_seating_plan_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, SeatingPlanRepository $repository, EntityManagerInterface $entityManager): Response
    {
        $this->assertCsrf($request);
        $plan = $this->findPlan($id, $repository, SeatingPlanVoter::MANAGE);

        $entityManager->remove($plan);
        $entityManager->flush();
        $this->addFlash('success', 'seatingPlanDeletedFlashMessage');

        return $this->redirectToRoute('app_seating_plan_index');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
