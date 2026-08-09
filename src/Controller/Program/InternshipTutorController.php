<?php

namespace App\Controller\Program;

use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Form\InternshipTutorLinkType;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Formation > Livret de l'alternant, onglet « Tuteurs » : les InternshipTutorLink, c'est-à-dire le triplet étudiant / tuteur / entreprise.
 *
 * Split out of the former ProgramInternshipController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class InternshipTutorController extends AbstractController
{
    use ProgramInternshipTrait;

    #[Route(path: '/programs/{id}/internship', name: 'app_program_internship')]
    #[Route(path: '/programs/{id}/internship/tutors', name: 'app_program_internship_tutors')]
    public function tutorsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'tutors');
    }

    #[Route(path: '/programs/{id}/internship/tutors/new', name: 'app_program_internship_tutors_new')]
    #[Route(path: '/programs/{id}/internship/tutors/{tutorLinkId}/edit', name: 'app_program_internship_tutors_edit')]
    public function tutorLinkForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, ?int $tutorLinkId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = null !== $tutorLinkId ? $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId) : new InternshipTutorLink($program);
        $isEdit = null !== $tutorLinkId;

        // Must be resolved and set before handleRequest()/isValid() runs, not after -
        // InternshipTutorLink::$student carries an Assert\NotNull, so setting it only on success
        // would make the form permanently invalid (student is null right up to the point
        // isValid() runs). Same convention as LaptopController::resolveActiveBorrower().
        if ($request->isMethod('POST')) {
            $tutorLink->setStudent($this->resolveProgramStudent($program, $request->request->get('student')));
        }

        $form = $this->createForm(InternshipTutorLinkType::class, $tutorLink, ['program' => $program]);
        $form->handleRequest($request);

        // $tutor is resolved inside the form's own SUBMIT listener, before validation - an
        // existing tutor account picked, or a new one provisioned from the typed contact details.
        if ($form->isSubmitted() && $form->isValid()) {
            $this->stampAuditFields($tutorLink, $isEdit);

            $entityManager->persist($tutorLink);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipTutorLinkUpdatedFlashMessage' : 'internshipTutorLinkCreatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_tutors', ['id' => $program->getId()]);
        }

        return $this->render('program/internship_tutor_link_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    // Backs the student ajax tom-select field in internship_tutor_link_new.html.twig - only the
    // program's own students are eligible, same convention as
    // ProgramTimetableSettingsController::teachersSearch().
    #[Route(path: '/programs/{id}/internship/tutors/students-search', name: 'app_program_internship_tutors_students_search')]
    public function tutorLinkStudentsSearch(int $id, Request $request, ProgramRepository $repository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $limit = 20;
        $query = mb_strtolower((string) $request->query->get('q', ''));

        $candidates = array_values(array_filter(
            $program->getStudents()->toArray(),
            static fn (User $user): bool => '' === $query || str_contains(mb_strtolower($user->getDisplayName() ?? $user->getUsername()), $query),
        ));

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], \array_slice($candidates, 0, $limit)),
            'pagination' => ['more' => \count($candidates) > $limit],
        ]);
    }

    #[Route(path: '/programs/{id}/internship/tutors/{tutorLinkId}/deactivate', name: 'app_program_internship_tutors_deactivate', methods: ['POST'])]
    public function deactivateTutorLink(int $id, int $tutorLinkId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);
        $this->assertValidToken('program_internship_deactivate', $request);

        $tutorLink->setInactiveDate(new \DateTimeImmutable());
        $tutorLink->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/internship/tutors/data', name: 'app_program_internship_tutors_data')]
    public function tutorLinksData(int $id, Request $request, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $tutorLinkRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $tutorLinkRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $tutorLinkRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (InternshipTutorLink $tutorLink): array => [
                    'id' => $tutorLink->getId(),
                    'isInactive' => null !== $tutorLink->getInactiveDate(),
                    // Doubles as the entry point to this student's pedagogical-team remarks -
                    // rendered as trusted HTML by the 'html' render keyword on this column (see
                    // _tutors_content.html.twig), same technique as skillGroupsData()'s 'label'.
                    'studentName' => sprintf(
                        '<a href="%s">%s</a>',
                        htmlspecialchars($this->generateUrl('app_program_internship_tutors_team_evaluations', ['id' => $program->getId(), 'tutorLinkId' => $tutorLink->getId()])),
                        htmlspecialchars($this->userLabel($tutorLink->getStudent())),
                    ),
                    'tutorName' => $this->userLabel($tutorLink->getTutor()),
                    'enterpriseName' => $tutorLink->getEnterprise()?->getName(),
                    'contractStartDate' => $tutorLink->getContractStartDate()?->format('d/m/Y') ?? '—',
                    'contractEndDate' => $tutorLink->getContractEndDate()?->format('d/m/Y') ?? '—',
                    'creationDate' => $tutorLink->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $tutorLink->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($tutorLink->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($tutorLink->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($tutorLink->getLastUpdatedBy()),
                    'lastUpdatedDate' => $tutorLink->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }
}
