<?php

declare(strict_types=1);

namespace App\Controller\Program;

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
 * Formation > Paramétrage, onglet « Groupes de compétences » et les compétences que chaque groupe
 * contient (SkillGroup::$skills). Toujours propres à la formation : il n'existe pas de variante
 * partagée, contrairement aux niveaux.
 *
 * The bodies moved to App\Controller\SkillGroupCrudTrait when UFA > Formations gained the same
 * screens under the name « Compétences »; the routes, their names and what they do are unchanged.
 * This class now declares three things and nothing else: the paths, the shell the list renders in,
 * and the route names its templates point at.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::TsfReferential)]
class SettingsSkillGroupController extends AbstractController
{
    use ProgramSettingsTabTrait;
    use SkillGroupCrudTrait;

    public function __construct(private readonly ProgramRepository $programRepository)
    {
    }

    #[Route(path: '/programs/{id}/settings/skill-groups', name: 'app_program_settings_skill_groups')]
    public function skillGroupsTab(int $id, SkillGroupRepository $skillGroupRepository): Response
    {
        return $this->doSkillGroupsList($id, $skillGroupRepository);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/reorder', name: 'app_program_settings_skill_groups_reorder', methods: ['POST'])]
    public function reorderSkillGroups(int $id, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository): JsonResponse
    {
        return $this->doReorderSkillGroups($id, $request, $entityManager, $skillGroupRepository);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/new', name: 'app_program_settings_skill_groups_new')]
    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/edit', name: 'app_program_settings_skill_groups_edit')]
    public function skillGroupForm(int $id, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, ?int $groupId = null): Response
    {
        return $this->doSkillGroupForm($id, $request, $entityManager, $skillGroupRepository, $groupId);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/teachers-search', name: 'app_program_settings_skill_groups_teachers_search')]
    public function skillGroupTeachersSearch(int $id, Request $request): JsonResponse
    {
        return $this->doSkillGroupTeachersSearch($id, $request);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/deactivate', name: 'app_program_settings_skill_groups_deactivate', methods: ['POST'])]
    public function deactivateSkillGroup(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository): JsonResponse
    {
        return $this->doDeactivateSkillGroup($id, $groupId, $request, $entityManager, $skillGroupRepository);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills', name: 'app_program_settings_skill_groups_skills')]
    public function skillsList(int $id, int $groupId, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): Response
    {
        return $this->doSkillsList($id, $groupId, $skillGroupRepository, $skillRepository);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/reorder', name: 'app_program_settings_skill_groups_skills_reorder', methods: ['POST'])]
    public function reorderSkills(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): JsonResponse
    {
        return $this->doReorderSkills($id, $groupId, $request, $entityManager, $skillGroupRepository, $skillRepository);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/new', name: 'app_program_settings_skill_groups_skills_new')]
    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/{skillId}/edit', name: 'app_program_settings_skill_groups_skills_edit')]
    public function skillForm(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer, ?int $skillId = null): Response
    {
        return $this->doSkillForm($id, $groupId, $request, $entityManager, $skillGroupRepository, $skillRepository, $sanitizer, $skillId);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/{skillId}/deactivate', name: 'app_program_settings_skill_groups_skills_deactivate', methods: ['POST'])]
    public function deactivateSkill(int $id, int $groupId, int $skillId, Request $request, EntityManagerInterface $entityManager, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): JsonResponse
    {
        return $this->doDeactivateSkill($id, $groupId, $skillId, $request, $entityManager, $skillGroupRepository, $skillRepository);
    }

    /**
     * The canonical set - the UFA door mirrors these keys with its own route names.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function skillRoutes(): array
    {
        return [
            'list' => 'app_program_settings_skill_groups',
            'reorder' => 'app_program_settings_skill_groups_reorder',
            'new' => 'app_program_settings_skill_groups_new',
            'edit' => 'app_program_settings_skill_groups_edit',
            'teachersSearch' => 'app_program_settings_skill_groups_teachers_search',
            'deactivate' => 'app_program_settings_skill_groups_deactivate',
            'skills' => 'app_program_settings_skill_groups_skills',
            'skillsReorder' => 'app_program_settings_skill_groups_skills_reorder',
            'skillNew' => 'app_program_settings_skill_groups_skills_new',
            'skillEdit' => 'app_program_settings_skill_groups_skills_edit',
            'skillDeactivate' => 'app_program_settings_skill_groups_skills_deactivate',
        ];
    }

    #[\Override]
    protected function renderSkillGroupList(Program $program, array $skillGroups): Response
    {
        return $this->render('program/settings.html.twig', [
            'program' => $program,
            'activeTab' => 'skill_groups',
            'skillGroups' => $skillGroups,
            'skillRoutes' => $this->skillRoutes(),
        ]);
    }

    #[\Override]
    protected function skillProgram(int $id): Program
    {
        return $this->findOrNotFound($id, $this->programRepository);
    }

    /**
     * The two segments between « Accueil » and the sub-screen's own title. `labelKey` is translated
     * by the template, `label` is printed as it stands - a formation's short name is data, not a
     * translation key.
     */
    #[\Override]
    protected function skillBreadcrumb(Program $program): array
    {
        return [
            ['labelKey' => 'programSettingsNavLabel', 'label' => null, 'url' => $this->generateUrl('app_program_settings', ['id' => $program->getId()])],
            ['labelKey' => null, 'label' => $program->getDisplayShortName(), 'url' => $this->generateUrl('app_program_settings_skill_groups', ['id' => $program->getId()])],
        ];
    }
}
