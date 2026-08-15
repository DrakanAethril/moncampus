<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentationArticle;
use App\Entity\DocumentationTag;
use App\Repository\DocumentationTagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns the labels typed in the editor's tag field (handoff 2d) into rows of the shared tag
 * referential - the same reading as App\Service\LibraryTagResolver, one vocabulary wider: here the
 * tags belong to the campus, not to a teacher, so "Certifications" typed by two people must land
 * on the same row. That is what App\Entity\DocumentationTag::normalize() decides.
 *
 * Creation on the fly is the point: an author writes the word they mean, and the administration
 * screen (rename/merge/delete) is what keeps the vocabulary from drifting afterwards.
 */
class DocumentationTagResolver
{
    public function __construct(
        private readonly DocumentationTagRepository $tags,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(string $label): ?DocumentationTag
    {
        $label = trim($label);

        if ('' === $label) {
            return null;
        }

        $tag = $this->tags->findOneByLabel($label);

        if (null === $tag) {
            $tag = new DocumentationTag($label);
            $this->entityManager->persist($tag);
        }

        return $tag;
    }

    /**
     * Replaces an article's tags with the ones these labels name, creating what does not exist.
     *
     * @param list<string> $labels
     */
    public function apply(DocumentationArticle $article, array $labels): void
    {
        $wanted = [];

        foreach ($labels as $label) {
            $tag = $this->resolve($label);

            if (null !== $tag) {
                $wanted[DocumentationTag::normalize($tag->getLabel())] = $tag;
            }
        }

        foreach ($article->getTags() as $tag) {
            if (!isset($wanted[$tag->getNormalizedLabel()])) {
                $article->removeTag($tag);
            }
        }

        foreach ($wanted as $tag) {
            $article->addTag($tag);
        }
    }

    /**
     * Moves every article of $source onto $target, then drops $source - the "fusionner" action of
     * the tag administration screen, and the only cure for the duplicates on-the-fly creation
     * invites.
     */
    public function merge(DocumentationTag $source, DocumentationTag $target): void
    {
        if ($source === $target) {
            return;
        }

        foreach ($source->getArticles() as $article) {
            $article->removeTag($source);
            $article->addTag($target);
        }

        $this->entityManager->remove($source);
    }
}
