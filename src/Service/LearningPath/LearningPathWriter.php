<?php

declare(strict_types=1);

namespace App\Service\LearningPath;

use App\Entity\LearningPath;
use App\Entity\LearningPathStep;
use App\Entity\OnlineCourse;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\LearningPathStatus;
use App\Repository\LearningPathEnrollmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Everything that writes a learning path - composing it, putting it online, taking it offline
 * (design/validated/cours-en-ligne.md, §10). The author's screens and the Claude connector's tools
 * both call this.
 *
 * The rules held here:
 *
 * - **a path lines up its author's own courses**, and each of them once;
 * - **a validation quiz is optional and goes anywhere**: nothing asks for one between two courses,
 *   and nothing forbids two in a row;
 * - a quiz step points at a quiz of its author's library that has something to ask;
 * - a path goes online with a title and at least one step that can be followed.
 *
 * Nothing here flushes: the caller owns the unit of work.
 */
class LearningPathWriter
{
    public function __construct(
        private readonly LearningPathEnrollmentRepository $enrollments,
        private readonly EntityManagerInterface $entityManager,
        #[Target('app.library_content')] private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function create(User $owner, string $title): LearningPath
    {
        $path = new LearningPath($owner, mb_substr(trim($title), 0, 200));
        $this->entityManager->persist($path);

        return $path;
    }

    public function describe(LearningPath $path, ?string $html): void
    {
        $path->setDescription(null === $html ? null : $this->sanitizer->sanitize($html));
        $path->touch();
    }

    /** A description written in Markdown - what the Claude connector sends. */
    public function describeMarkdown(LearningPath $path, string $markdown): void
    {
        $converter = new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $this->describe($path, '' === trim($markdown) ? null : (string) $converter->convert($markdown));
    }

    /**
     * Sets the whole list of steps at once - the Claude connector's way of composing a path.
     *
     * Checked whole before anything is written: the first step that cannot be taken refuses the
     * list, naming its rank, and the path is left as it was. A step already there is **kept**
     * rather than recreated, so that what its followers did on it survives a reordering; and on a
     * path somebody follows, a list that would drop a step is refused - dropping one erases those
     * traces, which is a gesture made on the screen, where it is announced.
     *
     * @param list<array{course?: OnlineCourse, quiz?: QuizTemplate, passPercent?: int, questionCount?: ?int}> $specs
     *
     * @throws LearningPathRefused
     */
    public function setSteps(LearningPath $path, array $specs): void
    {
        $existing = [];
        foreach ($path->orderedSteps() as $step) {
            $existing[$this->keyOf($step->getCourse(), $step->getQuizTemplate())][] = $step;
        }

        $seenCourses = [];
        $plan = [];
        foreach ($specs as $index => $spec) {
            $rank = (string) ($index + 1);
            $course = $spec['course'] ?? null;
            $quiz = $spec['quiz'] ?? null;

            if (null !== $course) {
                if (!$course->isOwnedBy($path->getOwner())) {
                    throw new LearningPathRefused('learningPathStepRefusedMessage', ['%rank%' => $rank, '%reason%' => 'learningPathCourseNotOwnMessage']);
                }
                if (isset($seenCourses[(int) $course->getId()])) {
                    throw new LearningPathRefused('learningPathStepRefusedMessage', ['%rank%' => $rank, '%reason%' => 'learningPathCourseTwiceMessage']);
                }
                $seenCourses[(int) $course->getId()] = true;
            } elseif (null !== $quiz) {
                if ($quiz->getTeacher() !== $path->getOwner()) {
                    throw new LearningPathRefused('learningPathStepRefusedMessage', ['%rank%' => $rank, '%reason%' => 'learningPathQuizNotOwnMessage']);
                }
                if ($quiz->getQuestions()->isEmpty()) {
                    throw new LearningPathRefused('learningPathStepRefusedMessage', ['%rank%' => $rank, '%reason%' => 'learningPathQuizEmptyMessage']);
                }
                $passPercent = $spec['passPercent'] ?? LearningPathStep::DEFAULT_PASS_PERCENT;
                if ($passPercent < 1 || $passPercent > 100) {
                    throw new LearningPathRefused('learningPathStepRefusedMessage', ['%rank%' => $rank, '%reason%' => 'learningPathThresholdRangeMessage']);
                }
            } else {
                throw new LearningPathRefused('learningPathStepRefusedMessage', ['%rank%' => $rank, '%reason%' => 'learningPathChooseCourseMessage']);
            }

            $key = $this->keyOf($course, $quiz);
            $kept = [] !== ($existing[$key] ?? []) ? array_shift($existing[$key]) : null;
            $plan[] = ['spec' => $spec, 'kept' => $kept];
        }

        $dropped = array_merge(...array_values($existing));
        if ([] !== $dropped && $this->followerCount($path) > 0) {
            throw new LearningPathRefused('learningPathFollowedStepsKeptMessage');
        }

        foreach ($dropped as $step) {
            $path->removeStep($step);
            $this->entityManager->remove($step);
        }

        foreach ($plan as $position => $item) {
            $spec = $item['spec'];
            $step = $item['kept'];
            if (null === $step) {
                $step = isset($spec['course'])
                    ? LearningPathStep::forCourse($path, $spec['course'])
                    : LearningPathStep::forQuiz($path, $spec['quiz'] ?? throw new \LogicException('A step is a course or a quiz.'));
                $path->addStep($step);
                $this->entityManager->persist($step);
            }
            if ($step->isQuiz()) {
                $step->setPassPercent($spec['passPercent'] ?? LearningPathStep::DEFAULT_PASS_PERCENT);
                $step->setQuestionCount($spec['questionCount'] ?? null);
            }
            $step->setPosition($position);
        }

        $path->touch();
    }

    /**
     * @throws LearningPathRefused
     */
    public function addCourse(LearningPath $path, OnlineCourse $course): LearningPathStep
    {
        if (!$course->isOwnedBy($path->getOwner())) {
            throw new LearningPathRefused('learningPathCourseNotOwnMessage');
        }

        foreach ($path->getSteps() as $existing) {
            if ($existing->getCourse() === $course) {
                throw new LearningPathRefused('learningPathCourseTwiceMessage', ['%course%' => $course->getTitle()]);
            }
        }

        return $this->append($path, LearningPathStep::forCourse($path, $course));
    }

    /**
     * @throws LearningPathRefused
     */
    public function addQuiz(LearningPath $path, QuizTemplate $quiz, int $passPercent = LearningPathStep::DEFAULT_PASS_PERCENT, ?int $questionCount = null): LearningPathStep
    {
        if ($quiz->getTeacher() !== $path->getOwner()) {
            throw new LearningPathRefused('learningPathQuizNotOwnMessage');
        }

        if ($quiz->getQuestions()->isEmpty()) {
            throw new LearningPathRefused('learningPathQuizEmptyMessage', ['%quiz%' => (string) $quiz->getName()]);
        }

        self::assertThreshold($passPercent);

        return $this->append($path, LearningPathStep::forQuiz($path, $quiz, $passPercent, $questionCount));
    }

    /**
     * @throws LearningPathRefused
     */
    public function configureQuiz(LearningPathStep $step, int $passPercent, ?int $questionCount): void
    {
        if (!$step->isQuiz()) {
            return;
        }

        self::assertThreshold($passPercent);
        $step->setPassPercent($passPercent);
        $step->setQuestionCount($questionCount);
        $step->getPath()->touch();
    }

    /**
     * Removes a step - and, with it, what its followers did on it: the dates they opened it, their
     * attempts. The screen says so before it asks.
     */
    public function removeStep(LearningPathStep $step): void
    {
        $path = $step->getPath();
        $path->removeStep($step);
        $this->entityManager->remove($step);
        $this->renumber($path);
        $path->touch();
    }

    /**
     * The order the steps are followed in. Ids that are not this path's steps are ignored, and a
     * step left out keeps its place after the named ones.
     *
     * @param array<array-key, int> $orderedIds
     */
    public function reorder(LearningPath $path, array $orderedIds): void
    {
        $rank = array_flip(array_values($orderedIds));
        $steps = $path->orderedSteps();

        usort($steps, static fn (LearningPathStep $a, LearningPathStep $b): int => [$rank[$a->getId()] ?? \PHP_INT_MAX, $a->getPosition()] <=> [$rank[$b->getId()] ?? \PHP_INT_MAX, $b->getPosition()]);

        foreach ($steps as $position => $step) {
            $step->setPosition($position);
        }

        $path->touch();
    }

    /**
     * What stands between this path and going online, as translation keys.
     *
     * @return list<string>
     */
    public function publishRefusals(LearningPath $path): array
    {
        $reasons = [];

        if ('' === trim($path->getTitle())) {
            $reasons[] = 'learningPathPublishNeedsTitleMessage';
        }

        $available = array_filter($path->orderedSteps(), static fn (LearningPathStep $step): bool => $step->isAvailable());
        if ([] === $available) {
            $reasons[] = 'learningPathPublishNeedsStepMessage';
        }

        return $reasons;
    }

    /**
     * @throws LearningPathRefused
     */
    public function publish(LearningPath $path): void
    {
        $reasons = $this->publishRefusals($path);
        if ([] !== $reasons) {
            throw new LearningPathRefused($reasons[0]);
        }

        $path->setStatus(LearningPathStatus::Published);
        $path->touch();
    }

    /** Back to a draft: nobody new starts it, and the follow-up of those who did is kept. */
    public function unpublish(LearningPath $path): void
    {
        $path->setStatus(LearningPathStatus::Draft);
        $path->touch();
    }

    /** The caller has asked the Voter: only a draft gets here. The follow-up goes with the path. */
    public function delete(LearningPath $path): void
    {
        $this->entityManager->remove($path);
    }

    public function followerCount(LearningPath $path): int
    {
        return null === $path->getId() ? 0 : $this->enrollments->countForPath($path);
    }

    private function append(LearningPath $path, LearningPathStep $step): LearningPathStep
    {
        $step->setPosition($path->getSteps()->count());
        $path->addStep($step);
        $this->entityManager->persist($step);
        $path->touch();

        return $step;
    }

    private function keyOf(?OnlineCourse $course, ?QuizTemplate $quiz): string
    {
        return null !== $course ? 'course:'.$course->getId() : 'quiz:'.$quiz?->getId();
    }

    private function renumber(LearningPath $path): void
    {
        foreach ($path->orderedSteps() as $position => $step) {
            $step->setPosition($position);
        }
    }

    private static function assertThreshold(int $passPercent): void
    {
        if ($passPercent < 1 || $passPercent > 100) {
            throw new LearningPathRefused('learningPathThresholdRangeMessage');
        }
    }
}
