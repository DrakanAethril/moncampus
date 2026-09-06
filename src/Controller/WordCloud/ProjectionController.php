<?php

declare(strict_types=1);

namespace App\Controller\WordCloud;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Enum\WordCloudProjectionMode;
use App\Enum\WordCloudScale;
use App\Repository\ProgramRepository;
use App\Repository\WordCloudRepository;
use App\Security\StructureAccessChecker;
use App\Service\WordCloud\WordCloudBoard;
use App\Service\WordCloud\WordCloudLiveNotifier;
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
 * The screen carries no navigation, no code and no chrome of any kind: everything on it is either
 * the question or an answer to it. The one control, « Quitter le plein écran », is drawn by the
 * browser's own fullscreen exit and by a single link.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::WordCloud)]
class ProjectionController extends AbstractController
{
    use WordCloudControllerTrait;

    public function __construct(
        private readonly ProgramRepository $programs,
        private readonly WordCloudRepository $clouds,
        private readonly StructureAccessChecker $accessChecker,
        private readonly WordCloudBoard $board,
        private readonly WordCloudWeighting $weighting,
        private readonly WordCloudSchedule $schedule,
        private readonly WordCloudLiveNotifier $liveNotifier,
    ) {
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/projection', name: 'app_program_word_cloud_projection', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['GET'])]
    public function project(int $id, int $cloudId, Request $request, HubInterface $hub, Authorization $mercureAuthorization): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);

        $mode = WordCloudProjectionMode::tryFrom($request->query->getString('mode')) ?? $cloud->getProjectionMode();
        $scale = WordCloudProjectionMode::WithLatest === $mode
            ? WordCloudScale::ProjectionWithPanel
            : WordCloudScale::ProjectionFull;

        $board = $this->board->build($cloud);

        $mercureAuthorization->setCookie($request, [$this->liveNotifier->topic($cloud)], [], [], 'subscriber');

        return $this->render('word_cloud/projection.html.twig', [
            'program' => $program,
            'cloud' => $cloud,
            'board' => $board,
            'mode' => $mode,
            'scale' => $scale,
            'words' => $this->weighting->layout($board->words, $scale),
            'status' => $this->schedule->status($cloud->window(), new \DateTimeImmutable()),
            'mercurePublicUrl' => $hub->getPublicUrl(),
            'topic' => $this->liveNotifier->topic($cloud),
        ]);
    }
}
