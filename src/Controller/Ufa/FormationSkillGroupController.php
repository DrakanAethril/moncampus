<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Controller\SkillGroupCrudTrait;
use App\Entity\Program;
use App\Enum\Feature;
use App\Repository\ProgramRepository;
use App\Repository\SkillGroupRepository;
use App\Repository\SkillRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * UFA > Formations > une formation > « Compétences » - the same screens as Formation >
 * Paramétrage > « Groupes de compétences », reached from the UFA menu.
 *
 * The name differs on purpose: the UFA team says « les compétences » and the tab is theirs. What is
 * behind it is the same rows, the same forms and the same code - App\Controller\SkillGroupCrudTrait
 * holds every body, and this class declares only the paths, the shell and the route names its
 * templates point at.
 *
 * **It follows `ufa_booklet`, not `tsf_referential`**, and that is the reason the second door
 * exists: the UFA team is delivered the booklet and not the référentiel, so the settings screens
 * answer 404 to them. Two doors, two features, one implementation.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::UfaBooklet)]
class FormationSkillGroupController extends AbstractController
{
    use SkillGroupCrudTrait;

    public function __construct(private readonly ProgramRepository $programRepository)
    {
    }

    #[Route(path: '/ufa/programs/{id}/skills', name: 'app_ufa_formation_skills')]
    public function skillGroupsTab(int $id, SkillGroupRepository $skillGroupRepository): Response
    {
        return $this->doSkillGroupsList($id, $skillGroupRepository);
    }

    #[Route(path: '/ufa/programs/{id}/skills/reorder', name: 'app_ufa_formation_skills_reorder', methods: ['POST'])]
    public function reorderSkillGroups(int $id, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository): JsonResponse
    {
        return $this->doReorderSkillGroups($id, $request, $entityManager, $skillGroupRepository);
    }

    #[Route(path: '/ufa/programs/{id}/skills/new', name: 'app_ufa_formation_skills_new')]
    #[Route(path: '/ufa/programs/{id}/skills/{groupId}/edit', name: 'app_ufa_formation_skills_edit')]
    public function skillGroupForm(int $id, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, ?int $groupId = null): Response
    {
        return $this->doSkillGroupForm($id, $request, $entityManager, $skillGroupRepository, $groupId);
    }

    #[Route(path: '/ufa/programs/{id}/skills/teachers-search', name: 'app_ufa_formation_skills_teachers_search')]
    public function skillGroupTeachersSearch(int $id, Request $request): JsonResponse
    {
        return $this->doSkillGroupTeachersSearch($id, $request);
    }

    #[Route(path: '/ufa/programs/{id}/skills/{groupId}/deactivate', name: 'app_ufa_formation_skills_deactivate', methods: ['POST'])]
    public function deactivateSkillGroup(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository): JsonResponse
    {
        return $this->doDeactivateSkillGroup($id, $groupId, $request, $entityManager, $skillGroupRepository);
    }

    #[Route(path: '/ufa/programs/{id}/skills/{groupId}/items', name: 'app_ufa_formation_skills_items')]
    public function skillsList(int $id, int $groupId, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): Response
    {
        return $this->doSkillsList($id, $groupId, $skillGroupRepository, $skillRepository);
    }

    #[Route(path: '/ufa/programs/{id}/skills/{groupId}/items/reorder', name: 'app_ufa_formation_skills_items_reorder', methods: ['POST'])]
    public function reorderSkills(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): JsonResponse
    {
        return $this->doReorderSkills($id, $groupId, $request, $entityManager, $skillGroupRepository, $skillRepository);
    }

    #[Route(path: '/ufa/programs/{id}/skills/{groupId}/items/new', name: 'app_ufa_formation_skills_items_new')]
    #[Route(path: '/ufa/programs/{id}/skills/{groupId}/items/{skillId}/edit', name: 'app_ufa_formation_skills_items_edit')]
    public function skillForm(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer, ?int $skillId = null): Response
    {
        return $this->doSkillForm($id, $groupId, $request, $entityManager, $skillGroupRepository, $skillRepository, $sanitizer, $skillId);
    }

    #[Route(path: '/ufa/programs/{id}/skills/{groupId}/items/{skillId}/deactivate', name: 'app_ufa_formation_skills_items_deactivate', methods: ['POST'])]
    public function deactivateSkill(int $id, int $groupId, int $skillId, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): JsonResponse
    {
        return $this->doDeactivateSkill($id, $groupId, $skillId, $request, $entityManager, $skillGroupRepository, $skillRepository);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function skillRoutes(): array
    {
        return [
            'list' => 'app_ufa_formation_skills',
            'reorder' => 'app_ufa_formation_skills_reorder',
            'new' => 'app_ufa_formation_skills_new',
            'edit' => 'app_ufa_formation_skills_edit',
            'teachersSearch' => 'app_ufa_formation_skills_teachers_search',
            'deactivate' => 'app_ufa_formation_skills_deactivate',
            'skills' => 'app_ufa_formation_skills_items',
            'skillsReorder' => 'app_ufa_formation_skills_items_reorder',
            'skillNew' => 'app_ufa_formation_skills_items_new',
            'skillEdit' => 'app_ufa_formation_skills_items_edit',
            'skillDeactivate' => 'app_ufa_formation_skills_items_deactivate',
        ];
    }

    #[\Override]
    protected function renderSkillGroupList(Program $program, array $skillGroups): Response
    {
        return $this->render('ufa/formation.html.twig', [
            'program' => $program,
            'activeTab' => 'skills',
            'skillGroups' => $skillGroups,
            'skillRoutes' => $this->skillRoutes(),
        ]);
    }

    #[\Override]
    protected function skillProgram(int $id): Program
    {
        return $this->programRepository->find($id) ?? throw $this->createNotFoundException();
    }

    #[\Override]
    protected function skillBreadcrumb(Program $program): array
    {
        return [
            ['labelKey' => 'ufaNavLabel', 'label' => null, 'url' => $this->generateUrl('app_ufa')],
            ['labelKey' => null, 'label' => $program->getDisplayShortName(), 'url' => $this->generateUrl('app_ufa_formation_skills', ['id' => $program->getId()])],
        ];
    }
}
