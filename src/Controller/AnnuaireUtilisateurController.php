<?php

namespace App\Controller;

use App\Entity\LdapManageUser;
use App\Entity\User;
use App\Form\LdapManageUserType;
use App\Repository\LdapManageUserRepository;
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
class AnnuaireUtilisateurController extends AbstractController
{
    #[Route(path: '/annuaire/utilisateurs', name: 'app_annuaire_utilisateurs')]
    public function index(): Response
    {
        return $this->render('annuaire/utilisateurs.html.twig');
    }

    #[Route(path: '/annuaire/utilisateurs/nouveau', name: 'app_annuaire_utilisateurs_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $ldapUser = new LdapManageUser('', '', '', '');
        $form = $this->createForm(LdapManageUserType::class, $ldapUser);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $currentUser */
            $currentUser = $this->getUser();
            $ldapUser->setAddedBy($currentUser->getUsername());

            $entityManager->persist($ldapUser);
            $entityManager->flush();

            $this->addFlash('success', 'userCreatedFlashMessage');

            return $this->redirectToRoute('app_annuaire_utilisateurs');
        }

        return $this->render('annuaire/utilisateur_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/annuaire/utilisateurs/data', name: 'app_annuaire_utilisateurs_data')]
    public function data(Request $request, LdapManageUserRepository $repository, QueueStateFormatter $stateFormatter): JsonResponse
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;

        $total = $repository->countAll();
        $rows = $repository->findPageOrderedByMostRecent($start, $length);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => array_map(
                fn (LdapManageUser $user): array => [
                    'fullName' => trim($user->getFirstname().' '.$user->getLastname()),
                    'userType' => $user->getUserType(),
                    'groups' => array_values(array_filter(explode('|', $user->getUserGroups()))),
                    'actionType' => $user->getActionType(),
                    'login' => $user->getLogin(),
                    'statusLabel' => $stateFormatter->label($user->getState()),
                    'statusClass' => $stateFormatter->cssClass($user->getState()),
                    'addedAt' => $user->getAddedAt()->format('d/m/Y H:i'),
                ],
                $rows,
            ),
        ]);
    }
}
