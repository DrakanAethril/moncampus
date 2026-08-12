<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AccessConditionType;
use App\Repository\AssignmentRepository;
use App\Repository\AudioRecordingRepository;
use App\Repository\GroupRepository;
use App\Repository\LibraryResourceInstanceRepository;
use App\Repository\QuizInstanceRepository;
use App\Repository\SeanceInstanceRepository;
use App\Repository\VideoResourceRepository;
use App\Security\StructureAccessChecker;

/**
 * What the objects behind a handful of unmet leaves are called, when this reader is entitled to
 * know they exist - the query half of AccessConditionNames.
 *
 * Only ever called on the leaves of a locked object that actually failed, which is what keeps it
 * affordable: a screen where everything is open resolves no name at all, and a locked row resolves
 * the one or two objects it names. One query per type all the same, because a screen can very well
 * lock ten rows on the same missing deposit.
 *
 * "Entitled to know" is deliberately coarse: the referenced object's Program has to be one the
 * reader can reach, and a resource additionally has to be published. Anything finer would mean
 * evaluating somebody else's screen to draw a grey line, and the fallback sentence is a perfectly
 * good answer.
 */
class AccessConditionNameResolver
{
    public function __construct(
        private readonly QuizInstanceRepository $quizInstanceRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly LibraryResourceInstanceRepository $resourceRepository,
        private readonly AudioRecordingRepository $audioRecordingRepository,
        private readonly VideoResourceRepository $videoResourceRepository,
        private readonly SeanceInstanceRepository $seanceRepository,
        private readonly GroupRepository $groupRepository,
        private readonly StructureAccessChecker $accessChecker,
        private readonly AssignmentAudienceResolver $audienceResolver,
    ) {
    }

    /** @param list<AccessConditionLeaf> $leaves */
    public function resolve(array $leaves, User $reader): AccessConditionNames
    {
        $idsByType = [];
        foreach ($leaves as $leaf) {
            if (null !== $leaf->targetId) {
                $idsByType[$leaf->type->value][$leaf->targetId] = $leaf->targetId;
            }
        }

        $names = [];
        foreach ($idsByType as $type => $ids) {
            $names[$type] = $this->namesOf(AccessConditionType::from($type), array_values($ids), $reader);
        }

        return new AccessConditionNames($names);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function namesOf(AccessConditionType $type, array $ids, User $reader): array
    {
        return match ($type) {
            AccessConditionType::QuizScore => $this->named(
                $this->quizInstanceRepository->findBy(['id' => $ids]),
                fn ($instance): bool => $this->readsProgram($instance->getProgram()),
                static fn ($instance): string => (string) $instance->getName(),
            ),
            AccessConditionType::AssignmentDone => $this->named(
                $this->assignmentRepository->findBy(['id' => $ids]),
                fn (Assignment $assignment): bool => $this->readsProgram($assignment->getProgram())
                    && ($this->accessChecker->isStaff() || $this->audienceResolver->isInAudience($assignment, $reader)),
                static fn (Assignment $assignment): string => (string) $assignment->getTitle(),
            ),
            AccessConditionType::ResourceViewed => $this->named(
                $this->resourceRepository->findBy(['id' => $ids]),
                fn ($resource): bool => $this->readsProgram($resource->getAccessConditionProgram())
                    && ($resource->isStudentVisible() || $this->accessChecker->isStaff()),
                static fn ($resource): string => (string) $resource->getLabel(),
            ),
            AccessConditionType::AudioListened => $this->named(
                $this->audioRecordingRepository->findBy(['id' => $ids]),
                fn ($recording): bool => $this->readsProgram($recording->getProgram()),
                static fn ($recording): string => (string) $recording->getName(),
            ),
            AccessConditionType::VideoWatched => $this->named(
                $this->videoResourceRepository->findBy(['id' => $ids]),
                fn ($resource): bool => $this->readsProgram($resource->getProgram()),
                static fn ($resource): string => (string) $resource->getName(),
            ),
            AccessConditionType::SeancePassed => $this->named(
                array_values($this->seanceRepository->findWithSlotByIds($ids)),
                fn ($seance): bool => $this->readsProgram($seance->getProgram()),
                static fn ($seance): string => (string) $seance->getTitre(),
            ),
            // A group is named unconditionally: knowing a group exists reveals nothing, and
            // "réservé à un autre groupe" would be less honest, not more discreet.
            AccessConditionType::Group => $this->named(
                $this->groupRepository->findBy(['id' => $ids]),
                static fn ($group): bool => true,
                static fn ($group): string => $group->getName(),
            ),
            AccessConditionType::DateFrom => [],
        };
    }

    /**
     * @param array<array-key, object> $objects
     * @param callable(object): bool   $isReadable
     * @param callable(object): string $name
     *
     * @return array<int, string>
     */
    private function named(array $objects, callable $isReadable, callable $name): array
    {
        $names = [];
        foreach ($objects as $object) {
            /** @var int|null $id */
            $id = method_exists($object, 'getId') ? $object->getId() : null;

            if (null !== $id && $isReadable($object)) {
                $names[$id] = $name($object);
            }
        }

        return $names;
    }

    private function readsProgram(?Program $program): bool
    {
        return null !== $program && $this->accessChecker->isProgramVisible($program);
    }
}
