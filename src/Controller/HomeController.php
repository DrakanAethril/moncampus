<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Placeholder landing page proving the LDAP-backed login works end to end.
 * Replace with the real dashboard once the screens are designed.
 */
class HomeController extends AbstractController
{
    #[Route(path: '/', name: 'app_home')]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('home/index.html.twig', [
            'user' => $user,
        ]);
    }
}
