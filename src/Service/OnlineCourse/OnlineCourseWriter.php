<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\OnlineCourseStatus;
use App\Repository\OnlineCoursePageRepository;
use App\Repository\OnlineCourseRepository;
use App\Repository\OnlineCourseTagRepository;
use App\Service\HelpSlug;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Everything that writes an online course - creating it, describing it, putting it online, taking
 * it offline, deleting it (design/validated/cours-en-ligne.md, §8). The author's screens and the
 * Claude connector's tools both call this, so a rule held here is held for both.
 *
 * The rules:
 *
 * - **A course is born a draft**, whoever creates it. Publishing is a gesture of its own.
 * - **Publishing asks for a title, a summary, at least one material, and a page with an address.**
 *   publishRefusals() names everything missing at once rather than the first thing.
 * - **A published course is taken offline before it is deleted** (the Voter says so too).
 * - The description is HTML through the library's sanitizer, whatever wrote it.
 * - **The test quiz is a quiz of the author's own library that has something to ask** - the rule
 *   a path's quiz step holds (App\Service\LearningPath\LearningPathWriter).
 *
 * Nothing here flushes: the caller owns the unit of work.
 */
class OnlineCourseWriter
{
    public function __construct(
        private readonly OnlineCourseRepository $courses,
        private readonly OnlineCoursePageRepository $pages,
        private readonly OnlineCourseTagRepository $tags,
        private readonly OnlineCourseTagResolver $tagResolver,
        private readonly OnlineCourseMaterialStore $materials,
        private readonly OnlineCourseImageStore $images,
        private readonly EntityManagerInterface $entityManager,
        private readonly HelpSlug $slug,
        #[Target('app.library_content')] private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function create(User $owner, string $title, ?string $slug = null): OnlineCourse
    {
        $title = mb_substr(trim($title), 0, 200);
        $course = new OnlineCourse($owner, $title, $this->availableSlug($owner, $slug ?? $title));
        $this->entityManager->persist($course);

        return $course;
    }

    /**
     * What somebody typed - a title or an address - as a slug this author does not use yet: the
     * second « Introduction » becomes `introduction-2` rather than a refusal.
     */
    public function availableSlug(User $owner, string $wanted, ?OnlineCourse $except = null): string
    {
        $base = mb_substr($this->slug->from($wanted), 0, 110);
        if ('' === $base) {
            $base = 'cours';
        }

        $taken = array_flip($this->courses->findSlugsForOwner($owner, $except));
        $slug = $base;
        for ($suffix = 2; isset($taken[$slug]); ++$suffix) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    /** What a hand-typed address becomes, without numbering it: the form says when it is taken. */
    public function normalizeSlug(string $raw): string
    {
        return mb_substr($this->slug->from($raw), 0, 120);
    }

    public function describe(OnlineCourse $course, ?string $html): void
    {
        $course->setDescription(null === $html ? null : $this->sanitizer->sanitize($html));
        $course->touch();
    }

    /**
     * A description written in Markdown - what the Claude connector sends. Rendered by the same
     * converter as a handout written by Claude (App\Service\CourseMaterialRenderer), raw HTML
     * escaped, then through the same sanitizer as a description typed on the screen.
     */
    public function describeMarkdown(OnlineCourse $course, string $markdown): void
    {
        if ('' === trim($markdown)) {
            $this->describe($course, null);

            return;
        }

        $converter = new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $this->describe($course, (string) $converter->convert($markdown));
    }

    /**
     * @param array<array-key, string> $labels
     */
    public function tag(OnlineCourse $course, array $labels): void
    {
        $this->tagResolver->apply($course, $labels);
        $course->touch();
    }

    /**
     * Links the quiz « Test » launches, or unlinks it with null. Linking one to a published course
     * puts it online at once: whoever may read the course may take it.
     *
     * @throws OnlineCourseQuizRefused
     */
    public function linkQuiz(OnlineCourse $course, ?QuizTemplate $quiz): void
    {
        if (null !== $quiz) {
            if ($quiz->getTeacher() !== $course->getOwner()) {
                throw new OnlineCourseQuizRefused('onlineCourseQuizNotOwnMessage');
            }
            if ($quiz->getQuestions()->isEmpty()) {
                throw new OnlineCourseQuizRefused('onlineCourseQuizEmptyMessage', ['%quiz%' => (string) $quiz->getName()]);
            }
        }

        if ($course->getQuizTemplate() !== $quiz) {
            $course->setQuizTemplate($quiz);
            $course->touch();
        }
    }

    /**
     * What stands between this course and going online, as translation keys. Empty when nothing
     * does.
     *
     * @return list<string>
     */
    public function publishRefusals(OnlineCourse $course): array
    {
        $reasons = [];

        if ('' === trim($course->getTitle())) {
            $reasons[] = 'onlineCoursePublishNeedsTitleMessage';
        }
        if ('' === $course->getSummary()) {
            $reasons[] = 'onlineCoursePublishNeedsSummaryMessage';
        }
        if ($course->getMaterials()->isEmpty()) {
            $reasons[] = 'onlineCoursePublishNeedsMaterialMessage';
        }
        if (null === $this->pages->findOneByOwner($course->getOwner())) {
            $reasons[] = 'onlineCoursePublishNeedsPageMessage';
        }

        return $reasons;
    }

    /**
     * @throws OnlineCoursePublicationRefused
     */
    public function publish(OnlineCourse $course, OnlineCourseStatus $status = OnlineCourseStatus::PublicCourse): void
    {
        if (!$status->isPublished()) {
            throw new \InvalidArgumentException('Publishing a course as a draft is unpublishing it.');
        }

        $reasons = $this->publishRefusals($course);
        if ([] !== $reasons) {
            throw new OnlineCoursePublicationRefused($reasons);
        }

        $course->setStatus($status);
        $course->touch();
    }

    /** Back to a draft. Nothing is removed: the files stay where they are, the address stays the course's. */
    public function unpublish(OnlineCourse $course): void
    {
        $course->setStatus(OnlineCourseStatus::Draft);
        $course->touch();
    }

    /** The caller has asked the Voter: only a draft gets here. Its files go to the deferred purge. */
    public function delete(OnlineCourse $course): void
    {
        $owner = $course->getOwner();
        $this->materials->removeAll($course);
        $this->images->remove($course);
        $this->entityManager->remove($course);
        $this->entityManager->flush();
        $this->tags->deleteUnusedForOwner($owner);
    }
}
