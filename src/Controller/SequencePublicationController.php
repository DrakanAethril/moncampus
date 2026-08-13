<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LibraryResourceInstance;
use App\Entity\SeanceInstance;
use App\Entity\SequenceInstance;
use App\Enum\ContentVisibility;
use App\Repository\SequenceInstanceRepository;
use App\Security\Voter\SequenceInstanceVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Cette séquence est-elle ouverte aux étudiants ?" - the three controls that make the course space
 * work at all.
 *
 * Everything behind them shipped with the course space: App\Enum\ContentVisibility, the
 * `student_visibility` column of App\Entity\StudentVisibilityTrait, the `student_visible` flag of a
 * resource, App\Service\CourseSpaceBoard's filtering and App\Security\Voter\SequenceInstanceVoter.
 * What never shipped was the way to *write* any of it. Since Hidden is the stored default, every
 * instantiated sequence stayed hidden for ever and « Mes cours » was an empty page for every
 * student - the feature was not half-finished, it was inert.
 *
 * Three levels, because that is how a teacher opens a course: the sequence as a whole, then a
 * séance at a time as the weeks go by, then the odd resource held back (a corrigé). A séance or a
 * resource is never *more* open than what contains it - App\Service\CourseSpaceBoard already reads
 * them that way, so this screen only has to say so.
 *
 * Writes only: the reading is App\Controller\ProgramSequenceInstanceController::show(), which is
 * where the form lives.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SequencePublicationController extends AbstractController
{
    #[Route(path: '/programs/{id}/sequences/{sequenceInstanceId}/visibility', name: 'app_program_sequence_visibility', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceInstanceId' => '\d+'])]
    public function sequenceVisibility(
        int $id,
        int $sequenceInstanceId,
        Request $request,
        SequenceInstanceRepository $repository,
        EntityManagerInterface $entityManager,
    ): Response {
        $sequence = $this->findOrDeny($id, $sequenceInstanceId, $repository, $request, 'sequence_publication');

        $sequence->setStudentVisibility(...$this->submittedVisibility($request));
        $entityManager->flush();
        $this->addFlash('success', 'sequencePublicationUpdatedFlashMessage');

        return $this->back($id, $sequenceInstanceId);
    }

    #[Route(path: '/programs/{id}/sequences/{sequenceInstanceId}/seances/{seanceInstanceId}/visibility', name: 'app_program_seance_visibility', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceInstanceId' => '\d+', 'seanceInstanceId' => '\d+'])]
    public function seanceVisibility(
        int $id,
        int $sequenceInstanceId,
        int $seanceInstanceId,
        Request $request,
        SequenceInstanceRepository $repository,
        EntityManagerInterface $entityManager,
    ): Response {
        $sequence = $this->findOrDeny($id, $sequenceInstanceId, $repository, $request, 'sequence_publication');
        $seance = $this->seanceOf($sequence, $seanceInstanceId);

        $seance->setStudentVisibility(...$this->submittedVisibility($request));
        $entityManager->flush();
        $this->addFlash('success', 'sequencePublicationUpdatedFlashMessage');

        return $this->back($id, $sequenceInstanceId);
    }

    /**
     * A resource is a plain yes/no - it carries no date of its own.
     *
     * Scheduling one would mean a second calendar to keep in step with its séance's, for a case
     * nobody has: a corrigé is held back until the teacher decides, not until a date they picked in
     * advance.
     */
    #[Route(path: '/programs/{id}/sequences/{sequenceInstanceId}/resources/{resourceId}/visibility', name: 'app_program_resource_visibility', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceInstanceId' => '\d+', 'resourceId' => '\d+'])]
    public function resourceVisibility(
        int $id,
        int $sequenceInstanceId,
        int $resourceId,
        Request $request,
        SequenceInstanceRepository $repository,
        EntityManagerInterface $entityManager,
    ): Response {
        $sequence = $this->findOrDeny($id, $sequenceInstanceId, $repository, $request, 'sequence_publication');

        $this->resourceOf($sequence, $resourceId)->setStudentVisible($request->request->getBoolean('visible'));
        $entityManager->flush();
        $this->addFlash('success', 'sequencePublicationUpdatedFlashMessage');

        return $this->back($id, $sequenceInstanceId);
    }

    /**
     * The sequence, once its program, its CSRF token and the publisher's claim on it all hold.
     *
     * The program in the URL is re-checked against the sequence's own rather than trusted, the same
     * reflex ProgramSequenceInstanceController applies on its two routes.
     */
    private function findOrDeny(
        int $programId,
        int $sequenceInstanceId,
        SequenceInstanceRepository $repository,
        Request $request,
        string $tokenId,
    ): SequenceInstance {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $sequence = $repository->find($sequenceInstanceId) ?? throw $this->createNotFoundException();

        if ($sequence->getProgram()?->getId() !== $programId) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SequenceInstanceVoter::PUBLISH, $sequence);

        return $sequence;
    }

    /**
     * What the selector posted.
     *
     * A Scheduled choice whose date will not parse falls back to no date at all, which
     * ContentVisibility reads as *not visible* - the one mistake this feature must not make is
     * publishing something by accident, so an unusable date holds the content back rather than
     * releasing it.
     *
     * @return array{0: ContentVisibility, 1: ?\DateTimeImmutable}
     */
    private function submittedVisibility(Request $request): array
    {
        $visibility = ContentVisibility::tryFrom((string) $request->request->get('visibility')) ?? ContentVisibility::Hidden;
        if (!$visibility->needsDate()) {
            return [$visibility, null];
        }

        $raw = trim((string) $request->request->get('publishedAt'));
        if ('' === $raw) {
            return [$visibility, null];
        }

        try {
            return [$visibility, new \DateTimeImmutable($raw)];
        } catch (\Exception) {
            return [$visibility, null];
        }
    }

    private function seanceOf(SequenceInstance $sequence, int $seanceInstanceId): SeanceInstance
    {
        foreach ($sequence->getSeanceInstances() as $seance) {
            if ($seance->getId() === $seanceInstanceId) {
                return $seance;
            }
        }

        throw $this->createNotFoundException();
    }

    private function resourceOf(SequenceInstance $sequence, int $resourceId): LibraryResourceInstance
    {
        foreach ($sequence->getLibraryResourceInstances() as $resource) {
            if ($resource->getId() === $resourceId) {
                return $resource;
            }
        }

        foreach ($sequence->getSeanceInstances() as $seance) {
            foreach ($seance->getLibraryResourceInstances() as $resource) {
                if ($resource->getId() === $resourceId) {
                    return $resource;
                }
            }
        }

        throw $this->createNotFoundException();
    }

    private function back(int $programId, int $sequenceInstanceId): Response
    {
        return $this->redirectToRoute('app_program_sequences_show', [
            'id' => $programId,
            'sequenceInstanceId' => $sequenceInstanceId,
        ]);
    }
}
