<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Security\ProgramInternshipBookletAccess;
use App\Service\InternshipBookletBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Section > Formation > « Livrets d'alternance »: the teachers of a formation in alternance list
 * its alternants and read each one's Livret de l'alternant.
 *
 * Reading is all there is here, on purpose: no wizard, no signature, no relance and no export -
 * those stay on the UFA screens (administration), the tutor's and the alternant's own. The whole
 * « who » is App\Security\ProgramInternshipBookletAccess, which the menu entry reads too.
 */
#[RequiresFeature(Feature::UfaBooklet)]
class TeacherBookletController extends AbstractController
{
    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly InternshipTutorLinkRepository $tutorLinkRepository,
        private readonly ProgramInternshipBookletAccess $bookletAccess,
    ) {
    }

    #[Route(path: '/programs/{id}/internship-booklets', name: 'app_program_internship_booklets', requirements: ['id' => '\d+'])]
    public function index(int $id): Response
    {
        $program = $this->readableProgram($id);

        return $this->render('program/internship_booklets/index.html.twig', [
            'program' => $program,
            'tutorLinks' => $this->tutorLinkRepository->findActiveForProgramByStudentName($program, $this->includesTestAlternances($program)),
        ]);
    }

    // The same reader as every other way into a booklet (templates/internship/_livret_reader.html.twig),
    // minus the export buttons.
    #[Route(path: '/programs/{id}/internship-booklets/{tutorLinkId}', name: 'app_program_internship_booklets_show', requirements: ['id' => '\d+', 'tutorLinkId' => '\d+'])]
    public function show(int $id, int $tutorLinkId): Response
    {
        $program = $this->readableProgram($id);

        return $this->render('program/internship_booklets/show.html.twig', [
            'program' => $program,
            'tutorLink' => $this->listedTutorLink($program, $tutorLinkId),
        ]);
    }

    // Unwrapped document behind the reader's <iframe src="...">.
    #[Route(path: '/programs/{id}/internship-booklets/{tutorLinkId}/frame', name: 'app_program_internship_booklets_frame', requirements: ['id' => '\d+', 'tutorLinkId' => '\d+'])]
    public function frame(int $id, int $tutorLinkId, InternshipBookletBuilder $bookletBuilder): Response
    {
        $program = $this->readableProgram($id);

        return $this->render('internship/booklet.html.twig', $bookletBuilder->build($this->listedTutorLink($program, $tutorLinkId)));
    }

    // 404 when the formation has no such screen, 403 when it has one this person may not read -
    // see ProgramInternshipBookletAccess for why the two are told apart.
    private function readableProgram(int $id): Program
    {
        $program = $this->programRepository->find($id) ?? throw $this->createNotFoundException();

        if (!$this->bookletAccess->isOffered($program)) {
            throw $this->createNotFoundException();
        }

        if (!$this->bookletAccess->isReadable($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }

    // The list is the door: a booklet answers here only if its row is on it - this formation's, an
    // alternance still running, and on the reader's side of the test fence.
    private function listedTutorLink(Program $program, int $tutorLinkId): InternshipTutorLink
    {
        $tutorLink = $this->tutorLinkRepository->find($tutorLinkId) ?? throw $this->createNotFoundException();

        if ($tutorLink->getProgram()?->getId() !== $program->getId()
            || $tutorLink->isTerminated()
            || ($tutorLink->isTestAlternance() && !$this->includesTestAlternances($program))) {
            throw $this->createNotFoundException();
        }

        return $tutorLink;
    }

    // A fake alternance is shown where the fake world is the normal one - a test formation, or a
    // test account - and nowhere else.
    private function includesTestAlternances(Program $program): bool
    {
        $user = $this->getUser();

        return $program->isTestProgram() || ($user instanceof User && $user->isTestUser());
    }
}
