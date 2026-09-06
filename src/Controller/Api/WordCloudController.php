<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Entity\WordCloud;
use App\Entity\WordCloudSubmission;
use App\Enum\Feature;
use App\Enum\WordCloudModerationState;
use App\Enum\WordCloudScale;
use App\Enum\WordCloudWordLength;
use App\Repository\ProgramRepository;
use App\Repository\WordCloudRepository;
use App\Repository\WordCloudSubmissionRepository;
use App\Service\JsonRequestPayload;
use App\Service\WordCloud\WordCloudAudience;
use App\Service\WordCloud\WordCloudBoard;
use App\Service\WordCloud\WordCloudSchedule;
use App\Service\WordCloud\WordCloudSubmissionRefused;
use App\Service\WordCloud\WordCloudSubmissionService;
use App\Service\WordCloud\WordCloudWeighting;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Nuage de mots » on the phone: the same three gestures as the web student screen - see whether
 * one is open, read the question, write a word.
 *
 * Modelled on Api\QuizLiveController, which is the nearest thing: something happening in the room
 * right now, announced by a banner on the home screen. It is deliberately **simpler** in two ways,
 * and both are worth stating because the difference is the design's, not an omission:
 *
 * - **No join, no participant.** A live contest has to enrol a player and mint them a per-session
 *   token; a cloud already knows who it is asking (App\Service\WordCloud\WordCloudAudience), and
 *   the identity is the one in the JWT.
 * - **No live transport.** A contest *must* stream: the phone has to learn that the host moved on.
 *   A cloud's student writes a word and is done, and the web student screen does not live-update
 *   either. When « Les étudiants voient le nuage sur leur écran » is on, the cloud comes back with
 *   the answer to the submission - which is the only moment it can have changed for them.
 *
 * There is no host or projector API, for the same reason the live contest has none: nobody runs the
 * board from their phone.
 *
 * **Not one rule is re-implemented here.** The period, the quota, the duplicate and the length are
 * WordCloudSubmissionService's, exactly as on the web, so a word the browser would refuse cannot be
 * slipped in through the app.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::WordCloud)]
