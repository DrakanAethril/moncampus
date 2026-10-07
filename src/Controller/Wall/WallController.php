<?php

declare(strict_types=1);

namespace App\Controller\Wall;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\User;
use App\Entity\Wall;
use App\Enum\Feature;
use App\Enum\WallRole;
use App\Repository\ProgramRepository;
use App\Repository\WallRepository;
use App\Security\Voter\WallVoter;
use App\Service\JsonRequestPayload;
use App\Service\PostValue;
use App\Service\QueryValue;
use App\Service\Wall\WallAccess;
use App\Service\Wall\WallAudience;
use App\Service\Wall\WallBoardView;
use App\Service\Wall\WallLiveNotifier;
use App\Service\Wall\WallRefusal;
use App\Service\Wall\WallResponder;
use App\Service\Wall\WallWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A wall itself (design/design_handoff_murs_collaboratifs, screens 3, 6 and 7): the page, the
 * reading its browsers ask for again, its settings, its sharing, its deletion, and the two
 * fragments the page fetches when asked - the destinations of « Copier / Déplacer » and the slides
 * of « Mode projection ».
 */
#[RequiresFeature(Feature::Walls)]
#[IsGranted(new Expression(WallAccess::ELIGIBLE_EXPRESSION), statusCode: 404)]
class WallController extends AbstractController
{
    use WallControllerTrait;

    #[Route(path: '/walls/{id}', name: 'app_wall_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request, EntityManagerInterface $entityManager, WallBoardView $view, WallAudience $audience, WallLiveNotifier $notifier, WallResponder $responder, ProgramRepository $programs, HubInterface $hub, Authorization $mercureAuthorization): Response
    {
        $wall = $this->wall($id, $entityManager);
        $board = $view->build($wall, $this->currentUser());
        // The same route serves one's own wall (Outils) and a student's wall read by their teacher
        // (Ressources › Murs étudiants): the navigation bar is told which, having only the route.
        $request->attributes->set('wall_supervised', WallRole::Supervisor === $board['role']);

        // The live subscription is a comfort, never a condition: without it the wall still follows
        // every gesture made in this browser, and catches up on the others' at the next one.
        $live = true;
        try {
            $mercureAuthorization->setCookie($request, [$notifier->topic($wall)], [], [], 'subscriber');
        } catch (\Throwable) {
            $live = false;
        }

        return $this->render('wall/show.html.twig', [
            'wall' => $wall,
            'board' => $board,
            'supervisedClass' => WallRole::Supervisor === $board['role'] ? $this->classReadFrom($request, $programs) : null,
            'view' => $wall->getFormat(),
            'people' => $view->people($wall),
            'classes' => $board['owns'] ? $this->offeredClasses($wall, $audience) : [],
            'settings' => $responder->settings($wall),
            'backgroundColors' => WallWriter::BACKGROUND_COLORS,
            'mercurePublicUrl' => $live ? $hub->getPublicUrl() : '',
            'topic' => $notifier->topic($wall),
        ]);
    }

    // What a browser asks when it learns the wall has moved, when it switches view and when it
    // opens a card: the wall as this person reads it now.
    #[Route(path: '/walls/{id}/state', name: 'app_wall_state', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function state(int $id, Request $request, EntityManagerInterface $entityManager, WallResponder $responder): JsonResponse
    {
        return $responder->state($this->wall($id, $entityManager), $this->currentUser(), $request);
    }

    // « Paramètres du mur », one setting at a time: each switch writes as it is flipped.
    #[Route(path: '/walls/{id}/settings', name: 'app_wall_settings', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function settings(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $wall = $this->wall($id, $entityManager, WallVoter::MANAGE);

        try {
            $writer->applySettings($wall, JsonRequestPayload::fromRequest($request), $this->currentUser());
        } catch (WallRefusal $refusal) {
            return $responder->refusal($refusal);
        }
        $responder->commit($wall);

