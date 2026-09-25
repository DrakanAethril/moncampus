<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\InternshipProgramInfo;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\UfaTeachingTeamEntryType;
use App\Repository\InternshipProgramInfoRepository;
use App\Repository\ProgramRepository;
use App\Service\FormValue;
use App\Service\TeachingTeam;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * UFA > Formations > une formation > « Équipe » - the lines the Livret de l'alternant prints as
 * its « Équipe pédagogique » (section I.4), and nothing else.
 *
 * A line is a matière and a teacher typed as they should be printed, plus the options it is
 * restricted to. None of it is linked to the formation's matières, its timetable or its accounts:
 * see App\Service\TeachingTeam, which holds every rule, for why.
 *
 * Same screen shape as the « Périodes d'évaluation » tab: a plain table, « Ajouter » on the tab
 * row, create/edit in a cm-panel over the list, delete per row.
 *
 * @phpstan-import-type TeachingTeamEntry from TeachingTeam
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::UfaBooklet)]
class FormationTeachingTeamController extends AbstractController
{
    private const string DELETE_TOKEN = 'ufa_teaching_team_delete';

    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly InternshipProgramInfoRepository $infoRepository,
    ) {
    }

    #[Route(path: '/ufa/programs/{id}/teaching-team', name: 'app_ufa_formation_teaching_team')]
    public function list(int $id): Response
    {
        return $this->renderTab($this->program($id));
    }

    #[Route(path: '/ufa/programs/{id}/teaching-team/new', name: 'app_ufa_formation_teaching_team_new')]
    #[Route(path: '/ufa/programs/{id}/teaching-team/{entryId}/edit', name: 'app_ufa_formation_teaching_team_edit', requirements: ['entryId' => '[0-9a-f]+'])]
    public function form(int $id, Request $request, EntityManagerInterface $entityManager, ?string $entryId = null): Response
    {
        $program = $this->program($id);
        $info = $this->infoRepository->findOneByProgram($program);
        $entries = $info?->getTeachingTeam() ?? [];

        $isEdit = null !== $entryId;
        $entry = $isEdit ? (TeachingTeam::find($entries, $entryId) ?? throw $this->createNotFoundException()) : null;

        $form = $this->createForm(UfaTeachingTeamEntryType::class, [
            'topic' => $entry['topic'] ?? '',
            'teacher' => $entry['teacher'] ?? '',
            'optionIds' => $entry['optionIds'] ?? [],
        ], ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null === $info) {
                $info = new InternshipProgramInfo($program);
                $info->setCreatedBy($this->currentUser());
                $entityManager->persist($info);
            } else {
                $info->setLastUpdatedBy($this->currentUser());
                $info->setLastUpdatedDate(new \DateTimeImmutable());
            }

            $info->setTeachingTeam(TeachingTeam::put($entries, [
                'id' => $entry['id'] ?? TeachingTeam::newId(),
                'topic' => FormValue::trimmed($form, 'topic'),
                'teacher' => FormValue::trimmed($form, 'teacher'),
                'optionIds' => $this->submittedOptionIds($form, $program),
            ]));
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'ufaTeachingTeamUpdatedFlashMessage' : 'ufaTeachingTeamCreatedFlashMessage');

            return $this->redirectToRoute('app_ufa_formation_teaching_team', ['id' => $program->getId()]);
        }

        return $this->renderTab($program, $form, $isEdit);
    }

    #[Route(path: '/ufa/programs/{id}/teaching-team/{entryId}/delete', name: 'app_ufa_formation_teaching_team_delete', requirements: ['entryId' => '[0-9a-f]+'], methods: ['POST'])]
    public function delete(int $id, string $entryId, Request $request, EntityManagerInterface $entityManager): Response
    {
        $program = $this->program($id);
        if (!$this->isCsrfTokenValid(self::DELETE_TOKEN, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $info = $this->infoRepository->findOneByProgram($program);
        // Already gone (deleted from another tab) is the state that was asked for: no 404 for it.
        if (null !== $info && null !== TeachingTeam::find($info->getTeachingTeam(), $entryId)) {
            $info->setTeachingTeam(TeachingTeam::remove($info->getTeachingTeam(), $entryId));
            $info->setLastUpdatedBy($this->currentUser());
            $info->setLastUpdatedDate(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'ufaTeachingTeamDeletedFlashMessage');
        }

        return $this->redirectToRoute('app_ufa_formation_teaching_team', ['id' => $program->getId()]);
    }

    private function renderTab(Program $program, ?FormInterface $panelForm = null, bool $panelIsEdit = false): Response
    {
        $optionNames = [];
        foreach ($program->getOptions() as $option) {
            $optionNames[$option->getId()] = $option->getShortName();
        }

        return $this->render('ufa/formation.html.twig', [
            'program' => $program,
            'activeTab' => 'teaching_team',
            // Listed in the booklet's own order, so the tab reads the way the page will print.
            'teachingTeam' => TeachingTeam::sorted($this->infoRepository->findOneByProgram($program)?->getTeachingTeam() ?? []),
            'optionNames' => $optionNames,
            'deleteToken' => self::DELETE_TOKEN,
            'panelForm' => $panelForm,
            'panelIsEdit' => $panelIsEdit,
        ]);
    }

    /**
     * Only ids of the formation's own options survive - the field offers nothing else, but the
     * payload is the client's.
     *
     * @return list<int>
     */
    private function submittedOptionIds(FormInterface $form, Program $program): array
    {
        if (!$form->has('optionIds')) {
            return [];
        }

        $submitted = $form->get('optionIds')->getData();
        $programOptionIds = array_map(static fn (Option $option): int => $option->getId(), $program->getOptions()->toArray());

        return array_values(array_filter(
            $programOptionIds,
            static fn (int $optionId): bool => \is_array($submitted) && \in_array($optionId, $submitted, true),
        ));
    }

    private function program(int $id): Program
    {
        return $this->programRepository->find($id) ?? throw $this->createNotFoundException();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
