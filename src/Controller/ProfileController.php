<?php

namespace App\Controller;

use App\Entity\LdapManagePassword;
use App\Entity\User;
use App\Form\AvatarUploadType;
use App\Form\ChangePasswordType;
use App\Form\ContactEmailType;
use App\Form\MessagingPreferencesType;
use App\Repository\LdapManagePasswordRepository;
use App\Security\LdapCredentialsVerifier;
use App\Service\ContactEmailVerifier;
use App\Service\FileUploadService;
use App\Service\QueueStateFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    private const string AVATAR_PREFIX = 'avatars/';

    #[Route(path: '/profile', name: 'app_profile')]
    public function index(LdapManagePasswordRepository $passwordRequestRepository, QueueStateFormatter $stateFormatter): Response
    {
        $user = $this->currentUser();
        $passwordRequest = $passwordRequestRepository->findMostRecentForUser($user);

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'avatarForm' => $this->createForm(AvatarUploadType::class),
            'contactEmailForm' => $this->createForm(ContactEmailType::class, $user),
            'messagingPreferencesForm' => $this->createForm(MessagingPreferencesType::class, $user),
            'changePasswordForm' => $this->createForm(ChangePasswordType::class),
            'passwordRequest' => $passwordRequest,
            'passwordRequestStatusLabel' => null !== $passwordRequest ? $stateFormatter->label($passwordRequest->getState()) : null,
            'passwordRequestStatusClass' => null !== $passwordRequest ? $stateFormatter->cssClass($passwordRequest->getState()) : null,
        ]);
    }

    #[Route(path: '/profile/messaging-preferences', name: 'app_profile_messaging_preferences', methods: ['POST'])]
    public function updateMessagingPreferences(Request $request, EntityManagerInterface $entityManager, #[Target('app.message_signature')] HtmlSanitizerInterface $sanitizer): Response
    {
        $user = $this->currentUser();

        $form = $this->createForm(MessagingPreferencesType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $signature = $user->getSignature();
            $user->setSignature(null !== $signature && '' !== trim(strip_tags($signature)) ? $sanitizer->sanitize($signature) : null);

            $entityManager->flush();

            $this->addFlash('success', 'messagingPreferencesSavedFlashMessage');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_profile');
    }

    // Self-service AD password change: re-verifies currentPassword via a live LDAP bind (the same
    // mechanism the login form itself uses) before queuing the new one - never trusts the active
    // session alone, so a left-open/stolen session can't change the account's password without
    // knowing it. Queues rather than applies immediately: the actual samba-tool call only happens
    // once the external manage_password.php consumer picks the row up (see
    // App\Entity\LdapManagePassword's class docblock).
    #[Route(path: '/profile/change-password', name: 'app_profile_change_password', methods: ['POST'])]
    public function changePassword(Request $request, EntityManagerInterface $entityManager, LdapCredentialsVerifier $credentialsVerifier, LdapManagePasswordRepository $passwordRequestRepository): Response
    {
        $user = $this->currentUser();

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentPassword = $form->get('currentPassword')->getData();
            $newPassword = $form->get('newPassword')->getData();

            if (!$credentialsVerifier->verifyPassword($currentPassword, $user)) {
                $this->addFlash('error', 'currentPasswordIncorrectFlashMessage');

                return $this->redirectToRoute('app_profile');
            }

            if (str_contains(mb_strtolower($newPassword), mb_strtolower($user->getUsername()))) {
                $this->addFlash('error', 'newPasswordContainsUsernameFlashMessage');

                return $this->redirectToRoute('app_profile');
            }

            $ldapManagePassword = new LdapManagePassword($user);
            $ldapManagePassword->setAddedBy($user->getUsername());

            $entityManager->persist($ldapManagePassword);
            $entityManager->flush();

            $passwordRequestRepository->setRequestedPassword($ldapManagePassword, $newPassword);

            $this->addFlash('success', 'changePasswordRequestedFlashMessage');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_profile');
    }

    // Only sends a new confirmation mail when the address actually changed - resubmitting the
    // same value (e.g. re-saving the form without touching the field) must not reset the pending
    // token or spam another email.
    #[Route(path: '/profile/contact-email', name: 'app_profile_contact_email', methods: ['POST'])]
    public function updateContactEmail(Request $request, EntityManagerInterface $entityManager, ContactEmailVerifier $contactEmailVerifier): Response
    {
        $user = $this->currentUser();
        $previousEmail = $user->getContactEmail();

        $form = $this->createForm(ContactEmailType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newEmail = $user->getContactEmail();

            if ($newEmail !== $previousEmail) {
                if (null === $newEmail) {
                    $user->setContactEmailVerifiedAt(null)->setContactEmailToken(null)->setContactEmailTokenRequestedAt(null);
                    $this->addFlash('success', 'contactEmailRemovedFlashMessage');
                } else {
                    $contactEmailVerifier->requestVerification($user);
                    $this->addFlash('success', 'contactEmailConfirmationSentFlashMessage');
                }

                $entityManager->flush();
            }
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route(path: '/profile/contact-email/resend', name: 'app_profile_contact_email_resend', methods: ['POST'])]
    public function resendContactEmailConfirmation(Request $request, EntityManagerInterface $entityManager, ContactEmailVerifier $contactEmailVerifier): Response
    {
        $user = $this->currentUser();

        if (!$this->isCsrfTokenValid('contact_email_resend', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (null !== $user->getContactEmail() && !$user->isContactEmailVerified()) {
            if ($contactEmailVerifier->canResend($user)) {
                $contactEmailVerifier->requestVerification($user);
                $entityManager->flush();
                $this->addFlash('success', 'contactEmailConfirmationSentFlashMessage');
            } else {
                $this->addFlash('error', 'contactEmailResendTooSoonFlashMessage');
            }
        }

        return $this->redirectToRoute('app_profile');
    }

    // Reached from the link mailed by ContactEmailVerifier::requestVerification() - deliberately
    // still ROLE_USER-gated (see class attribute) rather than a public route: only ever checks the
    // logged-in user's own pending token, never a global lookup, and a click from a fresh session
    // just bounces through /login and back since every user here already has platform access.
    #[Route(path: '/profile/contact-email/confirm/{token}', name: 'app_profile_contact_email_confirm')]
    public function confirmContactEmail(string $token, EntityManagerInterface $entityManager, ContactEmailVerifier $contactEmailVerifier): Response
    {
        $user = $this->currentUser();

        if ($contactEmailVerifier->confirm($user, $token)) {
            $entityManager->flush();
            $this->addFlash('success', 'contactEmailConfirmedFlashMessage');
        } else {
            $this->addFlash('error', 'contactEmailConfirmInvalidFlashMessage');
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route(path: '/profile/avatar', name: 'app_profile_avatar', methods: ['POST'])]
    public function uploadAvatar(Request $request, EntityManagerInterface $entityManager, FileUploadService $fileUploadService): Response
    {
        $user = $this->currentUser();

        $form = $this->createForm(AvatarUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('avatarFile')->getData();
            $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();

            // Timestamp in the filename (not a query-string cache-buster) so the URL itself
            // changes on every replace - CloudFront/browsers never need to be told to revalidate
            // a stale cached image. The old object is only deleted after the new one is safely
            // persisted, so a mid-upload failure never leaves the user with a broken avatar.
            $oldKey = $user->getAvatarKey();
            $newKey = $fileUploadService->upload(self::AVATAR_PREFIX, sprintf('%d-%d.%s', $user->getId(), time(), $extension), $file);

            $user->setAvatarKey($newKey);
            $entityManager->flush();

            if (null !== $oldKey) {
                $fileUploadService->delete($oldKey);
            }

            $this->addFlash('success', 'avatarUploadedFlashMessage');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_profile');
    }

    // Also called for anonymous visitors from the login page (see security/login.html.twig) via
    // a separate unauthenticated route below - this one only ever runs for a logged-in user, so
    // it's the only place the choice is persisted to the database (the login page's own toggle is
    // cookie-only, see theme_controller.js).
    #[Route(path: '/profile/theme', name: 'app_profile_theme', methods: ['POST'])]
    public function updateTheme(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->currentUser();

        if (!$this->isCsrfTokenValid('profile_theme', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $payload = json_decode($request->getContent(), true);
        $theme = $payload['theme'] ?? null;

        if (!\in_array($theme, ['light', 'dark'], true)) {
            throw $this->createAccessDeniedException();
        }

        $user->setThemePreference($theme);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
