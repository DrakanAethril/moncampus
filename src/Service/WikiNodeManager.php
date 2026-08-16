<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\Wiki;
use App\Entity\WikiNode;
use App\Enum\WikiNodeType;
use App\Repository\WikiNodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Everything that changes the shape of a wiki: creating, renaming, moving, trashing and restoring
 * nodes. The rules live in App\Service\WikiTree, which knows nothing of Doctrine; this class is
 * what turns them into rows.
 *
 * Moving a subtree is the only operation in the feature that touches more than one row - it
 * rewrites `path` and `depth` on the moved node and on every descendant, found by path prefix
 * rather than by walking parent by parent.
 */
class WikiNodeManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WikiNodeRepository $nodes,
        private readonly WikiTree $tree,
        private readonly WikiBodyText $bodyText,
    ) {
    }

    /**
     * A new wiki is born with one page, "Accueil", carrying a single welcome sentence - never an
     * empty rail. An empty tree gives a first-time user nothing to click and nothing to imitate.
     */
    public function createHomePage(Wiki $wiki, User $author, string $title, string $body): WikiNode
    {
        $node = $this->create($wiki, null, WikiNodeType::Page, $title, $author);
        $this->writeBody($node, $body, $author);

        return $node;
    }

    public function create(Wiki $wiki, ?WikiNode $parent, WikiNodeType $type, string $title, User $author): WikiNode
    {
        $node = new WikiNode(
            $wiki,
            $type,
            $title,
            $this->tree->uniqueSlug($title, $this->nodes->siblingSlugs($wiki, $parent)),
            $author,
        );

        $node->setParent($parent);
        $node->setPosition($this->tree->nextPosition($this->nodes->siblingPositions($wiki, $parent)));
        $this->placeUnder($node, $parent);

        $wiki->addNode($node);
        $wiki->touch();

        $this->entityManager->persist($node);

        return $node;
    }

    public function rename(WikiNode $node, string $title, User $author): void
    {
        $wiki = $node->getWiki();

        if (null === $wiki) {
            return;
        }

        $node->setTitle($title);
        $node->setSlug($this->tree->uniqueSlug($title, $this->nodes->siblingSlugs($wiki, $node->getParent(), $node)));
        $node->touch($author);
        $wiki->touch();
    }

    public function writeBody(WikiNode $node, ?string $body, User $author): void
    {
        $node->setBody($body);
        $node->setBodyText($this->bodyText->fromHtml($body));
        $node->touch($author);
        $node->getWiki()?->touch();
    }

    /**
     * Moves a node under a new parent, at a given rank among its new siblings.
     *
     * Refuses the one move that would detach a branch from its wiki - dropping a folder onto one of
     * its own descendants - rather than letting it produce an unreachable subtree.
     */
    public function move(WikiNode $node, ?WikiNode $newParent, ?int $position = null): bool
    {
        $wiki = $node->getWiki();
        $id = $node->getId();

        if (null === $wiki || null === $id) {
            return false;
        }

        if (null !== $newParent) {
            if ($newParent->getWiki() !== $wiki || $newParent === $node) {
                return false;
            }

            if ($this->tree->isDescendantOf($newParent->getPath(), $id)) {
                return false;
            }
        }

        $this->relocate($node, $newParent);
        $this->reorder($node, $position);
        $wiki->touch();

        return true;
    }

    /**
     * Deleting a node trashes it *and its descendants* - the rail filters them out, and the trash
     * screen restores or purges. Nothing is removed from the database here.
     */
    public function trash(WikiNode $node): void
    {
        $at = new \DateTimeImmutable();
        $node->setDeletedAt($at);

        foreach ($this->nodes->findDescendantsOf($node) as $descendant) {
            $descendant->setDeletedAt($at);
        }

        $node->getWiki()?->touch();
    }

    /**
     * Restores a node and everything under it. A node whose parent is still in the trash comes back
     * at root level rather than into an invisible branch - the alternative is a page the user
     * restored and cannot find.
     */
    public function restore(WikiNode $node): void
    {
        $parent = $node->getParent();
        $descendants = $this->nodes->findDescendantsOf($node);

        $node->setDeletedAt(null);

        foreach ($descendants as $descendant) {
            $descendant->setDeletedAt(null);
        }

        if (null !== $parent && $parent->isDeleted()) {
            // Back at root level rather than into a branch that is still invisible - the
            // alternative is a page the user restored and cannot find.
            $this->relocate($node, null);
            $this->reorder($node, null);
        }

        $node->getWiki()?->touch();
    }

    /**
     * @param list<WikiNode> $nodes
     */
    public function purge(array $nodes): void
    {
        foreach ($nodes as $node) {
            $this->entityManager->remove($node);
        }
    }

    /**
     * The ancestors of a node, nearest last - read straight off the materialized path, which is
     * what it is for.
     *
     * @param array<int, WikiNode> $byId every live node of the wiki, keyed by id
     *
     * @return list<WikiNode>
     */
    public function ancestorsOf(WikiNode $node, array $byId): array
    {
        $ancestors = [];

        foreach (explode('/', trim($node->getPath(), '/')) as $segment) {
            if ('' === $segment) {
                continue;
            }

            $ancestor = $byId[(int) $segment] ?? null;

            if (null !== $ancestor) {
                $ancestors[] = $ancestor;
            }
        }

        return $ancestors;
    }

    private function placeUnder(WikiNode $node, ?WikiNode $parent): void
    {
        $path = null === $parent
            ? $this->tree->rootPath()
            : $this->tree->childPath($parent->getPath(), $parent->getId() ?? 0);

        $node->setPath($path);
        $node->setDepth($this->tree->depthOf($path));
    }

    /**
     * Re-parents a node and re-prefixes its whole subtree - the single operation behind both a
     * drag in the rail and a restore whose parent is still in the trash.
     */
    private function relocate(WikiNode $node, ?WikiNode $newParent): void
    {
        $id = $node->getId();

        if (null === $id) {
            return;
        }

        $oldPrefix = $this->tree->childPath($node->getPath(), $id);
        $descendants = $this->nodes->findDescendantsOf($node);

        $node->setParent($newParent);
        $this->placeUnder($node, $newParent);

        $newPrefix = $this->tree->childPath($node->getPath(), $id);

        foreach ($descendants as $descendant) {
            $path = $this->tree->rewrittenPath($descendant->getPath(), $oldPrefix, $newPrefix);
            $descendant->setPath($path);
            $descendant->setDepth($this->tree->depthOf($path));
        }
    }

    /**
     * Inserts the node at $position among its siblings and renumbers them 1..n, so a drag never
     * leaves two siblings sharing a rank.
     */
    private function reorder(WikiNode $node, ?int $position): void
    {
        $wiki = $node->getWiki();

        if (null === $wiki) {
            return;
        }

        $siblings = [];

        foreach ($this->nodes->findLiveOf($wiki) as $candidate) {
            if ($candidate !== $node && $candidate->getParent() === $node->getParent()) {
                $siblings[] = $candidate;
            }
        }

        usort($siblings, static fn (WikiNode $a, WikiNode $b): int => $a->getPosition() <=> $b->getPosition());

        $index = null === $position ? \count($siblings) : max(0, min($position - 1, \count($siblings)));
        array_splice($siblings, $index, 0, [$node]);

        foreach ($siblings as $rank => $sibling) {
            $sibling->setPosition($rank + 1);
        }
    }
}
