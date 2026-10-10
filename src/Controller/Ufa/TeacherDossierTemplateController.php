<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\TeacherDossier;
use App\Enum\Feature;
use App\Repository\TeacherDossierRepository;
use App\Service\PostValue;
use App\Service\TeacherDossier\TeacherDossierComposer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * UFA › Dossiers intervenants › Modèles (design/validated/dossiers-intervenants.md, screen 6).
 *
 * A model is a dossier with no intervenant, so there is little here: the list, the creation of an
 * empty one, and « Dupliquer ». Its pieces are edited by TeacherDossierCompositionController - the
 * same editor as a dossier's - and it is deleted by TeacherDossierController::delete(), which
 * leaves every dossier made from it as it is.
 */
#[IsGranted(new Expression(self::ADMINISTRATION_EXPRESSION))]
#[RequiresFeature(Feature::TeacherDossiers)]
class TeacherDossierTemplateController extends AbstractController
{
    use TeacherDossierControllerTrait;

    public function __construct(
        private readonly TeacherDossierRepository $dossiers,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/ufa/teacher-dossiers/templates', name: 'app_ufa_teacher_dossier_templates', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('ufa/teacher_dossier/templates.html.twig', [
            'templates' => $this->dossiers->findTemplates(),
            'usage' => $this->dossiers->countByTemplate(),
        ]);
    }

    /** « Nouveau modèle » — a title, then straight onto its pieces. */
    #[Route(path: '/ufa/teacher-dossiers/templates/new', name: 'app_ufa_teacher_dossier_template_new', methods: ['POST'])]
    public function new(Request $request): Response
    {
        $this->assertToken($request);
        $title = mb_substr(PostValue::trimmed($request, 'title'), 0, 255);

        if ('' === $title) {
            $this->addFlash('error', 'teacherDossierNewNoTitleFlashMessage');

            return $this->redirectToRoute('app_ufa_teacher_dossier_templates');
        }

        $template = TeacherDossier::asTemplate($this->currentUser(), $title);
        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_ufa_teacher_dossier_documents', ['id' => $template->getId(), 'piece' => 'new']);
    }

    #[Route(path: '/ufa/teacher-dossiers/templates/{id}/duplicate', name: 'app_ufa_teacher_dossier_template_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(Request $request, int $id, TeacherDossierComposer $composer, TranslatorInterface $translator): Response
    {
        $template = $this->dossierOr404($id);
        $this->assertToken($request);

        if (!$template->isTemplate()) {
            throw $this->createNotFoundException();
        }

        $copy = $composer->toTemplate($template, $this->currentUser(), mb_substr($translator->trans('teacherDossierTemplateCopyTitle', ['%title%' => $template->getTitle()]), 0, 255));
        $this->entityManager->persist($copy);
        $this->entityManager->flush();
        $this->addFlash('success', 'teacherDossierTemplateDuplicatedFlashMessage');

        return $this->redirectToRoute('app_ufa_teacher_dossier_settings', ['id' => $copy->getId()]);
    }
}
