<?php

declare(strict_types=1);

namespace App\Service\Wall;

use App\Entity\User;
use App\Entity\Wall;
use App\Entity\WallCard;
use App\Entity\WallList;
use App\Enum\WallRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A wall as one person reads it: its lists, the cards that person may see in each, and for each
 * card whether they may change it. Built once per answer and handed to the templates, so that no
 * template asks a right card by card - and so that a card awaiting validation is left out here,
 * for everybody it is not meant for, rather than hidden by a stylesheet.
 *
 * Lists, cards and authors come in one query, the comment counts in a second: a wall of two
 * hundred cards costs what a wall of two does.
 *
 * @phpstan-type CardView array{card: WallCard, editable: bool, comments: int}
 * @phpstan-type ListView array{list: WallList, cards: list<CardView>}
 * @phpstan-type BoardView array{
 *     wall: Wall,
 *     role: WallRole,
 *     manages: bool,
 *     owns: bool,
 *     mayAdd: bool,
 *     lists: list<ListView>,
 * }
 */
final class WallBoardView
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WallAccess $access,
    ) {
    }

    /** @return BoardView */
    public function build(Wall $wall, User $user): array
    {
        $role = $this->access->roleOf($wall, $user) ?? throw new \LogicException('A wall is only ever drawn for somebody who may read it.');
        $comments = $wall->areCommentsEnabled() ? $this->commentCounts($wall) : [];

        $lists = [];
        foreach ($this->loadLists($wall) as $list) {
            $cards = [];
            foreach ($this->cardsOf($list) as $card) {
                if ($this->access->maySeeCard($card, $user)) {
                    $cards[] = [
                        'card' => $card,
                        'editable' => $this->access->mayEditCard($card, $user),
                        'comments' => $comments[(int) $card->getId()] ?? 0,
                    ];
                }
            }
            $lists[] = ['list' => $list, 'cards' => $cards];
        }

        return [
            'wall' => $wall,
            'role' => $role,
            'manages' => $role->manages(),
            'owns' => WallRole::Owner === $role,
            'mayAdd' => $this->access->mayAddCard($wall, $user),
            'lists' => $lists,
        ];
    }

    /**
     * The cards of « Mode projection », in the order of the lists then of the cards. A card still
     * awaiting validation is never projected, even by whoever may read it: the projector shows the
     * room what the moderation has not let through yet.
     *
     * @return list<array{card: WallCard, list: WallList}>
     */
    public function slides(Wall $wall): array
    {
        $slides = [];
        foreach ($this->loadLists($wall) as $list) {
            foreach ($this->cardsOf($list) as $card) {
                if (!$card->isPending()) {
                    $slides[] = ['card' => $card, 'list' => $list];
                }
            }
        }

        return $slides;
    }

    /**
     * The people drawn at the top of the wall: the owner, then the named members, and how many
     * more read it through a class.
     *
     * @return array{shown: list<User>, more: int}
     */
    public function people(Wall $wall, int $shown = 3): array
    {
        $named = [$wall->getOwner(), ...$wall->getMembers()->toArray()];
        $others = \count($named) > $shown ? \count($named) - $shown : 0;

        if (!$wall->getPrograms()->isEmpty()) {
            $others += (int) $this->entityManager->createQuery(
                'SELECT COUNT(DISTINCT s.id) FROM App\Entity\Program p JOIN p.students s WHERE p IN (:programs) AND s NOT IN (:named)',
            )->setParameter('programs', $wall->getPrograms()->toArray())->setParameter('named', $named)->getSingleScalarResult();
        }

        return ['shown' => \array_slice($named, 0, $shown), 'more' => $others];
    }

    /** @return list<WallList> */
    private function loadLists(Wall $wall): array
    {
        /** @var list<WallList> $lists */
        $lists = $this->entityManager->createQuery(
            'SELECT l, c, a FROM App\Entity\WallList l LEFT JOIN l.cards c LEFT JOIN c.author a WHERE l.wall = :wall ORDER BY l.position ASC, l.id ASC, c.position ASC, c.id ASC',
        )->setParameter('wall', $wall)->getResult();

        return $lists;
    }

    /**
     * A list's cards in the order of their ranks. Sorted here rather than trusted to the mapping's
     * ORDER BY: a wall is drawn in the very request that moved a card, and a collection already
     * in memory keeps the order it was loaded in whatever the ranks have since become.
     *
     * @return list<WallCard>
     */
    private function cardsOf(WallList $list): array
    {
        $cards = $list->getCards()->toArray();
        usort($cards, static fn (WallCard $a, WallCard $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        return $cards;
    }

    /** @return array<int, int> card id => number of comments */
    private function commentCounts(Wall $wall): array
    {
        /** @var list<array{card: int|string, total: int|string}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT IDENTITY(m.card) AS card, COUNT(m.id) AS total FROM App\Entity\WallComment m JOIN m.card c JOIN c.list l WHERE l.wall = :wall GROUP BY m.card',
        )->setParameter('wall', $wall)->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['card']] = (int) $row['total'];
        }

        return $counts;
    }
}
