<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\Program;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\QuizTournament;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\QuizTournamentPalette;
use App\Repository\QuizTournamentDuelRepository;
use App\Service\FileUploadService;
use App\Service\StagedUpload;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creating and editing a quiz tournament (design/validated/quiz-tournament.md §3) - the door the
 * administration screens (App\Controller\Settings\QuizTournamentController) write through.
 *
 * - **Born closed**: create() never opens; open() is the administrator's separate gesture.
 * - **Editable until its first duel**; after that only the name, the end and the targeting move
 *   (isLocked(), and App\Form\QuizTournamentType does not render the rest). Withdrawing a target
 *   withdraws the eligibility, never a line of the standings.
 * - **The reserve must allow a full duel** (refusals()): the sources' questions of the duel formats,
 *   QuizTournamentReserve.
 * - The logo is an image of the uploads bucket under `quiz-tournaments/{id}/`, a new name at each
 *   change so no cached copy survives it; the monogram stands in without one.
 * - **Nothing is deleted once a duel exists** (canDelete()).
 *
 * Nothing here flushes but delete(): the controller flushes once the whole gesture is written.
 *
 * @phpstan-type TournamentFormData array{
 *     name: string|null,
 *     startsAt?: \DateTimeImmutable|null,
 *     endsAt: \DateTimeImmutable|null,
 *     targetSections: iterable<Section>,
 *     targetTracks: iterable<Track>,
 *     targetPrograms: iterable<Program>,
 *     sources?: iterable<QuizTemplate|null>,
 *     questionsPerDuel?: int|null,
 *     maxTrueFalsePerDuel?: int|null,
 *     secondsPerQuestion?: int|null,
 *     secondsPerMultiQuestion?: int|null,
 *     palette?: QuizTournamentPalette|null,
 *     logo?: StagedUpload|null,
 *     removeLogo?: bool|null,
 * }
 * @phpstan-type Refusal array{field: string, key: string, params: array<string, string|int>}
 */
class QuizTournamentEditor
{
    private const string LOGO_PREFIX = 'quiz-tournaments/';

    public function __construct(
        private readonly QuizTournamentDuelRepository $duels,
        private readonly EntityManagerInterface $entityManager,
        private readonly UploadIntake $uploadIntake,
        private readonly FileUploadService $fileUploads,
    ) {
    }

    /** After its first duel, only the name, the end and the targeting move (§3). */
    public function isLocked(QuizTournament $tournament): bool
    {
        return null !== $tournament->getId() && $this->duels->existsForTournament($tournament);
    }

    public function canDelete(QuizTournament $tournament): bool
    {
        return !$this->isLocked($tournament);
    }

    /**
     * What the form opens on: a new tournament carries the duel's defaults and nothing else - no
     * date, no palette, no source (§3, « Défaut : — »).
     *
     * @return array<string, mixed>
     */
    public function formData(?QuizTournament $tournament): array
    {
        if (null === $tournament) {
            return [
                'name' => null,
                'startsAt' => null,
                'endsAt' => null,
                'targetSections' => [],
                'targetTracks' => [],
                'targetPrograms' => [],
                // One row on its placeholder, so the form opens on « choose a quiz » rather than on
                // an empty list with an add button.
                'sources' => [null],
                'questionsPerDuel' => QuizTournament::DEFAULT_QUESTIONS_PER_DUEL,
                'maxTrueFalsePerDuel' => QuizTournament::DEFAULT_MAX_TRUE_FALSE,
                'secondsPerQuestion' => QuizTournament::DEFAULT_SECONDS_PER_QUESTION,
                'secondsPerMultiQuestion' => QuizTournament::DEFAULT_SECONDS_PER_MULTI_QUESTION,
                'palette' => null,
            ];
        }

        return [
            'name' => $tournament->getName(),
            'startsAt' => $tournament->getStartsAt(),
            'endsAt' => $tournament->getEndsAt(),
            'targetSections' => $tournament->getTargetSections()->toArray(),
            'targetTracks' => $tournament->getTargetTracks()->toArray(),
            'targetPrograms' => $tournament->getTargetPrograms()->toArray(),
            'sources' => array_values($tournament->getSources()->toArray()),
            'questionsPerDuel' => $tournament->getQuestionsPerDuel(),
            'maxTrueFalsePerDuel' => $tournament->getMaxTrueFalsePerDuel(),
            'secondsPerQuestion' => $tournament->getSecondsPerQuestion(),
            'secondsPerMultiQuestion' => $tournament->getSecondsPerMultiQuestion(),
            'palette' => $tournament->getPalette(),
        ];
    }

