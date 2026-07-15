<?php

namespace App\Controller\Api;

use App\Entity\Announcement;
use App\Repository\AnnouncementRepository;
use App\Security\Voter\AudienceTargetableVoter;
use App\Service\AudienceLabelFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only mobile counterpart to AnnouncementController's list() action, non-staff branch only
 * (findAllActive() + AudienceTargetableVoter::VIEW filtering) - a mobile client never gets the
 * staff management view (every announcement, including expired), same as a non-staff web user.
 * $body is the raw sanitized HTML (see Announcement's docblock) - same convention as Api\
 * MessagesController's Message::$body, stripped to plain text client-side rather than server-side.
 */
class AnnouncementController extends AbstractController
{
    #[Route(path: '/api/announcements', name: 'api_announcements', methods: ['GET'])]
    public function list(AnnouncementRepository $repository, AudienceLabelFormatter $labelFormatter): JsonResponse
    {
        $announcements = array_values(array_filter(
            $repository->findAllActive(),
            fn (Announcement $announcement): bool => $this->isGranted(AudienceTargetableVoter::VIEW, $announcement),
        ));

        return $this->json([
            'announcements' => array_map(fn (Announcement $announcement): array => $this->formatAnnouncement($announcement, $labelFormatter), $announcements),
        ]);
    }

    /** @return array{id: int, title: string, body: string, creationDate: string, audienceLabel: string} */
    private function formatAnnouncement(Announcement $announcement, AudienceLabelFormatter $labelFormatter): array
    {
        return [
            'id' => $announcement->getId(),
            'title' => $announcement->getTitle(),
            'body' => $announcement->getBody(),
            'creationDate' => $announcement->getCreationDate()->format(\DateTimeInterface::ATOM),
            'audienceLabel' => $labelFormatter->format($announcement),
        ];
    }
}
