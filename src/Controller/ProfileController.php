<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\AvatarUploadType;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function index(): Response
    {
        return $this->render('profile/index.html.twig', [
            'user' => $this->currentUser(),
            'avatarForm' => $this->createForm(AvatarUploadType::class),
        ]);
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
