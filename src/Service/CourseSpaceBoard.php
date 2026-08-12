<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LibraryResourceInstance;
use App\Entity\Program;
use App\Entity\SeanceInstance;
use App\Entity\SequenceInstance;
use App\Entity\User;
use App\Repository\LibraryResourceInstanceViewRepository;
use App\Repository\SequenceInstanceRepository;
use App\Security\StructureAccessChecker;

/**
 * What one reader may see of a Program's sequences - the material the course space screens are
 * drawn from, named after its neighbours StudentWorkBoard and LessonLogBoard.
 *
 * Nothing here is persisted: what is readable is a reading of the visibility fields against the
 * clock, so it is always current and never has to be maintained. The whole rule lives here rather
 * than in the controller, which is what lets the web screens and the mobile API answer identically -
 * the same stance AudioListenTracker takes for its two players.
 *
 * "As a teacher" is resolved once per call through StructureAccessChecker rather than passed in:
 * a caller that could choose would eventually choose wrong, and the difference decides whether
 * unpublished content is returned.
 */
class CourseSpaceBoard
{
    public function __construct(
        private readonly SequenceInstanceRepository $sequenceInstanceRepository,
        private readonly LibraryResourceInstanceViewRepository $viewRepository,
        private readonly StructureAccessChecker $accessChecker,
    ) {
    }

    /**
     * The sequences of a Program this reader may open, in the repository's own order.
     *
     * @return list<SequenceInstance>
     */
    public function sequencesFor(Program $program, ?\DateTimeImmutable $now = null): array
    {
        return $this->readable($this->sequenceInstanceRepository->findForProgram($program), $this->readsUnpublished($program), $now);
    }

    /**
     * The séances of a sequence this reader may open.
     *
     * A séance held back inside a published sequence simply does not come back - the course space
     * shows no placeholder for it, exactly as an unpublished sequence is absent rather than greyed.
     * Greying belongs to access conditions, which say what to do to open something; an unpublished
     * séance has no such answer.
     *
     * @return list<SeanceInstance>
     */
    public function seancesFor(SequenceInstance $sequence, ?\DateTimeImmutable $now = null): array
    {
        $program = $sequence->getProgram();

        return $this->readable(
            $sequence->getSeanceInstances()->toArray(),
            null === $program || $this->readsUnpublished($program),
            $now,
        );
    }

    /**
     * The resources of a séance, its own and those of its phases, flattened into one list.
     *
     * Flattened on purpose: phases are the teacher's choreography and are never shown, so their
     * handouts would otherwise be unreachable for a student. A teacher gets everything, since the
     * per-resource flag is what they are about to edit.
     *
     * @return list<LibraryResourceInstance>
     */
    public function resourcesFor(SeanceInstance $seance): array
    {
        $resources = $seance->getLibraryResourceInstances()->toArray();

        foreach ($seance->getSeancePhaseInstances() as $phase) {
            foreach ($phase->getLibraryResourceInstances() as $resource) {
                $resources[] = $resource;
            }
        }

        $program = $seance->getProgram();
        if (null !== $program && $this->readsUnpublished($program)) {
            return array_values($resources);
        }

        return array_values(array_filter(
            $resources,
            static fn (LibraryResourceInstance $resource): bool => $resource->isStudentVisible(),
        ));
    }

    /**
     * Which of these resources the student has already opened, as a set keyed by id.
     *
     * One query for a whole séance: painting an "opened" marker per row is precisely where a screen
     * turns into one query per resource.
     *
     * @param list<LibraryResourceInstance> $resources
     *
     * @return array<int, true>
     */
    public function openedResourceIds(array $resources, User $student): array
    {
        $opened = [];
        foreach ($this->viewRepository->openedResourceIdsFor($resources, $student) as $id) {
            $opened[$id] = true;
        }

        return $opened;
    }

    /**
     * Teaching staff read what is not published yet; everyone else waits for publication. Same
     * split as SequenceInstanceVoter, and deliberately the same two calls in the same order.
     */
    private function readsUnpublished(Program $program): bool
    {
        return $this->accessChecker->isStaff() || $this->accessChecker->isProgramTeacher($program);
    }

    /**
     * @template T of SequenceInstance|SeanceInstance
     *
     * @param array<array-key, T> $content
     *
     * @return list<T>
     */
    private function readable(array $content, bool $readsUnpublished, ?\DateTimeImmutable $now): array
    {
        if ($readsUnpublished) {
            return array_values($content);
        }

        return array_values(array_filter(
            $content,
            static fn (SequenceInstance|SeanceInstance $one): bool => $one->isVisibleToStudentsAt($now),
        ));
    }
}
