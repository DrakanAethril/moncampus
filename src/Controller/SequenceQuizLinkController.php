<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Enum\Feature;
use App\Repository\QuizTemplateRepository;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\Voter\QuizTemplateVoter;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\PostValue;
use App\Service\SequenceQuizLinker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Attaching an existing quiz to a séquence or to one of its séances, and detaching it again.
 *
 * A controller of its own rather than four more actions on App\Controller\SequenceLibraryController,
 * which is fat enough - and these four say one thing: a link is not a possession. Nothing here ever
 * deletes a quiz; App\Service\SequenceQuizLinker owns the rules, including the one asymmetry (the
 * séquence's détachement reaches its séances, the séance's does not reach the séquence).
 *
 * Creating a quiz from a séquence or a séance is the other way in, and it already exists: the quiz
 * assistant's import step attaches what it produced (App\Controller\QuizImportController).
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::QuizLibrary)]
class SequenceQuizLinkController extends AbstractController
{
    public function __construct(
        private readonly SequenceTemplateRepository $sequences,
        private readonly SeanceTemplateRepository $seances,
        private readonly QuizTemplateRepository $quizzes,
        private readonly SequenceQuizLinker $linker,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/library/sequences/{sequenceId}/quiz/attach', name: 'app_library_sequences_quiz_attach', requirements: ['sequenceId' => '\d+'], methods: ['POST'])]
    public function attachToSequence(int $sequenceId, Request $request): Response
    {
        $sequence = $this->editableSequence($sequenceId);
        $this->assertCsrf($request, 'library_quiz_attach');

        $quiz = $this->pickedQuiz($request, $sequence);
        if (null !== $quiz) {
            $this->linker->attachToSequence($quiz, $sequence);
            $this->entityManager->flush();
            $this->addFlash('success', 'libraryQuizAttachedFlashMessage');
        }

        return $this->redirectToRoute('app_library_sequences_show', ['id' => $sequence->getId()]);
    }

    /**
     * The card's « Détacher », which speaks for the whole séquence: it removes the séance links too,
     * since those are exactly what the card raised into the row being detached.
     */
    #[Route(path: '/library/sequences/{sequenceId}/quiz/{quizId}/detach', name: 'app_library_sequences_quiz_detach', requirements: ['sequenceId' => '\d+', 'quizId' => '\d+'], methods: ['POST'])]
    public function detachFromSequence(int $sequenceId, int $quizId, Request $request): Response
    {
        $sequence = $this->editableSequence($sequenceId);
        $this->assertCsrf($request, 'library_quiz_detach');

        // Detaching asks nothing of the quiz - a teacher must always be able to clean their own
        // séquence, even of a quiz they may not edit. Attaching is the one that checks it, above.
        $quiz = $this->quizzes->find($quizId) ?? throw $this->createNotFoundException();
        $this->linker->detachFromSequence($quiz, $sequence);
        $this->entityManager->flush();
        $this->addFlash('success', 'libraryQuizDetachedFlashMessage');

        return $this->redirectToRoute('app_library_sequences_show', ['id' => $sequence->getId()]);
    }

    #[Route(path: '/library/sequences/{sequenceId}/sessions/{seanceId}/quiz/attach', name: 'app_library_seances_quiz_attach', requirements: ['sequenceId' => '\d+', 'seanceId' => '\d+'], methods: ['POST'])]
    public function attachToSeance(int $sequenceId, int $seanceId, Request $request): Response
    {
        $sequence = $this->editableSequence($sequenceId);
        $seance = $this->seanceOf($sequence, $seanceId);
        $this->assertCsrf($request, 'library_quiz_attach');

        $quiz = $this->pickedQuiz($request, $sequence);
        if (null !== $quiz) {
            $this->linker->attachToSeance($quiz, $seance);
            $this->entityManager->flush();
            $this->addFlash('success', 'libraryQuizAttachedFlashMessage');
        }

        return $this->redirectToRoute('app_library_seances_show', ['sequenceId' => $sequence->getId(), 'id' => $seance->getId()]);
    }

    /** Only this séance: a quiz the séquence names as well stays named by it. */
    #[Route(path: '/library/sequences/{sequenceId}/sessions/{seanceId}/quiz/{quizId}/detach', name: 'app_library_seances_quiz_detach', requirements: ['sequenceId' => '\d+', 'seanceId' => '\d+', 'quizId' => '\d+'], methods: ['POST'])]
    public function detachFromSeance(int $sequenceId, int $seanceId, int $quizId, Request $request): Response
    {
        $sequence = $this->editableSequence($sequenceId);
        $seance = $this->seanceOf($sequence, $seanceId);
        $this->assertCsrf($request, 'library_quiz_detach');

        $quiz = $this->quizzes->find($quizId) ?? throw $this->createNotFoundException();
        $this->linker->detachFromSeance($quiz, $seance);
        $this->entityManager->flush();
        $this->addFlash('success', 'libraryQuizDetachedFlashMessage');

        return $this->redirectToRoute('app_library_seances_show', ['sequenceId' => $sequence->getId(), 'id' => $seance->getId()]);
    }

    private function editableSequence(int $id): SequenceTemplate
    {
        $sequence = $this->sequences->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequence);

        return $sequence;
    }

    private function seanceOf(SequenceTemplate $sequence, int $id): SeanceTemplate
    {
        $seance = $this->seances->find($id) ?? throw $this->createNotFoundException();

        if ($seance->getSequenceTemplate()->getId() !== $sequence->getId()) {
            throw $this->createNotFoundException();
        }

        return $seance;
    }

    /**
     * The quiz the select named, or null when the teacher submitted the empty placeholder - which is
     * a slip, not an error, and answers with a message rather than a 404.
     *
     * The picker only ever offers the séquence owner's own library, and this is where that holds: a
     * quiz belonging to somebody else could never be launched by the teacher whose séquence carries
     * it, so it does not get attached, however the request was built.
     */
    private function pickedQuiz(Request $request, SequenceTemplate $sequence): ?QuizTemplate
    {
        $quizId = PostValue::int($request, 'quiz');
        if (0 === $quizId) {
            $this->addFlash('danger', 'libraryQuizAttachNoneChosenFlashMessage');

            return null;
        }

        $quiz = $this->quizzes->find($quizId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $quiz);

        if ($quiz->getTeacher() !== $sequence->getTeacher()) {
            throw $this->createNotFoundException();
        }

        return $quiz;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
