<?php

namespace App\Controller;

use App\Entity\LdapManageGroup;
use App\Entity\User;
use App\Form\LdapManageGroupType;
use App\Repository\LdapManageGroupRepository;
use App\Service\QueueStateFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class AnnuaireGroupeController extends AbstractController
{
    #[Route(path: '/annuaire/groupes', name: 'app_annuaire_groupes')]
    public function index(): Response
    {
        return $this->render('annuaire/groupes.html.twig');
    }

    #[Route(path: '/annuaire/groupes/nouveau', name: 'app_annuaire_groupes_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $group = new LdapManageGroup('', '');
        $form = $this->createForm(LdapManageGroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $currentUser */
            $currentUser = $this->getUser();
            $group->setAddedBy($currentUser->getUsername());

            $entityManager->persist($group);
            $entityManager->flush();

            $this->addFlash('success', 'groupCreatedFlashMessage');

            return $this->redirectToRoute('app_annuaire_groupes');
        }

        return $this->render('annuaire/groupe_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/annuaire/groupes/data', name: 'app_annuaire_groupes_data')]
    public function data(Request $request, LdapManageGroupRepository $repository, QueueStateFormatter $stateFormatter): JsonResponse
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));

        $total = $repository->countAll();
        $filteredTotal = '' !== $search ? $repository->countAll($search) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (LdapManageGroup $group): array => [
                    'name' => $group->getName(),
                    'description' => $group->getDescription(),
                    'statusLabel' => $stateFormatter->label($group->getState()),
                    'statusClass' => $stateFormatter->cssClass($group->getState()),
                    'addedAt' => $group->getAddedAt()->format('d/m/Y H:i'),
                    'addedBy' => $group->getAddedBy(),
                ],
                $rows,
            ),
        ]);
    }
}
