<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\EcfMinistryLogoType;
use App\Repository\InternshipFormationCenterRepository;
use App\Service\FileUploadService;
use App\Service\StagedUpload;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The ministry's bloc-marque printed on the ECF booklet's cover (design/validated/ecf-booklet.md):
 * uploaded once by an administrator in UFA > Configuration > Centre de formation, never shipped in
 * the repository - the image is the ministry's, not ours. Rendered into that tab as a fragment
 * (`card()`, no route).
 */
#[IsGranted('ROLE_ADMIN')]
#[RequiresFeature(Feature::UfaEcf)]
class EcfMinistryLogoController extends AbstractController
{
    private const string PREFIX = 'ufa/ecf/';
    private const string REMOVE_TOKEN = 'ufa_ecf_logo_remove';

    public function card(InternshipFormationCenterRepository $repository, FileUploadService $fileUploadService): Response
    {
        $key = $repository->findSingleton()?->getEcfMinistryLogoKey();

        return $this->render('ufa/ecf/_ministry_logo_card.html.twig', [
            'form' => $this->createForm(EcfMinistryLogoType::class, null, ['action' => $this->generateUrl('app_ufa_ecf_logo_upload')]),
            'logoUrl' => null !== $key ? $fileUploadService->url($key) : null,
        ]);
    }

    #[Route(path: '/ufa/configuration/ecf-logo', name: 'app_ufa_ecf_logo_upload', methods: ['POST'])]
    public function upload(Request $request, InternshipFormationCenterRepository $repository, EntityManagerInterface $entityManager, UploadIntake $uploadIntake, FileUploadService $fileUploadService): Response
    {
        $form = $this->createForm(EcfMinistryLogoType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var StagedUpload $file */
            $file = $form->get('logo')->getData();
            $center = $repository->getOrCreate();
            if (null === $center->getCreatedBy()) {
                /** @var User $user */
                $user = $this->getUser();
                $center->setCreatedBy($user);
            }
            $oldKey = $center->getEcfMinistryLogoKey();
            // A new name at each change: no cached copy of the previous image survives it.
            $center->setEcfMinistryLogoKey($uploadIntake->store($file, self::PREFIX, \sprintf('bloc-marque-%d.%s', time(), UploadIntake::extension($file))));
            $entityManager->flush();
            if (null !== $oldKey) {
                $fileUploadService->delete($oldKey);
            }
            $this->addFlash('success', 'ecfMinistryLogoSavedFlashMessage');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_ufa_configuration_formation_center');
    }

    #[Route(path: '/ufa/configuration/ecf-logo/remove', name: 'app_ufa_ecf_logo_remove', methods: ['POST'])]
    public function remove(Request $request, InternshipFormationCenterRepository $repository, EntityManagerInterface $entityManager, FileUploadService $fileUploadService): Response
    {
        if (!$this->isCsrfTokenValid(self::REMOVE_TOKEN, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $center = $repository->findSingleton();
        $key = $center?->getEcfMinistryLogoKey();
        if (null !== $center && null !== $key) {
            $center->setEcfMinistryLogoKey(null);
            $entityManager->flush();
            $fileUploadService->delete($key);
            $this->addFlash('success', 'ecfMinistryLogoRemovedFlashMessage');
        }

        return $this->redirectToRoute('app_ufa_configuration_formation_center');
    }
}
