<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\ProgramEcfSettings;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\EcfSettingsType;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\ProgramEcfSettingsRepository;
use App\Repository\ProgramRepository;
use App\Service\Ecf\EcfActivityTypes;
use App\Service\Ecf\EcfBookletLocator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * UFA > Formations > {formation} > « Livret ECF » (design/validated/ecf-booklet.md §4): the switch,
 * the dates the ministry's template prints and the organisme. The titre is read from
 * « Dénomination », the activity-types from « Compétences ». The administration reads it; only an
 * administrator saves it.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::UfaEcf)]
class EcfSettingsController extends AbstractController
{
    #[Route(path: '/ufa/programs/{id}/ecf', name: 'app_ufa_formation_ecf', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function settings(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        ProgramRepository $programRepository,
        ProgramEcfSettingsRepository $settingsRepository,
        InternshipFormationCenterRepository $formationCenterRepository,
        EcfActivityTypes $activityTypes,
        EcfBookletLocator $locator,
        TranslatorInterface $translator,
    ): Response {
        $program = $programRepository->find($id) ?? throw $this->createNotFoundException();
        $settings = $settingsRepository->findOneByProgram($program);
        $isNew = null === $settings;
        $settings ??= $this->proposed($program, $formationCenterRepository);

        $canEdit = $this->isGranted('ROLE_ADMIN');
        $form = $this->createForm(EcfSettingsType::class, $settings, ['disabled' => !$canEdit]);
        $form->handleRequest($request);

        $hasActivityTypes = $activityTypes->hasActivityTypes($program);
        $optionsWithoutTitle = $locator->optionsWithoutTitle($program);
        $hasTitle = \count($optionsWithoutTitle) < max(1, $program->getOptions()->count());

        if ($canEdit && $form->isSubmitted()) {
            if ($settings->isEnabled() && !$hasActivityTypes) {
                $form->get('enabled')->addError(new FormError($translator->trans('ecfSettingsRefusalNoGroupMessage')));
            }
            if ($settings->isEnabled() && !$hasTitle) {
                $form->get('enabled')->addError(new FormError($translator->trans('ecfSettingsRefusalNoTitleMessage')));
            }

            if ($form->isValid()) {
                /** @var User $user */
                $user = $this->getUser();
                if ($isNew) {
                    $settings->setCreatedBy($user);
                    $entityManager->persist($settings);
                } else {
                    $settings->setLastUpdatedBy($user);
                    $settings->setLastUpdatedDate(new \DateTimeImmutable());
                }
                $entityManager->flush();
                $this->addFlash('success', 'ecfSettingsSavedFlashMessage');

                return $this->redirectToRoute('app_ufa_formation_ecf', ['id' => $program->getId()]);
            }
        }

        return $this->render('ufa/formation.html.twig', [
            'program' => $program,
            'activeTab' => 'ecf',
            'form' => $form,
            'settings' => $settings,
            'canEdit' => $canEdit,
            'hasActivityTypes' => $hasActivityTypes,
            'optionsWithoutTitle' => $optionsWithoutTitle,
        ]);
    }

    /** A first visit proposes the training centre as organisme and lieu, and saves nothing. */
    private function proposed(Program $program, InternshipFormationCenterRepository $formationCenterRepository): ProgramEcfSettings
    {
        $settings = new ProgramEcfSettings($program);
        $center = $formationCenterRepository->findSingleton();
        if (null !== $center) {
            $settings->setOrganisation($center->getCfaName() ?? $center->getCompanyName());
            $place = trim(($center->getCompanyName() ?? '').' '.($center->getCity() ?? ''));
            $settings->setPlace('' !== $place ? $place : null);
        }

        return $settings;
    }
}
