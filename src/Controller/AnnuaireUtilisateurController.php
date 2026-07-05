<?php

namespace App\Controller;

use App\Repository\LdapManageUserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AnnuaireUtilisateurController extends AbstractController
{
    #[Route(path: '/annuaire/utilisateurs', name: 'app_annuaire_utilisateurs')]
    #[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
    public function index(LdapManageUserRepository $repository): Response
    {
        return $this->render('annuaire/utilisateurs.html.twig', [
            'utilisateurs' => $repository->findAllOrderedByMostRecent(),
        ]);
    }
}
