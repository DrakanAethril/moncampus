<?php

declare(strict_types=1);

namespace App\Controller\WordCloud;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Enum\WordCloudProjectionMode;
use App\Enum\WordCloudScale;
use App\Repository\WordCloudRepository;
use App\Service\WordCloud\WordCloudBoard;
use App\Service\WordCloud\WordCloudLiveNotifier;
use App\Service\WordCloud\WordCloudPublicAddress;
use App\Service\WordCloud\WordCloudSchedule;
use App\Service\WordCloud\WordCloudWeighting;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The board: the cloud alone, or the cloud beside « Les plus cités » and « Derniers arrivés ».
 *
 * Which of the two comes from the cloud's own setting and stays switchable from here - a teacher
 * who sees the room reading the wrong thing should not have to leave the projection to change it,
 * so the mode is a query parameter defaulting to the stored one.
 *
 * The screen carries no navigation and no chrome of any kind: everything on it is either the
 * question or an answer to it. A public cloud's QR code has a screen of its own, qr() below. The one control, « Quitter le plein écran », is drawn by the
 * browser's own fullscreen exit and by a single link.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::WordCloud)]
class ProjectionController extends AbstractController
{
    use WordCloudControllerTrait;

    public function __construct(
        private readonly WordCloudRepository $clouds,
        private readonly WordCloudBoard $board,
        private readonly WordCloudWeighting $weighting,
        private readonly WordCloudSchedule $schedule,
        private readonly WordCloudLiveNotifier $liveNotifier,
        private readonly WordCloudPublicAddress $publicAddress,
    ) {
    }

    #[Route(path: '/word-clouds/{cloudId}/projection', name: 'app_word_cloud_projection', requirements: ['cloudId' => '\d+'], methods: ['GET'])]
    public function project(int $cloudId, Request $request, HubInterface $hub, Authorization $mercureAuthorization): Response
    {
        $cloud = $this->findCloudOrDeny($cloudId, $this->clouds);

        $mode = WordCloudProjectionMode::tryFrom($request->query->getString('mode')) ?? $cloud->getProjectionMode();
        $scale = WordCloudProjectionMode::WithLatest === $mode
            ? WordCloudScale::ProjectionWithPanel
            : WordCloudScale::ProjectionFull;

        $board = $this->board->build($cloud);

        $live = $this->grantLiveSubscription($request, $mercureAuthorization, $this->liveNotifier, $cloud);

        return $this->render('word_cloud/projection.html.twig', [
            'program' => $cloud->getProgram(),
            'cloud' => $cloud,
            'board' => $board,
            'mode' => $mode,
            'scale' => $scale,
            'words' => $this->weighting->layout($board->words, $scale),
            'status' => $this->schedule->status($cloud->window(), new \DateTimeImmutable()),
            // Empty when the subscription could not be granted: the template then draws the board
            // without mounting the live controller, rather than opening a stream that is refused.
            'mercurePublicUrl' => $live ? $hub->getPublicUrl() : '',
            'topic' => $live ? $this->liveNotifier->topic($cloud) : '',
            'publicUrl' => $this->publicAddress->url($cloud),
        ]);
    }

    /**
     * The QR code of a public cloud, projected on its own (design/validated/word-cloud-public.md):
     * the code, the short address and the code to type, the question, and how many have answered so
     * far - the screen a room looks at before the words start coming. A cloud that is not public has
     * no such screen.
     */
    #[Route(path: '/word-clouds/{cloudId}/qr', name: 'app_word_cloud_qr', requirements: ['cloudId' => '\d+'], methods: ['GET'])]
    public function qr(int $cloudId, Request $request, HubInterface $hub, Authorization $mercureAuthorization): Response
    {
        $cloud = $this->findCloudOrDeny($cloudId, $this->clouds);
        $qrSvg = $this->publicAddress->qrSvg($cloud, 520);
        if (null === $qrSvg) {
            throw $this->createNotFoundException();
        }

        $live = $this->grantLiveSubscription($request, $mercureAuthorization, $this->liveNotifier, $cloud);

        return $this->render('word_cloud/qr.html.twig', [
            'cloud' => $cloud,
            'board' => $this->board->build($cloud),
            'status' => $this->schedule->status($cloud->window(), new \DateTimeImmutable()),
            'qrSvg' => $qrSvg,
            'joinLabel' => $this->publicAddress->joinLabel(),
            'mercurePublicUrl' => $live ? $hub->getPublicUrl() : '',
            'topic' => $live ? $this->liveNotifier->topic($cloud) : '',
        ]);
    }
}
