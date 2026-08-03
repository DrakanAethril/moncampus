<?php

namespace App\Controller;

use App\Entity\Assignment;
use App\Entity\AssignmentCompletion;
use App\Entity\User;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\ProgramRepository;
use App\Repository\SelfAssessmentRepository;
use App\Repository\QuizAttemptRepository;
use App\Service\AssignmentAudienceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Travail à réaliser » (design_handoff_cahier_de_texte 4a) : tout ce qu'un étudiant a à faire,
 * toutes matières et toutes séances confondues.
 *
 * Transversal aux formations, contrairement à App\Controller\ProgramAssignmentSubmissionController
 * qui reste la page d'un devoir donné - c'est elle qui reçoit le dépôt de fichier, et cet écran-ci
 * y renvoie. Le tableau de bord montre les sept jours qui viennent et pointe ici pour le reste.
 */
#[IsGranted('ROLE_STUDENT')]
class StudentWorkController extends AbstractController
{
    #[Route(path: '/travail-a-realiser', name: 'app_student_work')]
    public function index(Request $request, ProgramRepository $programRepository, AssignmentRepository $assignmentRepository, AssignmentSubmissionRepository $submissionRepository, AssignmentCompletionRepository $completionRepository, QuizAttemptRepository $attemptRepository, SelfAssessmentRepository $selfAssessmentRepository, AssignmentAudienceResolver $audienceResolver): Response
    {
        $student = $this->currentUser();
        $programs = $programRepository->findAllActiveForStudent($student);
        $now = new \DateTimeImmutable();

        $assignments = array_values(array_filter(
            $assignmentRepository->findVisibleForPrograms($programs, $now),
            static fn (Assignment $a): bool => $audienceResolver->isInAudience($a, $student),
        ));

        $doneIds = $completionRepository->findDoneAssignmentIds($assignments, $student);
        $submitted = [];
        foreach ($assignments as $assignment) {
            // Un travail à déposer se solde par son dépôt, un quiz par une tentative menée à son
            // terme : ni l'un ni l'autre ne demande à l'étudiant de déclarer qu'il a fini.
            $proved = match (true) {
                $assignment->expectsSubmission() => null !== $submissionRepository->findOneForAssignmentAndStudent($assignment, $student),
                null !== $assignment->getQuizInstance() => null !== $attemptRepository->findLastConcluded($assignment->getQuizInstance(), $student),
                // Une autoévaluation se solde par son estimation validée - le brouillon ne compte
                // pas, il se reprend.
                $assignment->getNature()->expectsSelfAssessment() => true === $selfAssessmentRepository->findOneForStudent($assignment, $student)?->isValidated(),
                default => false,
            };

            if ($proved) {
                $submitted[] = $assignment->getId();
            }
        }

        // Un travail quitte le « à faire » dès qu'il est déposé ou déclaré fait - la maquette n'a
        // que ces deux onglets, et un travail en retard reste à faire tant qu'il n'est ni l'un ni
        // l'autre.
        $todo = [];
        $done = [];
        foreach ($assignments as $assignment) {
            $isDone = \in_array($assignment->getId(), $doneIds, true) || \in_array($assignment->getId(), $submitted, true);
            $isDone ? $done[] = $assignment : $todo[] = $assignment;
        }

        // Filtre par matière : la formation, faute de mieux - c'est ce qui distingue les travaux
        // d'un étudiant dans cette application, et c'est ce que la maquette appelle « matière ».
        $programFilter = $request->query->getInt('formation');
        $visible = static fn (array $list): array => 0 === $programFilter
            ? $list
            : array_values(array_filter($list, static fn (Assignment $a): bool => $a->getProgram()?->getId() === $programFilter));

        $todoVisible = $visible($todo);

        return $this->render('student/work.html.twig', [
            'groups' => $this->groupByDeadline($todoVisible, $now),
            'doneCount' => \count($visible($done)),
            'todoCount' => \count($todoVisible),
            'lateCount' => \count(array_filter($todoVisible, static fn (Assignment $a): bool => $a->getDueDate() < $now)),
            'done' => $visible($done),
            'tab' => 'done' === $request->query->get('onglet') ? 'done' : 'todo',
            'programs' => $programs,
            'programFilter' => $programFilter,
            'now' => $now,
        ]);
    }

    /**
     * Les trois groupes de la maquette : ce qui est déjà en retard, ce qui tombe dans les sept
     * jours, le reste. Chronologique à l'intérieur de chacun, la requête les rendant déjà triés.
     *
     * @param list<Assignment> $assignments
     *
     * @return array<string, list<Assignment>>
     */
    private function groupByDeadline(array $assignments, \DateTimeImmutable $now): array
    {
        $groups = ['late' => [], 'week' => [], 'later' => []];
        $weekEnd = $now->modify('+7 days');

        foreach ($assignments as $assignment) {
            $due = $assignment->getDueDate();
            $groups[match (true) {
                $due < $now => 'late',
                $due <= $weekEnd => 'week',
                default => 'later',
            }][] = $assignment;
        }

        return $groups;
    }

    /**
     * « Marquer comme fait » et son retour en arrière : une ligne apparaît ou disparaît, l'absence
     * de ligne valant « à faire ». Réservé aux travaux sans dépôt ni passation, qui ont leur propre
     * preuve d'achèvement.
     */
    #[Route(path: '/travail-a-realiser/{assignmentId}/fait', name: 'app_student_work_done', methods: ['POST'])]
    public function toggleDone(int $assignmentId, Request $request, EntityManagerInterface $entityManager, AssignmentRepository $assignmentRepository, AssignmentCompletionRepository $completionRepository, AssignmentAudienceResolver $audienceResolver): Response
    {
        if (!$this->isCsrfTokenValid('student_work_done', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $student = $this->currentUser();
        $assignment = $assignmentRepository->find($assignmentId) ?? throw $this->createNotFoundException();

        if (!$assignment->getNature()->expectsSelfDeclaration() || !$audienceResolver->isInAudience($assignment, $student)) {
            throw $this->createAccessDeniedException();
        }

        $existing = $completionRepository->findOneFor($assignment, $student);
        $existing ? $entityManager->remove($existing) : $entityManager->persist(new AssignmentCompletion($assignment, $student));
        $entityManager->flush();

        return $this->redirectToRoute('app_student_work', $request->query->all());
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
