<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentationArticle;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\DocumentationArticleRepository;
use App\Repository\DocumentationTagRepository;

/**
 * What the documentation base's home page and its pages de garde show (handoff 2a/2b): the pinned
 * articles, the recent ones, and the tags actually worn by the articles in view.
 *
 * The two screens are one method because they are one screen: 2b is 2a narrowed to a section of
 * the perimeter, "une section affichant ses propres articles et ceux de ses sous-sections" - the
 * only difference is the branch of the hierarchy the query starts from.
 *
 * @phpstan-import-type TagUsageRow from DocumentationTagRepository
 *
 * @phpstan-type Board array{articles: list<DocumentationArticle>, pinned: list<DocumentationArticle>, recent: list<DocumentationArticle>, tags: list<TagUsageRow>, filtered: bool}
 */
class DocumentationBoard
{
    public function __construct(
        private readonly DocumentationArticleRepository $articles,
        private readonly DocumentationTagRepository $tags,
        private readonly DocumentationAccess $access,
        private readonly DocumentationPerimeter $perimeter,
    ) {
    }

    /**
     * @param int|null $recentLimit how many non-pinned articles to keep, null for all of them
     *
     * @return Board
     */
    public function build(?User $user, ?Group $scope, ?int $tagId = null, ?string $search = null, ?int $recentLimit = null): array
    {
        $roles = null === $user ? [] : $user->getRoles();
        $isManager = $this->access->isManagerRole($roles);
        $scopeId = $scope?->getId();

        $candidates = $this->articles->findCandidates(
            scopeGroupIds: null === $scopeId ? null : $this->perimeter->branchIds($scopeId),
            // A manager reads the whole base, so their own groups must not narrow it - and they
            // see drafts and archived articles too, which is what the null below turns on.
            readerGroupIds: $isManager ? null : $this->perimeter->readerGroupIds($user),
            tagId: $tagId,
            search: $search,
            includeUnpublished: $isManager,
        );

        $readable = $isManager
            ? $candidates
            : $this->access->filter($candidates, $this->perimeter->readerGroupIds($user), $roles);

        $pinned = [];
        $recent = [];

        foreach ($readable as $article) {
            if ($article->isPinned()) {
                $pinned[] = $article;
            } else {
                $recent[] = $article;
            }
        }

        if (null !== $recentLimit) {
            $recent = \array_slice($recent, 0, $recentLimit);
        }

        $filtered = null !== $tagId || (null !== $search && '' !== $search);

        // The rail lists the tags of the *section*, not of the current filter - built from the
        // unfiltered set, or picking a tag would leave the rail showing only that tag.
        $railArticles = $readable;

        if ($filtered) {
            $unfiltered = $this->articles->findCandidates(
                scopeGroupIds: null === $scopeId ? null : $this->perimeter->branchIds($scopeId),
                readerGroupIds: $isManager ? null : $this->perimeter->readerGroupIds($user),
                includeUnpublished: $isManager,
            );

            $railArticles = $isManager
                ? $unfiltered
                : $this->access->filter($unfiltered, $this->perimeter->readerGroupIds($user), $roles);
        }

        return [
            'articles' => $readable,
            'pinned' => $pinned,
            'recent' => $recent,
            'tags' => $this->tagsOf($railArticles),
            'filtered' => $filtered,
        ];
    }

    /**
     * The tag rail of the left column - only the tags of the articles in view, so a tag never
     * offers a filter that would come back empty.
     *
     * @param list<DocumentationArticle> $articles
     *
     * @return list<TagUsageRow>
     */
    private function tagsOf(array $articles): array
    {
        $ids = [];

        foreach ($articles as $article) {
            $id = $article->getId();

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $this->tags->findUsedByArticles($ids);
    }
}
