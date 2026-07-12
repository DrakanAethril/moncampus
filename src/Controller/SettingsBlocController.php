<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\BlocType;
use App\Repository\BlocRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Staff CRUD for the Bloc reference list (competency-block codes SequenceTemplate is tagged
// with) - a small, plain flat list, not the full DataTables pagination pattern used for larger
// reference lists like Room, since Blocs are expected to number in the dozens at most.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SettingsBlocController extends AbstractController
{
    #[Route(path: '/settings/blocs', name: 'app_settings_blocs')]
    public function list(BlocRepository $repository): Response
    {
        return $this->render('settings/blocs.html.twig', [
            'blocs' => $repository->findAllOrderedByCode(),
        ]);
    }

    #[Route(path: '/settings/blocs/new', name: 'app_settings_blocs_new')]
    #[Route(path: '/settings/blocs/{id}/edit', name: 'app_settings_blocs_edit')]
    public function form(Request $request, EntityManagerInterface $entityManager, BlocRepository $repository, ?int $id = null): Response
    {
        $bloc = null !== $id ? $repository->find($id) ?? throw $this->createNotFoundException() : null;
        $isEdit = null !== $bloc;

        $form = $this->createForm(BlocType::class, $bloc);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'blocUpdatedFlashMessage' : 'blocCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_blocs');
        }

        return $this->render('settings/bloc_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/blocs/{id}/deactivate', name: 'app_settings_blocs_deactivate', methods: ['POST'])]
    public function deactivate(int $id, Request $request, EntityManagerInterface $entityManager, BlocRepository $repository): Response
    {
        $bloc = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('settings_blocs_deactivate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $bloc->setInactiveDate(new \DateTimeImmutable());
        $bloc->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        $this->addFlash('success', 'blocDeactivatedFlashMessage');

        return $this->redirectToRoute('app_settings_blocs');
    }

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
