<?php

declare(strict_types=1);

namespace App\Controller\WordCloud;

use App\Attribute\RequiresFeature;
use App\Entity\WordCloud;
use App\Enum\Feature;
use App\Enum\WordCloudModerationState;
use App\Enum\WordCloudScale;
use App\Repository\WordCloudRepository;
use App\Repository\WordCloudSubmissionRepository;
use App\Security\Voter\WordCloudVoter;
use App\Service\WordCloud\WordCloudAudience;
use App\Service\WordCloud\WordCloudBoard;
use App\Service\WordCloud\WordCloudSchedule;
use App\Service\WordCloud\WordCloudSubmissionRefused;
use App\Service\WordCloud\WordCloudSubmissionService;
use App\Service\WordCloud\WordCloudWeighting;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The student's side: the question, a box to write in, and what they have already sent.
 *
 * It lives under `/my/...` like « Mes machines virtuelles », not under the class's own screens: a
 * student reaches it from the banner on their dashboard, the way they join a live contest, and the
 * class is something the cloud already knows.
 *
 * There is no access code and no QR code, deliberately - the design took both out. The audience is
 * already known, so the door is the Voter's, not a string on a slide.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::WordCloud)]
class StudentController extends AbstractController
{
    use WordCloudControllerTrait;

    private const string CSRF_TOKEN_ID = 'word_cloud_submit';

    public function __construct(
        private readonly WordCloudRepository $clouds,
        private readonly WordCloudSubmissionRepository $submissions,
        private readonly WordCloudAudience $audience,
        private readonly WordCloudSchedule $schedule,
        private readonly WordCloudBoard $board,
        private readonly WordCloudWeighting $weighting,
    ) {
    }

    #[Route(path: '/my/word-clouds/{id}', name: 'app_my_word_cloud', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $cloud = $this->findAddressedCloud($id);

        return $this->render('word_cloud/student.html.twig', $this->viewModel($cloud));
    }

    #[Route(path: '/my/word-clouds/{id}/submit', name: 'app_my_word_cloud_submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submit(int $id, Request $request, WordCloudSubmissionService $service): Response
    {
        $cloud = $this->findAddressedCloud($id);
        $this->assertCsrf(self::CSRF_TOKEN_ID, $request->request->getString('_token'));

        // The period is the Voter's answer as much as the endpoint's, and both are read: hiding the
        // form is not what closes a cloud.
        $this->denyAccessUnlessGranted(WordCloudVoter::SUBMIT, $cloud);

        foreach ($this->wordsOf($request) as $word) {
            try {
                $service->submit($cloud, $this->currentUser(), $word, new \DateTimeImmutable());
                $this->addFlash('success', 'wordCloudSubmittedFlashMessage');
            } catch (WordCloudSubmissionRefused $refused) {
                // One word refused does not throw the others away: the form sends up to N boxes at
                // once, and a duplicate in the second must not lose the first.
                $this->addFlash('error', $refused->refusal->messageKey());
            }
        }

        return $this->redirectToRoute('app_my_word_cloud', ['id' => $cloud->getId()]);
    }

    /**
     * The boxes of the form, blank ones dropped.
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

        return $words;
    }

    /**
     * A cloud this student is actually asked. Anything else is a 404: not a permission refused, a
     * question that was not put to them.
     */
    private function findAddressedCloud(int $id): WordCloud
    {
        $cloud = $this->clouds->find($id);

        if (null === $cloud || !$this->audience->includes($cloud, $this->currentUser())) {
            throw $this->createNotFoundException();
        }

        return $cloud;
    }

    /** @return array<string, mixed> */
    private function viewModel(WordCloud $cloud): array
    {
        $now = new \DateTimeImmutable();
        $own = $this->submissions->findForCloudAndStudent($cloud, $this->currentUser());

        $counted = \count(array_filter(
            $own,
            static fn ($one): bool => WordCloudModerationState::Rejected !== $one->getModerationState(),
        ));

        $quota = $cloud->getWordsPerStudent();
        // How many empty boxes to draw. Unlimited means one at a time: a page of thirty boxes is
        // not what « Illimités » is for.
        $remaining = null === $quota ? 1 : max(0, $quota - $counted);

        // Only when the teacher asked for it - « Les étudiants voient le nuage sur leur écran » is
        // off by default, because a cloud on every desk is a cloud nobody looks up from.
        $board = $cloud->isVisibleToStudents() ? $this->board->build($cloud) : null;

        return [
            'cloud' => $cloud,
            'own' => $own,
            'status' => $this->schedule->status($cloud->window(), $now),
            'canSubmit' => $this->isGranted(WordCloudVoter::SUBMIT, $cloud),
            'remainingBoxes' => $remaining,
            'board' => $board,
            'scale' => WordCloudScale::PilotPreview,
            'words' => null !== $board ? $this->weighting->layout($board->words, WordCloudScale::PilotPreview) : [],
        ];
    }
}
