<?php

namespace App\Controller;

use App\Repository\LdapManageGroupRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AnnuaireGroupeController extends AbstractController
{
    #[Route(path: '/annuaire/groupes', name: 'app_annuaire_groupes')]
    #[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
    public function index(LdapManageGroupRepository $repository): Response
    {
        return $this->render('annuaire/groupes.html.twig', [
            'groupes' => $repository->findAllOrderedByMostRecent(),
        ]);
    }
}
