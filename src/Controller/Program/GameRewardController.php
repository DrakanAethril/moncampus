<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\RewardItem;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\RewardNature;
use App\Enum\RewardScope;
use App\Repository\ProgramRepository;
use App\Repository\RewardGrantRepository;
use App\Repository\RewardItemRepository;
use App\Repository\UserRepository;
use App\Security\StructureAccessChecker;
use App\Service\Game\GameAccess;
use App\Service\Game\GamePeriodResolver;
use App\Service\Game\RewardGranter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The reward catalogue of one formation, and the granting of what is in it (design's screen 8).
 *
 * The banner the screen leads with is the rule that is easiest to break by accident, so it is said
 * on the screen rather than only in the documentation: **granting a reward never moves the ranking.**
 * A reward gives no points and touches no index - if it did, a teacher could lift a student above
 * the others with one click and outside their envelope.
 *
 * The four tiers are entries of this same catalogue, marked automatic, granted at closure. They are
 * listed here so a teacher can see what the machine hands out beside what they hand out themselves.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::Game)]
class GameRewardController extends AbstractController
{
    #[Route(path: '/programs/{id}/game/rewards', name: 'app_program_game_rewards', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(
        int $id,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GamePeriodResolver $periods,
        RewardItemRepository $items,
        RewardGrantRepository $grants,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);
        // A formation with no period at all still has a catalogue and can still thank somebody: a
        // reward is not the outcome of a closure, and refusing the screen would say it was.
        $period = $periods->activePeriod($program);

        $catalogue = $items->catalogueFor($program);

        return $this->render('game/rewards.html.twig', [
            'program' => $program,
            'period' => $period,
            'catalogue' => $catalogue,
            'counts' => $this->countsOf($catalogue, $grants),
            'granted' => $grants->grantedIn($program),
            'students' => $this->orderedStudents($program),
            'natures' => RewardNature::cases(),
            'scopes' => RewardScope::cases(),
        ]);
    }

    /** Create an entry in this formation's own catalogue. */
    #[Route(path: '/programs/{id}/game/rewards/new', name: 'app_program_game_rewards_new', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(
        int $id,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        EntityManagerInterface $entityManager,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);

        if (!$this->isCsrfTokenValid('game_reward_new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $label = trim((string) $request->request->get('label'));
        $nature = RewardNature::tryFrom((string) $request->request->get('nature'));
        $scope = RewardScope::tryFrom((string) $request->request->get('scope'));

        if ('' === $label || null === $nature || null === $scope) {
            $this->addFlash('error', 'rewardCreateRefusedMessage');

            return $this->redirectToRoute('app_program_game_rewards', ['id' => $program->getId()]);
        }

        $item = (new RewardItem($program, $label, $nature, $scope))
            ->setDescription(trim((string) $request->request->get('description')))
            ->setIcon(mb_substr(trim((string) $request->request->get('icon')), 0, 2));

        $quantity = $request->request->get('quantity');
        $item->setQuantity(null === $quantity || '' === $quantity ? null : max(0, (int) $quantity));

        $entityManager->persist($item);
        $entityManager->flush();

        $this->addFlash('success', 'rewardCreatedFlashMessage');

        return $this->redirectToRoute('app_program_game_rewards', ['id' => $program->getId()]);
    }

    /** Grant one entry, to a student or to the whole class. */
    #[Route(path: '/programs/{id}/game/rewards/{itemId}/grant', name: 'app_program_game_rewards_grant', requirements: ['id' => '\d+', 'itemId' => '\d+'], methods: ['POST'])]
    public function grant(
        int $id,
        int $itemId,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GamePeriodResolver $periods,
        RewardItemRepository $items,
        UserRepository $users,
        RewardGranter $granter,
    ): Response {
        $program = $this->openProgram($id, $programs, $access, $accessChecker);
        $period = $periods->activePeriod($program);

        if (!$this->isCsrfTokenValid('game_reward_grant', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $item = $items->find($itemId) ?? throw $this->createNotFoundException();

        if (null !== $item->getProgram() && $item->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        if ($item->isAutomatic()) {
            // A tier is granted by the closure and by nothing else: a hand-granted bronze would be
            // a tier that says nothing about an index.
            $this->addFlash('error', 'rewardAutomaticRefusedMessage');

            return $this->redirectToRoute('app_program_game_rewards', ['id' => $program->getId()]);
        }

        $reason = trim((string) $request->request->get('reason'));

        if (RewardScope::ClassWide === $item->getScope()) {
            $granter->grantToGroup($item, $this->orderedStudents($program), $program, $period, $this->currentUser(), null, $reason);
            $this->addFlash('success', 'rewardGrantedFlashMessage');

            return $this->redirectToRoute('app_program_game_rewards', ['id' => $program->getId()]);
        }

        $student = $users->find($request->request->getInt('student')) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($student)) {
            throw $this->createNotFoundException();
        }

        $granter->grantToStudent($item, $student, $program, $period, $this->currentUser(), $reason);
        $this->addFlash('success', 'rewardGrantedFlashMessage');

        return $this->redirectToRoute('app_program_game_rewards', ['id' => $program->getId()]);
    }

    /**
     * @param list<RewardItem> $catalogue
     *
     * @return array<int, int> item id => how many are in circulation
     */
    private function countsOf(array $catalogue, RewardGrantRepository $grants): array
    {
        $counts = [];
        foreach ($catalogue as $item) {
            $counts[(int) $item->getId()] = $grants->countGranted($item);
        }

        return $counts;
    }

    /** @return list<User> */
    private function orderedStudents(Program $program): array
    {
        $students = array_values($program->getStudents()->toArray());
        usort($students, static fn (User $a, User $b): int => strcasecmp(
            $a->getDisplayName() ?? $a->getUsername(),
            $b->getDisplayName() ?? $b->getUsername(),
        ));

        return $students;
    }

    private function openProgram(int $id, ProgramRepository $programs, GameAccess $access, StructureAccessChecker $accessChecker): Program
    {
        $program = $programs->find($id) ?? throw $this->createNotFoundException();

        if (!$access->isOpen($program)) {
            throw $this->createNotFoundException();
        }

        if (!$accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
