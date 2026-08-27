<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\EngagementDeclaration;
use App\Entity\EngagementDeclarationAttachment;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\EngagementKind;
use App\Enum\Feature;
use App\Form\EngagementDeclarationType;
use App\Repository\EngagementDeclarationRepository;
use App\Repository\ProgramRepository;
use App\Security\StructureAccessChecker;
use App\Service\FormValue;
use App\Service\Game\EngagementDeclarationService;
use App\Service\Game\EngagementRefused;
use App\Service\Game\GameAccess;
use App\Service\Game\GamePeriodResolver;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Declared engagement, both sides (design's screen 13).
 *
 * The student files; a teacher of the class validates or refuses. **Nothing is credited before
 * validation**, and the value credited is the kind's own - a validator never chooses an amount, or
 * the whole equity of §2 would leave through that door.
 *
 * A refusal is motivated and read by the student, and the declaration stays in the queue struck
 * through rather than disappearing: that is what stops the same thing being re-filed three times in
 * the hope of another reviewer.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::Game)]
class GameEngagementController extends AbstractController
{
    #[Route(path: '/game/engagement/new', name: 'app_game_engagement_new', methods: ['GET', 'POST'])]
    public function declare(
        Request $request,
        GameAccess $access,
        GamePeriodResolver $periods,
        EngagementDeclarationService $service,
        EngagementDeclarationRepository $declarations,
        UploadIntake $uploadIntake,
        EntityManagerInterface $entityManager,
    ): Response {
        $student = $this->currentUser();
        $program = $access->primaryProgramFor($student) ?? throw $this->createNotFoundException();
        $period = $periods->activePeriod($program) ?? throw $this->createNotFoundException();

        $form = $this->createForm(EngagementDeclarationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $kind = $form->get('kind')->getData();

            try {
                if (!$kind instanceof EngagementKind) {
                    throw new EngagementRefused('engagementKindPlaceholder');
                }

                $declaration = $service->file($student, $program, $period, $kind, FormValue::trimmed($form, 'description'));
                $this->attach($declaration, $form->get('attachments')->getData(), $uploadIntake);
                $entityManager->flush();

                $this->addFlash('success', 'engagementFiledFlashMessage');

                return $this->redirectToRoute('app_game_engagement_new');
            } catch (EngagementRefused $refusal) {
                $this->addFlash('error', $refusal->getMessage());
            }
        }

        // A POST re-rendered with a 200 is thrown away by Turbo and the student watches their error
        // never appear - the round trip this repository has already paid for twice. An invalid or
        // refused submission answers 422 so the page it renders actually replaces the one on screen.
        $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('game/engagement_new.html.twig', [
            'form' => $form,
            'program' => $program,
            'period' => $period,
            'kinds' => EngagementKind::cases(),
            'mine' => $declarations->findForStudent($student, $program, $period),
        ], new Response('', $status));
    }

    /**
     * The validation queue - a teacher of the class, and staff.
     *
     * Scoped to the one formation the teacher opened it from, or to their own if they teach a single
     * one: nothing here ever lists two classes side by side (§4, decision 1).
     */
    #[Route(path: '/game/engagements', name: 'app_game_engagements', methods: ['GET'])]
    public function queue(
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        GamePeriodResolver $periods,
        EngagementDeclarationRepository $declarations,
    ): Response {
        $teaching = $this->teachingPrograms($programs, $access, $accessChecker);

        if ([] === $teaching) {
            throw $this->createNotFoundException();
        }

        $asked = $request->query->getInt('program');
        $program = $this->pick($teaching, $asked);
        $period = $periods->activePeriod($program) ?? throw $this->createNotFoundException();

        return $this->render('game/engagement_queue.html.twig', [
            'programs' => $teaching,
            'program' => $program,
            'period' => $period,
            'declarations' => $declarations->queueFor($program, $period),
            'waiting' => $declarations->countWaiting($program, $period),
        ]);
    }

    #[Route(path: '/game/engagements/{id}/review', name: 'app_game_engagements_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function review(
        int $id,
        Request $request,
        EngagementDeclarationRepository $declarations,
        GameAccess $access,
        StructureAccessChecker $accessChecker,
        EngagementDeclarationService $service,
    ): Response {
        if (!$this->isCsrfTokenValid('game_engagement_review', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $declaration = $declarations->find($id) ?? throw $this->createNotFoundException();
        $program = $declaration->getProgram();

        if (!$access->isOpen($program)) {
            throw $this->createNotFoundException();
        }

        if (!$accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        try {
            if ('refuse' === $request->request->get('decision')) {
                $service->refuse($declaration, $this->currentUser(), (string) $request->request->get('reason'));
                $this->addFlash('success', 'engagementRefusedFlashMessage');
            } else {
                $service->validate($declaration, $this->currentUser());
                $this->addFlash('success', 'engagementValidatedFlashMessage');
            }
        } catch (EngagementRefused $refusal) {
            $this->addFlash('error', $refusal->getMessage());
        }

        return $this->redirectToRoute('app_game_engagements', ['program' => $program->getId()]);
    }

    /**
     * The evidence, stored through the shared intake - the form carried tokens, never bytes.
     *
     * @param mixed $files what App\Form\FilePickerType hands back: a list of staged uploads
     */
    private function attach(EngagementDeclaration $declaration, mixed $files, UploadIntake $uploadIntake): void
    {
        if (!\is_array($files)) {
            return;
        }

        $position = 0;
        foreach ($files as $file) {
            $name = UploadIntake::originalName($file);
            $key = $uploadIntake->store($file, 'game/engagement/', $name);

            new EngagementDeclarationAttachment($declaration, $key, $name, $position++);
        }
    }

    /**
     * The formations this person may review for - the game's own, and only where they teach.
     *
     * @return list<Program>
     */
    private function teachingPrograms(ProgramRepository $programs, GameAccess $access, StructureAccessChecker $accessChecker): array
    {
        // Staff reach every formation, so their list is drawn from all of them rather than from
        // what they happen to be enrolled in - which, for an administrator, is nothing at all.
        // isProgramTeacher() still decides, one formation at a time; this only chooses what to ask
        // it about.
        $candidates = $accessChecker->isStaff()
            ? $programs->findAllActiveWithStudents()
            : $programs->findAllWithMember($this->currentUser());

        return array_values(array_filter(
            $candidates,
            static fn (Program $program): bool => $access->isOpen($program) && $accessChecker->isProgramTeacher($program),
        ));
    }

    /** @param list<Program> $programs */
    private function pick(array $programs, int $asked): Program
    {
        foreach ($programs as $program) {
            if ($program->getId() === $asked) {
                return $program;
            }
        }

        return $programs[0];
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
