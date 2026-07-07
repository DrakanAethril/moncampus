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

        // ROLE_EXTERNAL (entreprise tutors) have no use for the staff/student navigation this
        // page's layout is built around - route them straight to their own landing instead.
        if (\in_array('ROLE_EXTERNAL', $user->getRoles(), true)) {
            return $this->redirectToRoute('app_internship_tutor_home');
        }

        return $this->render('home/index.html.twig', [
            'user' => $user,
        ]);
    }
}
