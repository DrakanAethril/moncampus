<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Entity\User;
use App\Entity\WordCloud;
use App\Entity\WordCloudSubmission;
use App\Enum\WordCloudModerationState;

/**
 * The two follow-up views, « Par mot » and « Par étudiant ».
 *
 * They are **two aggregations of one table** and nothing more, which is the whole point of the
 * nominative history: the same rows answer « qui a proposé ce mot » and « qu'a proposé cette
 * personne », so the two screens can never contradict each other.
 *
 * Two decisions are worth stating, because both are what makes the follow-up honest:
 *
 * - **Every student of the audience is listed**, including those who wrote nothing. A list built
 *   from the submissions alone would be a list of people who took part, which is exactly the half
 *   the teacher does not need.
 * - **Refused words appear**, marked as such. They left the cloud, not the record.
 *
 * @phpstan-type WordCloudWordRow array{word: string, count: int, share: int, contributors: list<array{name: string, time: string}>}
 * @phpstan-type WordCloudStudentRow array{student: User, participated: bool, wordCount: int, words: list<array{text: string, rejected: bool}>, lastSubmittedAt: \DateTimeImmutable|null}
 */
class WordCloudFollowUp
{
    public function __construct(private readonly WordCloudAggregator $aggregator)
    {
    }

    /**
     * @param list<WordCloudSubmission> $submissions
     *
     * @return list<WordCloudWordRow>
     */
    public function byWord(WordCloud $cloud, array $submissions, string $query = ''): array
    {
        // The cloud's own words: what was refused is not one of them, and would otherwise show up
        // in the ranking it was taken out of.
        $counted = array_values(array_filter($submissions, static fn (WordCloudSubmission $one): bool => $one->isCounted()));

        $rows = [];
        $contributorsByKey = [];
        foreach ($counted as $submission) {
            $key = $this->aggregator->key((string) $submission->getText(), $cloud->isGroupVariants());
            $contributorsByKey[$key][] = [
                'name' => $this->displayName($submission->getStudent()),
                'time' => $submission->getSubmittedAt()->format('H:i'),
            ];
        }

        $words = $this->aggregator->aggregate(array_map(
            fn (WordCloudSubmission $one): array => [
                'text' => (string) $one->getText(),
                'key' => $this->aggregator->key((string) $one->getText(), $cloud->isGroupVariants()),
            ],
            $counted,
        ));

        $max = $words[0]['count'] ?? 0;

        foreach ($words as $word) {
            if (!$this->matches($query, $word['word'])) {
                continue;
            }

            // The displayed spelling is one variant of the group; its contributors are the whole
            // group's, which is why they are gathered by key rather than by the word shown.
            $key = $this->aggregator->key($word['word'], $cloud->isGroupVariants());

            $rows[] = [
                'word' => $word['word'],
                'count' => $word['count'],
                'share' => $max > 0 ? (int) round($word['count'] / $max * 100) : 0,
                'contributors' => $contributorsByKey[$key] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * @param list<User>                $roster      the cloud's audience, in display order
     * @param list<WordCloudSubmission> $submissions
     *
     * @return list<WordCloudStudentRow>
     */
    public function byStudent(WordCloud $cloud, array $roster, array $submissions, string $query = ''): array
    {
        /** @var array<int, list<WordCloudSubmission>> $byStudentId */
        $byStudentId = [];
        foreach ($submissions as $submission) {
            $byStudentId[(int) $submission->getStudent()?->getId()][] = $submission;
        }

        $rows = [];
        foreach ($roster as $student) {
            $own = $byStudentId[(int) $student->getId()] ?? [];
            $words = array_map(
                static fn (WordCloudSubmission $one): array => [
                    'text' => (string) $one->getText(),
                    'rejected' => WordCloudModerationState::Rejected === $one->getModerationState(),
                ],
                $own,
            );

            // The search reads the person and what they wrote, so typing a word finds whoever
            // proposed it on this tab as well as on the other one.
            if (!$this->matches($query, $this->displayName($student), ...array_column($words, 'text'))) {
                continue;
            }

            $last = null;
            foreach ($own as $submission) {
                if (null === $last || $submission->getSubmittedAt() > $last) {
                    $last = $submission->getSubmittedAt();
                }
            }

            $rows[] = [
                'student' => $student,
                'participated' => [] !== $own,
                'wordCount' => \count($own),
                'words' => $words,
                'lastSubmittedAt' => $last,
            ];
        }

        return $rows;
    }

    /**
     * The students of the audience who wrote nothing at all - who « Relancer » and « Message aux
     * sans-réponse » are addressed to, and nobody else.
     *
     * @param list<User>                $roster
     * @param list<WordCloudSubmission> $submissions
     *
     * @return list<User>
     */
    public function silentStudents(array $roster, array $submissions): array
    {
        $spoke = [];
        foreach ($submissions as $submission) {
            $spoke[(int) $submission->getStudent()?->getId()] = true;
        }

        return array_values(array_filter(
            $roster,
            static fn (User $student): bool => !isset($spoke[(int) $student->getId()]),
        ));
    }

    private function matches(string $query, string ...$haystacks): bool
    {
        $needle = mb_strtolower(trim($query));
        if ('' === $needle) {
            return true;
        }

        foreach ($haystacks as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function displayName(?User $user): string
    {
        if (null === $user) {
            return '';
        }

        return $user->getDisplayName() ?? $user->getUsername();
    }
}
