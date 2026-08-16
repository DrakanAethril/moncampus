<?php

declare(strict_types=1);

namespace App\Controller\Wiki;

use App\Entity\Wiki;
use App\Entity\WikiNode;
use App\Security\Voter\WikiVoter;
use App\Service\PostValue;
use Symfony\Component\HttpFoundation\Request;

/**
 * The handful of things every wiki controller does: open a wiki after checking who is asking,
 * assemble the rail, and read a CSRF token off a POST.
 *
 * Deliberately small - the access rule itself is App\Service\WikiAccess's and the tree's shape is
 * App\Service\WikiTree's; this only spares six controllers the same four lines.
 */
trait WikiTrait
{
    /**
     * Opens a wiki for reading. WIKI_VIEW is a documented alias of WIKI_EDIT today - whoever may
     * see a wiki may write in it - so this is the only door the read screens need.
     */
    private function loadWiki(int $id): Wiki
    {
        $wiki = $this->wikis->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(WikiVoter::VIEW, $wiki);

        return $wiki;
    }

    /** A node has to belong to the wiki in its own URL, or /wiki/1/p/9999 reads another wiki's page. */
    private function loadNode(Wiki $wiki, int $nodeId): WikiNode
    {
        $node = $this->nodes->find($nodeId);

        if (null === $node || $node->getWiki() !== $wiki) {
            throw $this->createNotFoundException();
        }

        return $node;
    }

    /**
     * The rail: the whole wiki in one query, nested in PHP by (parent, position).
     *
     * @return array{tree: list<array<string, mixed>>, byId: array<int, WikiNode>}
     */
    private function rail(Wiki $wiki): array
    {
        $nodes = $this->nodes->findLiveOf($wiki);
        $byId = [];
        $rows = [];

        foreach ($nodes as $node) {
            $id = $node->getId();

            if (null === $id) {
                continue;
            }

            $byId[$id] = $node;
            $rows[] = [
                'id' => $id,
                'parentId' => $node->getParent()?->getId(),
                'position' => $node->getPosition(),
                'node' => $node,
            ];
        }

        return ['tree' => $this->tree->assemble($rows), 'byId' => $byId];
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
