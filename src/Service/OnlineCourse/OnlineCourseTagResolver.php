<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCourseTag;
use App\Entity\User;
use App\Repository\OnlineCourseTagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns the labels typed in a course's tag field into rows of its author's own vocabulary
 * (design/validated/cours-en-ligne.md, §9) - the reading App\Service\DocumentationTagResolver makes
 * for the documentation, one vocabulary narrower: here a tag belongs to an author.
 *
 * Creation on the fly is the point. « SQL » typed on a second course lands on the row the first
 * one created, whatever its case or accents, because the lookup is on the normalized label.
 */
class OnlineCourseTagResolver
{
    /** A course is filtered by a handful of words, not indexed by a paragraph of them. */
    public const int MAX_TAGS = 12;

    public function __construct(
        private readonly OnlineCourseTagRepository $tags,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Replaces a course's tags with the ones these labels name, creating what does not exist.
     *
     * @param array<array-key, string> $labels
     */
    public function apply(OnlineCourse $course, array $labels): void
    {
        $owner = $course->getOwner();
        $wanted = [];

        foreach ($labels as $label) {
            $normalized = OnlineCourseTag::normalize($label);
            if ('' === $normalized || isset($wanted[$normalized]) || \count($wanted) >= self::MAX_TAGS) {
                continue;
            }

            $wanted[$normalized] = $this->resolve($owner, $label);
        }

        foreach ($course->getTags()->toArray() as $tag) {
            if (!isset($wanted[$tag->getNormalizedLabel()])) {
                $course->removeTag($tag);
            }
        }

        foreach ($wanted as $tag) {
            $course->addTag($tag);
        }
    }

    /**
     * The labels of a course, one per line - what the hidden field of the form holds.
     */
    public static function fieldValue(OnlineCourse $course): string
    {
        return implode("\n", array_map(static fn (OnlineCourseTag $tag): string => $tag->getLabel(), $course->getTags()->toArray()));
    }

    /**
     * @return list<string>
     */
    public static function labelsOf(string $fieldValue): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\R/u', $fieldValue) ?: []), static fn (string $label): bool => '' !== $label));
    }

    private function resolve(User $owner, string $label): OnlineCourseTag
    {
        $tag = $this->tags->findOneByOwnerAndLabel($owner, $label);

        if (null === $tag) {
            $tag = new OnlineCourseTag($owner, $label);
            $this->entityManager->persist($tag);
        }

        return $tag;
    }
}
