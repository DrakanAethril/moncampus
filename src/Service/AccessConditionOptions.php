<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccessConditionHost;
use App\Entity\Group;
use App\Entity\Program;
use App\Entity\SeanceInstance;
use App\Enum\AccessConditionType;
use App\Repository\AssignmentRepository;
use App\Repository\AudioRecordingRepository;
use App\Repository\GroupRepository;
use App\Repository\LibraryResourceInstanceRepository;
use App\Repository\QuizInstanceRepository;
use App\Repository\SeanceInstanceRepository;
use App\Repository\VideoResourceRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the teacher's form can point a condition at, for one class - one list per leaf type, handed
 * to the screen as JSON so that changing the type in the select swaps the objects without a round
 * trip.
 *
 * A séance carries its resolved date with it: "ce que l'enseignant a choisi (la séance) et ce que
 * cela vaut maintenant (le jeudi 18 à 16:00)". That second line is what makes it visible that the
 * condition will follow the slot if the timetable moves - and, when there is no slot yet, that
 * nothing will open until there is one.
 *
 * The object being edited is left out of its own list: a work unlocked by itself is the shortest
 * cycle there is, and it is friendlier not to offer it than to refuse it afterwards.
 */
class AccessConditionOptions
{
    public function __construct(
        private readonly SeanceInstanceRepository $seanceRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly QuizInstanceRepository $quizInstanceRepository,
        private readonly LibraryResourceInstanceRepository $resourceRepository,
        private readonly AudioRecordingRepository $audioRecordingRepository,
        private readonly VideoResourceRepository $videoResourceRepository,
        private readonly GroupRepository $groupRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, list<array{id: int, label: string, note?: string}>>
     */
    public function forProgram(Program $program, AccessConditionHost $edited): array
    {
        $editedKey = AccessConditionHostKey::of($edited);

        return [
            AccessConditionType::SeancePassed->value => array_map(
                fn (SeanceInstance $seance): array => [
                    'id' => (int) $seance->getId(),
                    'label' => $this->seanceLabel($seance),
                    'note' => $this->slotNote($seance),
                ],
                $this->seanceRepository->findForProgram($program),
            ),
            AccessConditionType::AssignmentDone->value => $this->options(
                $this->assignmentRepository->findForProgram($program),
                static fn ($assignment): string => (string) $assignment->getTitle(),
                AccessConditionHostKey::ASSIGNMENT,
                $editedKey,
            ),
            AccessConditionType::QuizScore->value => $this->options(
                $this->quizInstanceRepository->findForProgram($program),
                static fn ($instance): string => (string) $instance->getName(),
                AccessConditionHostKey::QUIZ_INSTANCE,
                $editedKey,
            ),
            AccessConditionType::ResourceViewed->value => $this->options(
                $this->resourceRepository->findForProgram($program),
                static fn ($resource): string => (string) $resource->getLabel(),
                AccessConditionHostKey::RESOURCE,
                $editedKey,
            ),
            AccessConditionType::AudioListened->value => $this->options(
                $this->audioRecordingRepository->findForPrograms([$program]),
                static fn ($recording): string => (string) $recording->getName(),
                null,
                $editedKey,
            ),
            AccessConditionType::VideoWatched->value => $this->options(
                $this->videoResourceRepository->findForProgram($program),
                static fn ($resource): string => (string) $resource->getName(),
                null,
                $editedKey,
            ),
            AccessConditionType::Group->value => $this->options(
                $this->groupRepository->findBy(['inactiveDate' => null], ['name' => 'ASC']),
                static fn (Group $group): string => $group->getName(),
                null,
                $editedKey,
            ),
            AccessConditionType::DateFrom->value => [],
        ];
    }

    /**
     * @param array<array-key, object>  $objects
     * @param callable(object): string  $label
     *
     * @return list<array{id: int, label: string}>
     */
    private function options(array $objects, callable $label, ?string $type, string $editedKey): array
    {
        $options = [];
        foreach ($objects as $object) {
            /** @var int|null $id */
            $id = method_exists($object, 'getId') ? $object->getId() : null;

            if (null === $id || (null !== $type && AccessConditionHostKey::forType($type, $id) === $editedKey)) {
                continue;
            }

            $options[] = ['id' => $id, 'label' => $label($object)];
        }

        return $options;
    }

    private function seanceLabel(SeanceInstance $seance): string
    {
        $sequence = $seance->getSequenceInstance()?->getTitre();
        $title = \sprintf('%d — %s', $seance->getOrdre(), (string) $seance->getTitre());

        return null === $sequence ? $title : \sprintf('%s · %s', $sequence, $title);
    }

    /** Today's resolution of "après la séance", or why it resolves to nothing yet. */
    private function slotNote(SeanceInstance $seance): string
    {
        $endAt = $seance->getLessonSession()?->getEndAt();

        return null === $endAt
            ? $this->translator->trans('accessConditionSeanceUnscheduledNote')
            : $this->translator->trans('accessConditionSeanceSlotNote', ['%date%' => $endAt->format('d/m/Y H:i')]);
    }
}
