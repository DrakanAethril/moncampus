<?php

namespace App\Service;

use App\Entity\AbstractLibraryTag;
use App\Entity\User;
use App\Repository\AbstractLibraryTagRepository;
use Doctrine\ORM\EntityManagerInterface;

// Find-or-create for the library's free-text tag fields (Niveau/Option/Bloc de compétences) - see
// App\Entity\AbstractLibraryTag. A submitted label always resolves to a tag: an existing one if
// this teacher already has one with that exact label, otherwise a brand new one, persisted here
// (not flushed - the caller's own flush() picks it up along with whatever it's attaching the tag
// to).
class LibraryTagResolver
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @template T of AbstractLibraryTag
     *
     * @param AbstractLibraryTagRepository<T> $repository
     * @param class-string<T>                 $tagClass
     *
     * @return T|null
     */
    public function resolveOne(AbstractLibraryTagRepository $repository, string $tagClass, User $teacher, ?string $label): ?AbstractLibraryTag
    {
        $label = trim((string) $label);
        if ('' === $label) {
            return null;
        }

        $existing = $repository->findOneByTeacherAndLabel($teacher, $label);
        if (null !== $existing) {
            return $existing;
        }

        $tag = new $tagClass($teacher, $label);
        $this->entityManager->persist($tag);

        return $tag;
    }

    /**
     * @template T of AbstractLibraryTag
     *
     * @param AbstractLibraryTagRepository<T> $repository
     * @param class-string<T>                 $tagClass
     * @param list<string>                    $labels
     *
     * @return list<T>
     */
    public function resolveMany(AbstractLibraryTagRepository $repository, string $tagClass, User $teacher, array $labels): array
    {
        return array_values(array_filter(array_map(
            fn (string $label): ?AbstractLibraryTag => $this->resolveOne($repository, $tagClass, $teacher, $label),
            $labels,
        )));
    }
}
