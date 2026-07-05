<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class LocaleController extends AbstractController
{
    #[Route(path: '/locale/{locale}', name: 'app_locale_switch', requirements: ['locale' => 'fr|en'])]
    public function switch(string $locale, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $request->getSession()->set('_locale', $locale);

        $user = $this->getUser();
        if ($user instanceof User) {
            $user->setLocale($locale);
            $entityManager->flush();
        }

        $redirect = $request->query->get('redirect', '');
        if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            $redirect = $this->generateUrl('app_home');
        }

        return $this->redirect($redirect);
    }
}
