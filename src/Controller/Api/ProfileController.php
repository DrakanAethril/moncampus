<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\LdapManagePassword;
use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Form\ContactEmailType;
use App\Repository\LdapManagePasswordRepository;
use App\Service\ContactEmailVerifier;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mobile counterpart to ProfileController's contact-email and change-password actions (see that
 * class for the full docblocks on the underlying rules - this just re-exposes the same
 * ContactEmailVerifier/LdapManagePasswordRepository logic as JSON, no CSRF
 * (stateless JWT firewall, same reasoning as Api\SignupListController). Avatar upload,
 * messaging-preferences and theme aren't exposed here - out of scope for the mobile profile screen
 * (design/design_campus_manager/README.md's "App mobile MonCampus" section: "pas de préférences
 * de messagerie sur mobile").
 */
class ProfileController extends AbstractController
{
    // Mirrors ProfileController::updateContactEmail() - only acts (and only touches
    // verification state) when the value actually changed, same "resubmitting the same value must
    // not reset the pending token" rule.
    #[Route(path: '/api/profile/contact-email', name: 'api_profile_contact_email', methods: ['POST'])]
    public function updateContactEmail(Request $request, EntityManagerInterface $entityManager, ContactEmailVerifier $contactEmailVerifier): JsonResponse
    {
        $user = $this->currentUser();
        $previousEmail = $user->getContactEmail();

        $payload = JsonRequestPayload::fromRequest($request);

        // csrf_protection: false - the api firewall is stateless/JWT-bearer, there's no session
        // to hold a CSRF token against (same reasoning as Api\SignupListController's routes never
        // checking one).
        $form = $this->createForm(ContactEmailType::class, $user, ['csrf_protection' => false]);
        $form->submit(['contactEmail' => $payload->string('contactEmail')], false);

        if (!$form->isValid()) {
            return $this->json(['error' => 'invalid_email', 'errors' => $this->formErrors($form)], 422);
        }

        $newEmail = $user->getContactEmail();

        if ($newEmail !== $previousEmail) {
            if (null === $newEmail) {
                $user->setContactEmailVerifiedAt(null)->setContactEmailToken(null)->setContactEmailTokenRequestedAt(null);
            } else {
                $contactEmailVerifier->requestVerification($user);
            }

            $entityManager->flush();
        }

        return $this->json($this->formatContactEmail($user));
    }

    // Mirrors ProfileController::resendContactEmailConfirmation().
    #[Route(path: '/api/profile/contact-email/resend', name: 'api_profile_contact_email_resend', methods: ['POST'])]
    public function resendContactEmailConfirmation(EntityManagerInterface $entityManager, ContactEmailVerifier $contactEmailVerifier): JsonResponse
    {
        $user = $this->currentUser();

        if (null === $user->getContactEmail() || $user->isContactEmailVerified()) {
            return $this->json(['error' => 'no_pending_email'], 409);
        }

        if (!$contactEmailVerifier->canResend($user)) {
            return $this->json(['error' => 'resend_too_soon'], 429);
        }

        $contactEmailVerifier->requestVerification($user);
        $entityManager->flush();

        return $this->json($this->formatContactEmail($user));
    }

    // Mirrors ProfileController::changePassword() - trusts the active session/JWT alone, no
    // current-password re-verification, before queuing the new password.
    #[Route(path: '/api/profile/change-password', name: 'api_profile_change_password', methods: ['POST'])]
    public function changePassword(Request $request, EntityManagerInterface $entityManager, LdapManagePasswordRepository $passwordRequestRepository): JsonResponse
    {
        $user = $this->currentUser();
        $payload = JsonRequestPayload::fromRequest($request);

        $form = $this->createForm(ChangePasswordType::class, options: ['csrf_protection' => false]);
        $form->submit([
            'newPassword' => [
                'first' => $payload->string('newPassword'),
                'second' => $payload->string('newPasswordConfirmation'),
            ],
        ], false);

        if (!$form->isValid()) {
            return $this->json(['error' => 'invalid_password', 'errors' => $this->formErrors($form)], 422);
        }

        $newPassword = $form->get('newPassword')->getData();

        if (str_contains(mb_strtolower($newPassword), mb_strtolower($user->getUsername()))) {
            return $this->json(['error' => 'new_password_contains_username'], 422);
        }

        $ldapManagePassword = new LdapManagePassword($user);
        $ldapManagePassword->setAddedBy($user->getUsername());

        $entityManager->persist($ldapManagePassword);
        $entityManager->flush();

        $passwordRequestRepository->setRequestedPassword($ldapManagePassword, $newPassword);

        return $this->json(['success' => true]);
    }

    /** @return array{contactEmail: string|null, contactEmailVerified: bool} */
    private function formatContactEmail(User $user): array
    {
        return [
            'contactEmail' => $user->getContactEmail(),
            'contactEmailVerified' => $user->isContactEmailVerified(),
        ];
    }

    /** @return list<string> */
    private function formErrors(FormInterface $form): array
    {
        $messages = [];
        foreach ($form->getErrors(true) as $error) {
            $messages[] = $error->getMessage();
        }

        return $messages;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
