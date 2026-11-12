<?php

declare(strict_types=1);

namespace App\Controller\WordCloud;

use App\Entity\User;
use App\Entity\WordCloud;
use App\Enum\Feature;
use App\Enum\WordCloudModerationState;
use App\Enum\WordCloudScale;
use App\Repository\WordCloudRepository;
use App\Repository\WordCloudSubmissionRepository;
use App\Security\FeatureAccess;
use App\Service\WordCloud\WordCloudAuthor;
use App\Service\WordCloud\WordCloudBoard;
use App\Service\WordCloud\WordCloudGuestToken;
use App\Service\WordCloud\WordCloudPublicCodes;
use App\Service\WordCloud\WordCloudSchedule;
use App\Service\WordCloud\WordCloudSubmissionRefused;
use App\Service\WordCloud\WordCloudSubmissionService;
use App\Service\WordCloud\WordCloudWeighting;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A public cloud's address, /cloud/{code} - what its QR code opens (design/validated/
 * word-cloud-public.md). `^/cloud(/|$)` is PUBLIC_ACCESS (config/packages/security.yaml): a signed-in
 * account and a visitor without one both arrive here.
 *
 * - An account answers as itself, never as a guest - the live contest's rule - and its words are the
 *   ones it would have written from « Mes nuages » had the cloud been its class's.
 * - A visitor answers with no name at all: a token in a cookie scoped to this address
 *   (WordCloudGuestToken) is what the quota and the duplicate rule read, minted with the first word.
 * - Every rule of a word is WordCloudSubmissionService's, the same door as the student's screen and
 *   the mobile API; this page only says who is writing.
 *
 * No #[RequiresFeature]: a visitor has no role to read the matrix with. A cloud exists here only
 * while it is public and the feature is lit for its author - else a 404
 * (tests/Functional/FeatureCoverageTest.php names this controller's routes).
 */
class PublicController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'word_cloud_public';

    public function __construct(
        private readonly WordCloudRepository $clouds,
        private readonly WordCloudSubmissionRepository $submissions,
        private readonly WordCloudSchedule $schedule,
        private readonly WordCloudBoard $board,
        private readonly WordCloudWeighting $weighting,
        private readonly FeatureAccess $features,
    ) {
    }

    /**
     * The address without its code: a page that asks for it, and sends a code it finds on to the
     * cloud's own address. A code no public cloud carries is answered on the page itself.
     */
    #[Route(path: '/cloud', name: 'app_word_cloud_public_join', methods: ['GET'])]
    public function join(Request $request): Response
    {
        $typed = $request->query->has('code') ? trim($request->query->getString('code')) : null;
        $error = null;
        if (null !== $typed) {
            $code = WordCloudPublicCodes::codeOf($typed);
            $cloud = null !== $code ? $this->find($code) : null;
            if (null !== $cloud) {
                return $this->redirectToRoute('app_word_cloud_public', ['code' => $cloud->getPublicCode()]);
            }
            $error = '' === $typed ? 'wordCloudJoinEmpty' : 'wordCloudJoinUnknown';
        }

        return $this->render('word_cloud/public_join.html.twig', [
            'typed' => $typed ?? '',
            'error' => $error,
        ], new Response('', 'wordCloudJoinUnknown' === $error ? Response::HTTP_NOT_FOUND : Response::HTTP_OK));
    }

    #[Route(path: '/cloud/{code}', name: 'app_word_cloud_public', requirements: ['code' => '[A-Za-z0-9]{4,8}'], methods: ['GET'])]
    public function show(string $code, Request $request): Response
    {
        $cloud = $this->cloud($code);
        $author = $this->author($request, $cloud);
        $own = null !== $author ? $this->submissions->findForCloudAndAuthor($cloud, $author) : [];
        $now = new \DateTimeImmutable();

        $counted = \count(array_filter($own, static fn ($one): bool => WordCloudModerationState::Rejected !== $one->getModerationState()));
        $quota = $cloud->getWordsPerStudent();
        // One box at a time when « Illimités », as on the student's screen.
        $remaining = null === $quota ? 1 : max(0, $quota - $counted);
        $board = $cloud->isVisibleToStudents() ? $this->board->build($cloud) : null;

        return $this->render('word_cloud/public.html.twig', [
            'cloud' => $cloud,
            'own' => $own,
            'status' => $this->schedule->status($cloud->window(), $now),
            'open' => $this->schedule->isOpen($cloud->window(), $now),
            'remainingBoxes' => $remaining,
            'board' => $board,
            'scale' => WordCloudScale::PilotPreview,
            'words' => null !== $board ? $this->weighting->layout($board->words, WordCloudScale::PilotPreview) : [],
        ]);
    }

    #[Route(path: '/cloud/{code}/submit', name: 'app_word_cloud_public_submit', requirements: ['code' => '[A-Za-z0-9]{4,8}'], methods: ['POST'])]
    public function submit(
        string $code,
        Request $request,
        WordCloudSubmissionService $service,
        #[Autowire(service: 'limiter.word_cloud_guest')] RateLimiterFactory $limiter,
    ): Response {
        $cloud = $this->cloud($code);
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $response = $this->redirectToRoute('app_word_cloud_public', ['code' => $cloud->getPublicCode()], Response::HTTP_SEE_OTHER);
        $words = $this->wordsOf($request);
        if ([] === $words) {
            return $response;
        }

        $author = $this->author($request, $cloud);
        if (null === $author) {
            // A visitor's first word: their token is minted now, and travels back with the answer.
            $token = WordCloudGuestToken::mint();
            $author = WordCloudAuthor::guest(WordCloudGuestToken::hash($token));
            $response->headers->setCookie(WordCloudGuestToken::cookie($cloud, $token, $request->isSecure()));
        }

        // Against a script: losing the cookie is a fresh quota, the IP is not. Generous enough for
        // a whole school behind one address.
        if (null === $author->user && !$limiter->create('ip:'.$request->getClientIp())->consume(\count($words))->isAccepted()) {
            $this->addFlash('error', 'wordCloudPublicTooManyFlashMessage');

            return $response;
        }

        foreach ($words as $word) {
            try {
                $service->submitAs($cloud, $author, $word, new \DateTimeImmutable());
                $this->addFlash('success', 'wordCloudSubmittedFlashMessage');
            } catch (WordCloudSubmissionRefused $refused) {
                // One word refused does not throw the others away.
                $this->addFlash('error', $refused->refusal->messageKey());
            }
        }

        return $response;
    }

    /** A public cloud of this code, whose author has the feature lit - or a 404. */
    private function cloud(string $code): WordCloud
    {
        return $this->find($code) ?? throw $this->createNotFoundException();
    }

    private function find(string $code): ?WordCloud
    {
        $cloud = $this->clouds->findOneByPublicCode($code);
        $author = $cloud?->getTeacher();

        return null !== $cloud && $cloud->isPublic() && null !== $author && $this->features->isEnabled(Feature::WordCloud, $author) ? $cloud : null;
    }

    /** The account signed in, or the guest this browser already is on this cloud - or nobody yet. */
    private function author(Request $request, WordCloud $cloud): ?WordCloudAuthor
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return WordCloudAuthor::account($user);
        }

        $token = WordCloudGuestToken::fromRequest($request, $cloud);

        return null !== $token ? WordCloudAuthor::guest(WordCloudGuestToken::hash($token)) : null;
    }

    /**
     * The boxes of the form, blank ones dropped - at most twenty, whatever was posted.
     *
     * @return list<string>
     */
    private function wordsOf(Request $request): array
    {
        /** @var array<array-key, mixed> $submitted */
        $submitted = $request->request->all('words');

        $words = [];
        foreach ($submitted as $word) {
            $trimmed = \is_scalar($word) ? trim((string) $word) : '';
            if ('' !== $trimmed) {
                $words[] = $trimmed;
            }
        }

        return \array_slice($words, 0, 20);
    }
}