    /**
     * The reserve these quizzes give a duel of this shape - every question of each quiz counted
     * once, whatever the number of rows naming it.
     *
     * @param iterable<QuizTemplate|null> $sources
     */
    public function reserveOf(iterable $sources, int $questionsPerDuel, int $maxTrueFalse): QuizTournamentReserve
    {
        $types = [];
        foreach (self::distinctSources($sources) as $quiz) {
            foreach ($quiz->getQuestions() as $question) {
                /* @var QuizQuestion $question */
                $types[] = $question->getType();
            }
        }

        return QuizTournamentReserve::of($types, $questionsPerDuel, $maxTrueFalse);
    }

    /**
     * The rules that span several fields, each refusal on the field it is read under. Empty when the
     * tournament may be written.
     *
     * @param TournamentFormData $data
     *
     * @return list<Refusal>
     */
    public function refusals(array $data, ?QuizTournament $tournament, bool $locked): array
    {
        $refusals = [];

        $startsAt = $locked ? $tournament?->getStartsAt() : ($data['startsAt'] ?? null);
        $endsAt = $data['endsAt'];
        if (null !== $startsAt && null !== $endsAt && $endsAt <= $startsAt) {
            $refusals[] = ['field' => 'endsAt', 'key' => 'quizTournamentEndBeforeStartError', 'params' => []];
        }

        if ($locked) {
            return $refusals;
        }

        $perDuel = $data['questionsPerDuel'] ?? QuizTournament::DEFAULT_QUESTIONS_PER_DUEL;
        $maxTrueFalse = $data['maxTrueFalsePerDuel'] ?? QuizTournament::DEFAULT_MAX_TRUE_FALSE;
        if ($maxTrueFalse > $perDuel) {
            $refusals[] = ['field' => 'maxTrueFalsePerDuel', 'key' => 'quizTournamentTooManyTrueFalseError', 'params' => ['%max%' => $perDuel]];
        }

        $sources = self::distinctSources($data['sources'] ?? []);
        if ([] === $sources) {
            $refusals[] = ['field' => 'sources', 'key' => 'quizTournamentSourcesRequiredError', 'params' => []];

            return $refusals;
        }

        $reserve = $this->reserveOf($sources, $perDuel, $maxTrueFalse);
        if ($reserve->missing > 0) {
            $refusals[] = ['field' => 'sources', 'key' => 'quizTournamentReserveMissingError', 'params' => ['%count%' => $reserve->missing, '%admitted%' => $reserve->admitted, '%needed%' => $perDuel]];
        }
        if ($reserve->missingNotTrueFalse > 0) {
            $refusals[] = ['field' => 'sources', 'key' => 'quizTournamentReserveMissingNotTrueFalseError', 'params' => ['%count%' => $reserve->missingNotTrueFalse, '%max%' => $maxTrueFalse, '%needed%' => $perDuel - $maxTrueFalse]];
        }

        return $refusals;
    }

    /**
     * A new tournament, closed, from a form whose refusals() came back empty. Persisted, not
     * flushed; the logo is stored once it has an id (storeLogo()).
     *
     * @param TournamentFormData $data
     */
    public function create(array $data, User $by): QuizTournament
    {
        $tournament = new QuizTournament(
            (string) $data['name'],
            $data['startsAt'] ?? throw new \InvalidArgumentException('A tournament needs a start.'),
            $data['endsAt'] ?? throw new \InvalidArgumentException('A tournament needs an end.'),
            $data['palette'] ?? throw new \InvalidArgumentException('A tournament needs a palette.'),
            $by,
        );
        $this->write($tournament, $data, false);
        $this->entityManager->persist($tournament);

        return $tournament;
    }

    /**
     * The edit of an existing tournament: everything, or - locked - the name, the end and the
     * targeting alone.
     *
     * @param TournamentFormData $data
     */
    public function apply(QuizTournament $tournament, array $data, User $by, \DateTimeImmutable $now): void
    {
        $locked = $this->isLocked($tournament);
        $tournament->setName((string) $data['name']);
        if (null !== $data['endsAt']) {
            $tournament->setEndsAt($data['endsAt']);
        }
        if (!$locked) {
            if (null !== ($data['startsAt'] ?? null)) {
                $tournament->setStartsAt($data['startsAt']);
            }
            if (null !== ($data['palette'] ?? null)) {
                $tournament->setPalette($data['palette']);
            }
        }
        $this->write($tournament, $data, $locked);
        $tournament->markUpdated($by, $now);
    }

