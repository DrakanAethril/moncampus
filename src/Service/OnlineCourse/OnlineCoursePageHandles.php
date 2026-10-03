<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

use App\Entity\OnlineCoursePage;
use App\Entity\OnlineCoursePageHandle;
use App\Entity\User;
use App\Repository\OnlineCoursePageHandleRepository;
use App\Service\HelpSlug;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The address of a teacher's public page - choosing it, changing it, and finding a page by any
 * address it has ever carried (design/validated/cours-en-ligne.md, §5).
 *
 * The rule is the user's: **the address may change at any time, before and during diffusion**. So
 * nothing is frozen here; what a change must not do is break a link already handed out, nor let
 * somebody else take the address that was left. Both come from one table: every address a page has
 * carried keeps its row (App\Entity\OnlineCoursePageHandle), a row belongs to one page for ever,
 * and the UNIQUE index refuses a second one. A page that takes an old address of its own back
 * simply finds its row again.
 */
class OnlineCoursePageHandles
{
    public const int MIN_LENGTH = 3;
    public const int MAX_LENGTH = 60;

    /**
     * Segments that mean something else right under `/courses`, today or soon enough that handing
     * them out now would be a debt.
     *
     * @var list<string>
     */
    private const array RESERVED = ['paths', 'path', 'new', 'page', 'tags', 'tag', 'search', 'play', 'api', 'admin', 'tools', 'courses', 'cours', 'parcours'];

    public function __construct(
        private readonly OnlineCoursePageHandleRepository $handles,
        private readonly EntityManagerInterface $entityManager,
        private readonly HelpSlug $slug,
    ) {
    }

    /** What the teacher typed, as an address: lowercase ASCII words joined by hyphens. */
    public function normalize(string $raw): string
    {
        return mb_substr($this->slug->from($raw), 0, self::MAX_LENGTH);
    }

    /**
     * Why this address cannot be this owner's, as a translation key - or null when it can.
     *
     * @param ?OnlineCoursePage $page the page asking, when it already exists: an address it carried
     *                                before is its own to take back
     */
    public function refusal(string $handle, User $owner, ?OnlineCoursePage $page = null): ?string
    {
        if (mb_strlen($handle) < self::MIN_LENGTH || 1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $handle)) {
            return 'onlineCourseHandleFormatMessage';
        }

        if (\in_array($handle, self::RESERVED, true)) {
            return 'onlineCourseHandleReservedMessage';
        }

        // The address is public, the login is what somebody signs in with: the first must never
        // give the second away, even when the teacher types it themselves.
        if (mb_strtolower($owner->getUserIdentifier()) === $handle) {
            return 'onlineCourseHandleIsLoginMessage';
        }

        $existing = $this->handles->findOneBy(['handle' => $handle]);
        if (null !== $existing && (null === $page || $existing->getPage() !== $page)) {
            return 'onlineCourseHandleTakenMessage';
        }

        return null;
    }

    /**
     * Creates the owner's page under this address.
     *
     * @throws OnlineCourseHandleRefused
     */
    public function create(User $owner, string $handle, string $title): OnlineCoursePage
    {
        $handle = $this->normalize($handle);
        $refusal = $this->refusal($handle, $owner);
        if (null !== $refusal) {
            throw new OnlineCourseHandleRefused($refusal);
        }

        $page = new OnlineCoursePage($owner, $handle, $title);
        $this->entityManager->persist($page);
        $this->entityManager->persist(new OnlineCoursePageHandle($page, $handle));

        return $page;
    }

    /**
     * Moves a page to another address. The one it leaves keeps its row, and so keeps redirecting.
     *
     * @throws OnlineCourseHandleRefused
     */
    public function change(OnlineCoursePage $page, string $handle): void
    {
        $handle = $this->normalize($handle);
        if ($handle === $page->getHandle()) {
            return;
        }

        $refusal = $this->refusal($handle, $page->getOwner(), $page);
        if (null !== $refusal) {
            throw new OnlineCourseHandleRefused($refusal);
        }

        if (null === $this->handles->findOneBy(['handle' => $handle])) {
            $this->entityManager->persist(new OnlineCoursePageHandle($page, $handle));
        }

        $page->setHandle($handle);
    }

    /**
     * The page an address leads to, whether it is the page's current one or one it used to carry.
     * The caller compares with getHandle() to know whether to redirect.
     */
    public function resolve(string $handle): ?OnlineCoursePage
    {
        return $this->handles->findOneBy(['handle' => mb_strtolower($handle)])?->getPage();
    }

    /**
     * The addresses a page used to carry and still answers on, most recent first.
     *
     * @return list<string>
     */
    public function formerHandles(OnlineCoursePage $page): array
    {
        $former = [];
        foreach ($this->handles->findBy(['page' => $page], ['id' => 'DESC']) as $row) {
            if ($row->getHandle() !== $page->getHandle()) {
                $former[] = $row->getHandle();
            }
        }

        return $former;
    }
}