        return $responder->state($wall, $this->currentUser(), $request);
    }

    // « Partager »: the whole audience, classes and people, replaced by what the dialog holds.
    #[Route(path: '/walls/{id}/sharing', name: 'app_wall_sharing', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function sharing(int $id, Request $request, EntityManagerInterface $entityManager, WallAudience $audience, WallResponder $responder): Response
    {
        $this->assertCsrf($request);
        $wall = $this->wall($id, $entityManager, WallVoter::OWN);

        $audience->share($wall, PostValue::intList($request, 'programs'), PostValue::intList($request, 'members'));
        $responder->commit($wall);
        $this->addFlash('success', 'wallSharingSavedFlashMessage');

        return $this->redirectToRoute('app_wall_show', ['id' => $wall->getId()]);
    }

    #[Route(path: '/walls/{id}/delete', name: 'app_wall_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallLiveNotifier $notifier): Response
    {
        $this->assertCsrf($request);
        $wall = $this->wall($id, $entityManager, WallVoter::OWN);

        // Told before the row goes: afterwards the wall has no id left to name its topic with.
        $notifier->publish($wall, deleted: true);
        $writer->delete($wall);
        $entityManager->flush();
        $this->addFlash('success', 'wallDeletedFlashMessage');

        return $this->redirectToRoute('app_walls');
    }

    /**
     * The sharing dialogs' people picker - tomselect + ajax, per the repository's rule. It offers
     * what App\Service\Wall\WallAudience allows this person to add, and nothing wider.
     */
    #[Route(path: '/walls/people', name: 'app_wall_people', methods: ['GET'])]
    public function people(Request $request, WallAudience $audience): JsonResponse
    {
        $limit = 20;
        $people = $audience->peopleFor($this->currentUser(), QueryValue::trimmed($request, 'q'), $limit);

        return $this->json([
            'results' => array_map(static fn (User $person): array => [
                'id' => $person->getId(),
                'text' => $person->getDisplayName() ?? $person->getUsername(),
            ], $people),
            'pagination' => ['more' => \count($people) === $limit],
        ]);
    }

    /**
     * The walls a card or a list of this wall may be copied or moved to: for a card, every wall
     * this person may add a card on; for a list, every wall they run. The current wall is one of
     * them - moving a card to another list of the same wall is a destination like any other.
     */
    #[Route(path: '/walls/{id}/destinations', name: 'app_wall_destinations', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function destinations(int $id, Request $request, EntityManagerInterface $entityManager, WallRepository $walls): Response
    {
        $wall = $this->wall($id, $entityManager);
        $user = $this->currentUser();
        $forList = 'list' === QueryValue::string($request, 'kind');

        $destinations = array_values(array_filter(
            [...$walls->findOwnedBy($user), ...$walls->findSharedWith($user)],
            fn (Wall $candidate): bool => $this->isGranted($forList ? WallVoter::MANAGE : WallVoter::ADD_CARD, $candidate)
                && ($forList || !$candidate->getLists()->isEmpty()),
        ));

        return $this->render('wall/_destinations.html.twig', [
            'wall' => $wall,
            'destinations' => $destinations,
            'forList' => $forList,
        ]);
    }

    #[Route(path: '/walls/{id}/projection', name: 'app_wall_projection', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function projection(int $id, EntityManagerInterface $entityManager, WallBoardView $view): Response
    {
        $wall = $this->wall($id, $entityManager);

        return $this->render('wall/_projection.html.twig', [
            'wall' => $wall,
            'slides' => $view->slides($wall),
        ]);
    }

    /**
     * The class a supervising teacher came from (« Murs étudiants » links with `?class=`), for the
     * trail to lead back to it. Only ever one of the classes they teach: the parameter draws a
     * breadcrumb, it opens nothing.
     */
    private function classReadFrom(Request $request, ProgramRepository $programs): ?Program
    {
        $id = QueryValue::nullableInt($request, 'class');
        foreach (null === $id ? [] : $programs->findAllForTeacher($this->currentUser()) as $program) {
            if ($program->getId() === $id) {
                return $program;
            }
        }

        return null;
    }

    /**
     * The classes the sharing dialog lists: the ones the owner may add, plus the ones already on
     * the wall - a class one no longer teaches has to stay listed to be unticked.
     *
     * @return list<Program>
     */
    private function offeredClasses(Wall $wall, WallAudience $audience): array
    {
        $classes = [];
        foreach ([...$audience->classesFor($wall->getOwner()), ...$wall->getPrograms()->toArray()] as $program) {
            $classes[(int) $program->getId()] = $program;
        }

        return array_values($classes);
    }
}