class WordCloudController extends AbstractController
{
    public function __construct(
        private readonly WordCloudRepository $clouds,
        private readonly WordCloudSubmissionRepository $submissions,
        private readonly WordCloudAudience $audience,
        private readonly WordCloudSchedule $schedule,
        private readonly WordCloudBoard $board,
        private readonly WordCloudWeighting $weighting,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Polled by the home screen on every load - the banner, and nothing else.
     *
     * It answers a non-student with an empty body rather than a 403, exactly as
     * Api\QuizLiveController::active() learned to: a denied access is logged at *error* level, and
     * an app the whole staff has installed then becomes a steady stream of Discord alerts.
     */
    #[Route(path: '/api/word-clouds/active', name: 'api_word_clouds_active', methods: ['GET'])]
    public function active(ProgramRepository $programs): JsonResponse
    {
        if (!$this->isGranted('ROLE_STUDENT')) {
            return $this->json(['cloud' => null]);
        }

        $student = $this->currentUser();
        $open = $this->clouds->findOpenForPrograms(
            $programs->findAllActiveForStudent($student),
            new \DateTimeImmutable(),
        );

        foreach ($open as $cloud) {
            // The audience is re-checked here rather than in the query: a cloud can be aimed at one
            // option of the class, and a banner offering a question that is not yours is a dead end.
            if ($this->audience->includes($cloud, $student)) {
                return $this->json(['cloud' => [
                    'id' => $cloud->getId(),
                    'name' => $cloud->getName(),
                    'question' => $cloud->getQuestion(),
                    'programShortName' => $cloud->getProgram()?->getDisplayShortName(),
                ]]);
            }
        }

        return $this->json(['cloud' => null]);
    }

    #[Route(path: '/api/word-clouds/{id}', name: 'api_word_cloud', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $cloud = $this->addressedCloud($id);

        if (null === $cloud) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->stateOf($cloud));
    }

    /**
     * One to N words in one call, and the new state back with them.
     *
     * A refused word does not throw the others away, exactly as the web form behaves: the app sends
     * the boxes it has, and a duplicate in the second must not lose the first. What comes back is
     * therefore a list of refusals *and* the state - one round trip, and the screen never has to
     * guess what was taken.
     */
    #[Route(path: '/api/word-clouds/{id}/submit', name: 'api_word_cloud_submit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function submit(int $id, Request $request, WordCloudSubmissionService $service): JsonResponse
    {
        $cloud = $this->addressedCloud($id);

        if (null === $cloud) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $payload = JsonRequestPayload::fromRequest($request);
        // `words` for several boxes, `word` for one - the app sends whichever it has, and neither
        // spelling is worth a second endpoint.
        $words = $payload->strings('words');
        if ([] === $words && '' !== trim($payload->string('word'))) {
            $words = [$payload->string('word')];
        }

        $accepted = [];
        $refusals = [];
        $now = new \DateTimeImmutable();

        foreach ($words as $word) {
            if ('' === trim($word)) {
                continue;
            }

            try {
                $service->submit($cloud, $this->currentUser(), $word, $now);
                $accepted[] = trim($word);
            } catch (WordCloudSubmissionRefused $refused) {
                $refusals[] = [
                    'word' => trim($word),
                    'reason' => $refused->refusal->value,
                    'message' => $this->translator->trans($refused->refusal->messageKey()),
                ];
            }
        }

        return $this->json([
            'accepted' => $accepted,
            'refusals' => $refusals,
            'state' => $this->stateOf($cloud),
        ]);
    }

    /**
     * What the screen needs to draw itself, and what it needs to know before letting somebody type.
     *
     * `remainingWords` is null when the cloud is « Illimités », the same null the whole tool uses
     * for it - never a large number the app would have to recognise as meaning "no limit".
     *
     * @return array<string, mixed>
     */
    private function stateOf(WordCloud $cloud): array
    {
        $now = new \DateTimeImmutable();
        $status = $this->schedule->status($cloud->window(), $now);
        $own = $this->submissions->findForCloudAndStudent($cloud, $this->currentUser());

        $counted = \count(array_filter(
            $own,
            static fn (WordCloudSubmission $one): bool => WordCloudModerationState::Rejected !== $one->getModerationState(),
        ));

        $quota = $cloud->getWordsPerStudent();
        $remaining = null === $quota ? null : max(0, $quota - $counted);

        return [
            'id' => $cloud->getId(),
            'name' => $cloud->getName(),
            'question' => $cloud->getQuestion(),
            'programShortName' => $cloud->getProgram()?->getDisplayShortName(),
            'status' => $status->value,
            // Whether a word may be written *now* - the period and the quota folded into the one
            // answer the screen actually asks for.
            'canSubmit' => $this->schedule->isOpen($cloud->window(), $now) && (null === $remaining || $remaining > 0),
            'remainingWords' => $remaining,
            'closesAt' => $cloud->getClosesAt()?->format(\DateTimeInterface::ATOM),
            // The two limits on one box, so the app can stop a word the server would refuse instead
            // of letting somebody type it and be told no.
            'maxWordsPerAnswer' => $cloud->getWordLength()->maxWords(),
            'maxCharacters' => WordCloudWordLength::MAX_CHARACTERS,
            'ownWords' => array_map(
                static fn (WordCloudSubmission $one): array => [
                    'text' => $one->getText(),
                    'rejected' => WordCloudModerationState::Rejected === $one->getModerationState(),
                    'pending' => WordCloudModerationState::Pending === $one->getModerationState(),
                ],
                $own,
            ),
            'cloud' => $cloud->isVisibleToStudents() ? $this->cloudPayload($cloud) : null,
        ];
    }

    /**
     * The cloud itself, already weighted.
     *
     * Sizes and colour rungs come from App\Service\WordCloud\WordCloudWeighting, like every other
     * surface of this tool: the phone cannot draw a cloud the board would have drawn differently.
     * The rung is a name rather than a colour because the palette follows the app's own theme.
     *
     * @return list<array{word: string, count: int, size: int, step: string}>
     */
    private function cloudPayload(WordCloud $cloud): array
    {
        return $this->weighting->layout($this->board->build($cloud)->words, WordCloudScale::PilotPreview);
    }

    /**
     * A cloud this student is actually asked, or null.
     *
     * Null becomes a 404 rather than a 403: a question that was never put to somebody does not
     * exist for them, and saying « interdit » would confirm that it does.
     */
    private function addressedCloud(int $id): ?WordCloud
    {
        $cloud = $this->clouds->find($id);

        if (null === $cloud || !$this->audience->includes($cloud, $this->currentUser())) {
            return null;
        }

        return $cloud;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
