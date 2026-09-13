<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Section;
use App\Entity\User;
use App\Repository\JobboardTokenRepository;
use App\Repository\SectionRepository;
use App\Service\Jobboard\IngestInstructions;
use App\Service\Jobboard\IngestTokenFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Configuration > Jobboard > API » - the keys handed to the collecting agent, and the instruction
 * sheet that goes with them.
 *
 * Administrators only, and deliberately without a `#[RequiresFeature]`: no setting made here may
 * close the screen the settings are made on. The `app_settings_` route prefix is also what puts
 * these routes under FeatureCoverageTest's own exemption for that rule.
 *
 * **The secret is shown once.** It is parked in the session by the creating request, printed by the
 * redirect that follows, and dropped as it is read - a refresh does not show it again, and nothing
 * in the database can print it. That is the same posture as passwords on this platform: nothing is
 * ever read back.
 */
#[IsGranted('ROLE_ADMIN')]
class JobboardApiController extends AbstractController
{
    /** The freshly minted key, waiting for the redirect that prints it. Read once, then gone. */
    private const string SECRET_SESSION_KEY = 'jobboard_token_secret';

    #[Route(path: '/settings/jobboard/api', name: 'app_settings_jobboard_api', methods: ['GET'])]
    public function index(Request $request, JobboardTokenRepository $tokens, SectionRepository $sections, IngestInstructions $instructions): Response
    {
        $session = $request->getSession();
        $secret = $session->get(self::SECRET_SESSION_KEY);
        $session->remove(self::SECRET_SESSION_KEY);

        return $this->render('settings/jobboard_api.html.twig', [
            'tokens' => $tokens->findForScreen(),
            'sections' => $sections->findAllOrdered(),
            'secret' => \is_string($secret) ? $secret : null,
            // Generated from the same enums the validator uses. A sheet typed by hand would be
            // wrong the day a source is added, and wrong silently - on the agent's side.
            'instructions' => $instructions->markdown($request->getSchemeAndHttpHost()),
        ]);
    }

    #[Route(path: '/settings/jobboard/api/tokens', name: 'app_settings_jobboard_token_create', methods: ['POST'])]
    public function create(
        Request $request,
        SectionRepository $sections,
        IngestTokenFactory $factory,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('jobboard_token_create', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $label = trim((string) $request->request->get('label'));
        $section = $sections->find($request->request->getInt('section'));

        if ('' === $label || !$section instanceof Section) {
            $this->addFlash('danger', 'jobboardTokenIncompleteFlashMessage');

            return $this->redirectToRoute('app_settings_jobboard_api');
        }

        $minted = $factory->create(mb_substr($label, 0, 120), $section, $this->author());

        $entityManager->persist($minted['token']);
        $entityManager->flush();

        $request->getSession()->set(self::SECRET_SESSION_KEY, $minted['secret']);
        $this->addFlash('success', 'jobboardTokenCreatedFlashMessage');

        return $this->redirectToRoute('app_settings_jobboard_api');
    }

    #[Route(path: '/settings/jobboard/api/tokens/{id}/revoke', name: 'app_settings_jobboard_token_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(int $id, Request $request, JobboardTokenRepository $tokens, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('jobboard_token_revoke', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $token = $tokens->find($id);

        if (null === $token) {
            throw $this->createNotFoundException();
        }

        // Revoked, never deleted: the row is the trace of what deposited the offers already stored,
        // and the batches point at it.
        $token->revoke();
        $entityManager->flush();

        $this->addFlash('success', 'jobboardTokenRevokedFlashMessage');

        return $this->redirectToRoute('app_settings_jobboard_api');
    }

    private function author(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
