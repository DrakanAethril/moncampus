<?php

namespace App\Controller;

use App\Entity\LdapManagePassword;
use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Repository\LdapManagePasswordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Screens 17/22 of design/design_handoff_pages_arrivee - the forced password renewal screen an
 * authenticated but "restricted" session (App\Entity\User::$mustChangePassword) is bounced to,
 * via App\EventSubscriber\ForcePasswordRenewalSubscriber. Reuses ChangePasswordType and the same
 * LdapManagePassword queuing this app already relies on for self-service changes
 * (App\Controller\ProfileController::changePassword()) - same async, trust-the-session-immediately
 * model, just clearing $mustChangePassword instead of only flashing a message.
 */
#[IsGranted('ROLE_USER')]
class PasswordRenewalController extends AbstractController
{
    #[Route(path: '/password/renewal', name: 'app_password_renewal')]
    public function renewal(Request $request, EntityManagerInterface $entityManager, LdapManagePasswordRepository $passwordRequestRepository): Response
    {
        $user = $this->currentUser();

        // Nothing to do here once the restriction has already been lifted - most likely reached by
        // navigating back after a successful submit.
        if (!$user->isMustChangePassword()) {
            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('newPassword')->getData();

            if (str_contains(mb_strtolower($newPassword), mb_strtolower($user->getUsername()))) {
                $this->addFlash('error', 'newPasswordContainsUsernameFlashMessage');
            } else {
                $ldapManagePassword = new LdapManagePassword($user);
                $ldapManagePassword->setAddedBy($user->getUsername());

                $entityManager->persist($ldapManagePassword);
                $entityManager->flush();

                $passwordRequestRepository->setRequestedPassword($ldapManagePassword, $newPassword);

                $user->setMustChangePassword(false);
                $entityManager->flush();

                return $this->redirectToRoute('app_password_renewal_confirmation');
            }
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->render('security/password_renewal.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/password/renewal/confirmation', name: 'app_password_renewal_confirmation')]
    public function confirmation(): Response
    {
        return $this->render('security/password_renewal_confirmed.html.twig');
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