    /**
     * The uploaded logo, replacing the previous one - whose object is handed to the deferred purge.
     * The tournament must already have an id.
     */
    public function storeLogo(QuizTournament $tournament, StagedUpload $file): void
    {
        $old = $tournament->getLogoKey();
        $key = $this->uploadIntake->store(
            $file,
            self::LOGO_PREFIX.(int) $tournament->getId().'/',
            \sprintf('logo-%s.%s', bin2hex(random_bytes(6)), UploadIntake::extension($file)),
        );
        $tournament->setLogoKey($key);
        if (null !== $old) {
            $this->fileUploads->delete($old);
        }
    }

    public function removeLogo(QuizTournament $tournament): void
    {
        $old = $tournament->getLogoKey();
        $tournament->setLogoKey(null);
        if (null !== $old) {
            $this->fileUploads->delete($old);
        }
    }

    /** Opening is the administrator's gesture that shows a tournament to the people it reaches. */
    public function open(QuizTournament $tournament, User $by, \DateTimeImmutable $now): void
    {
        $tournament->open($by, $now);
    }

    /** Only a tournament that never had a duel; its players, challenges and rounds go with it. */
    public function delete(QuizTournament $tournament): void
    {
        if (!$this->canDelete($tournament)) {
            throw new \LogicException('A tournament that had a duel is never deleted.');
        }

        $logo = $tournament->getLogoKey();
        $this->entityManager->remove($tournament);
        $this->entityManager->flush();
        if (null !== $logo) {
            $this->fileUploads->delete($logo);
        }
    }

    /** @param TournamentFormData $data */
    private function write(QuizTournament $tournament, array $data, bool $locked): void
    {
        self::sync($tournament->getTargetSections()->toArray(), $data['targetSections'], $tournament->addTargetSection(...), $tournament->removeTargetSection(...));
        self::sync($tournament->getTargetTracks()->toArray(), $data['targetTracks'], $tournament->addTargetTrack(...), $tournament->removeTargetTrack(...));
        self::sync($tournament->getTargetPrograms()->toArray(), $data['targetPrograms'], $tournament->addTargetProgram(...), $tournament->removeTargetProgram(...));

        if ($locked) {
            return;
        }

        self::sync($tournament->getSources()->toArray(), self::distinctSources($data['sources'] ?? []), $tournament->addSource(...), $tournament->removeSource(...));
        $tournament
            ->setQuestionsPerDuel($data['questionsPerDuel'] ?? QuizTournament::DEFAULT_QUESTIONS_PER_DUEL)
            ->setMaxTrueFalsePerDuel($data['maxTrueFalsePerDuel'] ?? QuizTournament::DEFAULT_MAX_TRUE_FALSE)
            ->setSecondsPerQuestion($data['secondsPerQuestion'] ?? QuizTournament::DEFAULT_SECONDS_PER_QUESTION)
            ->setSecondsPerMultiQuestion($data['secondsPerMultiQuestion'] ?? QuizTournament::DEFAULT_SECONDS_PER_MULTI_QUESTION);
    }

    /**
     * @template T of object
     *
     * @param array<array-key, T> $current
     * @param iterable<T>         $wanted
     * @param callable(T): mixed  $add
     * @param callable(T): mixed  $remove
     */
    private static function sync(array $current, iterable $wanted, callable $add, callable $remove): void
    {
        $keep = [];
        foreach ($wanted as $item) {
            $keep[spl_object_id($item)] = $item;
        }
        foreach ($current as $item) {
            if (!isset($keep[spl_object_id($item)])) {
                $remove($item);
            }
        }
        foreach ($keep as $item) {
            $add($item);
        }
    }

    /**
     * The quizzes named, once each, in the order of the rows - a row left on its placeholder names
     * nothing.
     *
     * @param iterable<QuizTemplate|null> $sources
     *
     * @return list<QuizTemplate>
     */
    private static function distinctSources(iterable $sources): array
    {
        $distinct = [];
        foreach ($sources as $quiz) {
            if ($quiz instanceof QuizTemplate) {
                $distinct[spl_object_id($quiz)] = $quiz;
            }
        }

        return array_values($distinct);
    }
}
