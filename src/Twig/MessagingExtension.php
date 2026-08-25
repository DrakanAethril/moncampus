<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Enum\Feature;
use App\Repository\MessageThreadRecipientRepository;
use App\Security\FeatureAccess;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Powers the "Messages" nav badge in templates/layout/app.html.twig - a Twig function rather than
// passing this from every controller, same reasoning as AvatarExtension/StructureNavigationExtension.
// Also backs the inbox list's per-row day grouping and timestamp (design/design_handoff_messagerie
// #1) - kept here rather than computed in App\Controller\MessageController so
// messages/_thread_rows.html.twig (the "Charger plus" fragment) and the initial page render both
// get it from the exact same place.
class MessagingExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageThreadRecipientRepository $recipientRepository,
        private readonly TranslatorInterface $translator,
        private readonly FeatureAccess $featureAccess,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_message_thread_count', $this->unreadMessageThreadCount(...)),
            new TwigFunction('message_day_label', $this->messageDayLabel(...)),
            new TwigFunction('message_row_time', $this->messageRowTime(...)),
            new TwigFunction('message_initials', $this->messageInitials(...)),
        ];
    }

    // Same two-letter-initials shape as App\Controller\MessageController::initialsFor() (list
    // rows are built server-side as plain arrays there; this covers the handful of avatars
    // messages/show.html.twig and messages/compose.html.twig render straight from a User/name
    // string) - small enough that duplicating it here beats introducing a shared service for one
    // five-line pure function.
    public function messageInitials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        if (\count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($name, 0, 2));
    }

    /**
     * Zero when the messaging feature is switched off for this account, without asking the database
     * (design/validated/feature-access.md §7.3).
     *
     * The badge is drawn from this number alone, in the navbar and nowhere else, so answering zero
     * here is what makes the pastille disappear along with the envelope - a count of unread threads
     * next to no way of reading them is worse than no count at all.
     */
    public function unreadMessageThreadCount(?User $user): int
    {
        if (null === $user || !$this->featureAccess->isEnabled(Feature::Messaging, $user)) {
            return 0;
        }

        return $this->recipientRepository->countUnreadForUser($user);
    }

    // "Aujourd'hui" / "Hier" / "20/07/2026" - the sticky group header above a run of same-day
    // rows in the list pane.
    public function messageDayLabel(\DateTimeImmutable $date): string
    {
        return match ($date->format('Y-m-d')) {
            (new \DateTimeImmutable('today'))->format('Y-m-d') => $this->translator->trans('messageDayTodayLabel'),
            (new \DateTimeImmutable('yesterday'))->format('Y-m-d') => $this->translator->trans('messageDayYesterdayLabel'),
            default => $date->format('d/m/Y'),
        };
    }

    // "09:12" for something sent earlier today, "20/07" otherwise - same today/not-today split as
    // messageDayLabel() above, just formatted for the row's own timestamp slot rather than a group
    // header.
    public function messageRowTime(\DateTimeImmutable $date): string
    {
        return $date >= new \DateTimeImmutable('today') ? $date->format('H:i') : $date->format('d/m');
    }
}
