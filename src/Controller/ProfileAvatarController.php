<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\AvatarChoice;
use App\Form\AvatarUploadType;
use App\SeriousGame\Avatar\SeriousGameAvatars;
use App\Service\FileUploadService;
use App\Service\StagedUpload;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Mon avatar », on « Mon profil » (design/validated/quiz-tournament.md §16): one choice, two paths,
 * for every account - send a photo, or draw an avatar in the creation tunnel. What stands for the
 * person is `User::$avatarChoice` (App\Service\UserAvatars); sending a photo chooses the photo,
 * saving a drawing chooses the drawing, and switching between them deletes neither.
 *
 * Not behind a feature (FeatureCoverageTest, « outside the catalogue on purpose »): it is how
 * somebody presents their own account. The drawing is the serious games' editor
 * (`sg_avatar_editor_controller.js` over `public/serious-games/shared/avatar.js`), which moved here
 * from `/serious-games/avatar`; pins are offered to whoever won one and nothing is earned here.
 */
#[IsGranted('ROLE_USER')]
class ProfileAvatarController extends AbstractController
{
    private const string AVATAR_PREFIX = 'avatars/';
    private const string DRAW_CSRF_ID = 'profile_avatar_draw';
    private const string CHOICE_CSRF_ID = 'profile_avatar_choice';

    #[Route(path: '/profile/avatar', name: 'app_profile_avatar', methods: ['POST'])]
    public function upload(Request $request, EntityManagerInterface $entityManager, FileUploadService $fileUploadService, UploadIntake $uploadIntake): Response
    {
        $user = $this->currentUser();

        $form = $this->createForm(AvatarUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var StagedUpload $file */
            $file = $form->get('avatarFile')->getData();
            $extension = UploadIntake::extension($file);

            // Timestamp in the filename (not a query-string cache-buster) so the URL itself
            // changes on every replace - CloudFront/browsers never need to be told to revalidate
            // a stale cached image. The old object is only deleted after the new one is safely
            // persisted, so a mid-upload failure never leaves the user with a broken avatar. Only
            // the previous *photo* goes: the drawing is never touched by a photo.
            $oldKey = $user->getAvatarKey();
            $newKey = $uploadIntake->store($file, self::AVATAR_PREFIX, sprintf('%d-%d.%s', $user->getId(), time(), $extension));

            $user->setAvatarKey($newKey);
            $user->setAvatarChoice(AvatarChoice::Photo);
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

        return $this->redirectToRoute('app_profile', ['_fragment' => 'mon-avatar']);
    }

    /** « Utiliser ma photo » / « Utiliser mon avatar » / « Aucun (initiales) »: nothing is deleted. */
    #[Route(path: '/profile/avatar/choice', name: 'app_profile_avatar_choice', methods: ['POST'])]
    public function choose(Request $request, EntityManagerInterface $entityManager, SeriousGameAvatars $drawings): Response
    {
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid(self::CHOICE_CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $choice = AvatarChoice::fromFormValue($request->request->getString('choice'));
        } catch (\ValueError) {
            throw $this->createNotFoundException();
        }

        if (null !== $choice && !$choice->isPossible(null !== $user->getAvatarKey(), $drawings->hasOne($user))) {
            $this->addFlash('error', 'profileAvatarChoiceUnavailableFlash');

            return $this->redirectToRoute('app_profile', ['_fragment' => 'mon-avatar']);
        }

        $user->setAvatarChoice($choice);
        $entityManager->flush();
        $this->addFlash('success', 'profileAvatarChoiceSavedFlash');

        return $this->redirectToRoute('app_profile', ['_fragment' => 'mon-avatar']);
    }

    /** The creation tunnel: the editor, drawn by its Stimulus controller from the shared module. */
    #[Route(path: '/profile/avatar/draw', name: 'app_profile_avatar_draw', methods: ['GET'])]
    public function draw(SeriousGameAvatars $drawings): Response
    {
        $user = $this->currentUser();
        $pins = $drawings->heldPins($user);

        return $this->render('profile/avatar_draw.html.twig', [
            'config' => $drawings->configFor($user),
            'saved' => $drawings->hasOne($user),
            'pins' => $pins,
            // The « Épinglettes » tab is offered to whoever holds one, never to promise one.
            'collects' => [] !== $pins,
            'shown' => AvatarChoice::Drawn === $user->getAvatarChoice(),
        ]);
    }

    /**
     * Saves the drawing and chooses it. The server keeps only the values the editor offers and
     * refuses a pin the person has not obtained (SeriousGameAvatars).
     */
    #[Route(path: '/profile/avatar/draw', name: 'app_profile_avatar_draw_save', methods: ['POST'])]
    public function saveDrawing(Request $request, SeriousGameAvatars $drawings): JsonResponse
    {
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid(self::DRAW_CSRF_ID, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $config = json_decode($request->getContent(), true);
        if (!\is_array($config)) {
            return $this->json(['error' => 'profileAvatarInvalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return $this->json(['config' => $drawings->draw($user, $config)]);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
