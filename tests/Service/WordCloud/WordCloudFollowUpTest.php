<?php

declare(strict_types=1);

namespace App\Tests\Service\WordCloud;

use App\Entity\User;
use App\Entity\WordCloud;
use App\Entity\WordCloudSubmission;
use App\Enum\WordCloudModerationState;
use App\Service\WordCloud\WordCloudAggregator;
use App\Service\WordCloud\WordCloudFollowUp;
use App\Service\WordCloud\WordCloudNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * « Par mot » and « Par étudiant » - two readings of the same rows, which is the whole reason the
 * history is nominative.
 *
 * The two things pinned hardest here are the ones a screen built from the submissions alone would
 * quietly get wrong: a student who wrote nothing must still be on the list (they are the point of
 * it), and a refused word must be out of the ranking and still in the record.
 *
 * @phpstan-import-type WordCloudStudentRow from WordCloudFollowUp
 */
class WordCloudFollowUpTest extends TestCase
{
    public function testAWordCarriesItsContributorsAndTheHourEachWroteIt(): void
    {
        $lucas = $this->student(1, 'Lucas Martin');
        $emma = $this->student(2, 'Emma Bernard');

        $rows = $this->followUp()->byWord($this->cloud(), [
            $this->submission($lucas, 'pare-feu', '08:34'),
            $this->submission($emma, 'Pare-feu', '08:37'),
        ]);

        self::assertSame('pare-feu', $rows[0]['word']);
        self::assertSame(2, $rows[0]['count']);
        self::assertSame(100, $rows[0]['share']);
        self::assertSame(
            [['name' => 'Lucas Martin', 'time' => '08:34'], ['name' => 'Emma Bernard', 'time' => '08:37']],
            $rows[0]['contributors'],
        );
    }

    /** The bar is a share of the most cited word, not of the total. */
    public function testTheWeightBarIsRelativeToTheMostCitedWord(): void
    {
        $lucas = $this->student(1, 'Lucas Martin');
        $emma = $this->student(2, 'Emma Bernard');

        $rows = $this->followUp()->byWord($this->cloud(), [
            $this->submission($lucas, 'pare-feu', '08:34'),
            $this->submission($emma, 'pare-feu', '08:35'),
            $this->submission($lucas, 'VPN', '08:36'),
        ]);

        self::assertSame([100, 50], array_column($rows, 'share'));
    }

    /** Refused: out of the cloud, so out of its ranking too. */
    public function testARefusedWordIsNotOneOfTheCloudsWords(): void
    {
        $hugo = $this->student(1, 'Hugo Moreau');

        $rows = $this->followUp()->byWord($this->cloud(), [
            $this->submission($hugo, 'darkweb', '08:40', WordCloudModerationState::Rejected),
        ]);

        self::assertSame([], $rows);
    }

    public function testSearchingFiltersTheWords(): void
    {
        $lucas = $this->student(1, 'Lucas Martin');

        $rows = $this->followUp()->byWord($this->cloud(), [
            $this->submission($lucas, 'phishing', '08:34'),
            $this->submission($lucas, 'pare-feu', '08:35'),
        ], 'PHISH');

        self::assertSame(['phishing'], array_column($rows, 'word'));
    }

    /**
     * The line the whole screen exists for: somebody who wrote nothing is listed, marked as such,
     * rather than absent from a table of participants.
     */
    public function testEveryStudentOfTheAudienceIsListedIncludingTheSilentOnes(): void
    {
        $lucas = $this->student(1, 'Lucas Martin');
        $enzo = $this->student(2, 'Enzo Simon');

        $rows = $this->followUp()->byStudent($this->cloud(), [$lucas, $enzo], [
            $this->submission($lucas, 'pare-feu', '08:34'),
        ]);

        self::assertCount(2, $rows);
        self::assertTrue($rows[0]['participated']);
        self::assertSame(1, $rows[0]['wordCount']);
        self::assertFalse($rows[1]['participated']);
        self::assertSame(0, $rows[1]['wordCount']);
        self::assertSame([], $rows[1]['words']);
        self::assertNull($rows[1]['lastSubmittedAt']);
    }

    public function testAStudentsLastSubmissionIsTheLatestOfTheirs(): void
    {
        $lucas = $this->student(1, 'Lucas Martin');

        $rows = $this->followUp()->byStudent($this->cloud(), [$lucas], [
            $this->submission($lucas, 'pare-feu', '08:34'),
            $this->submission($lucas, 'phishing', '08:52'),
            $this->submission($lucas, 'VPN', '08:41'),
        ]);

        self::assertSame('08:52', $rows[0]['lastSubmittedAt']?->format('H:i'));
    }

    /** Refused: out of the cloud, still on the person's line - and flagged. */
    public function testARefusedWordStaysOnItsAuthorsLine(): void
    {
        $hugo = $this->student(1, 'Hugo Moreau');

        $rows = $this->followUp()->byStudent($this->cloud(), [$hugo], [
            $this->submission($hugo, 'darkweb', '08:40', WordCloudModerationState::Rejected),
        ]);

        self::assertTrue($rows[0]['participated']);
        self::assertSame([['text' => 'darkweb', 'rejected' => true]], $rows[0]['words']);
    }

    /** Typing a word on the student tab finds whoever proposed it, not only whoever is called that. */
    public function testSearchingByStudentReadsBothTheNameAndTheWords(): void
    {
        $lucas = $this->student(1, 'Lucas Martin');
        $enzo = $this->student(2, 'Enzo Simon');
        $submissions = [$this->submission($lucas, 'phishing', '08:34')];

        $followUp = $this->followUp();

        self::assertSame(['Lucas Martin'], $this->names($followUp->byStudent($this->cloud(), [$lucas, $enzo], $submissions, 'martin')));
        self::assertSame(['Lucas Martin'], $this->names($followUp->byStudent($this->cloud(), [$lucas, $enzo], $submissions, 'phish')));
        self::assertSame(['Enzo Simon'], $this->names($followUp->byStudent($this->cloud(), [$lucas, $enzo], $submissions, 'simon')));
    }

    /** « Relancer » is addressed to the silent, and a refused word is not silence. */
    public function testWhoeverWroteAnythingAtAllIsNoLongerSilent(): void
    {
        $hugo = $this->student(1, 'Hugo Moreau');
        $enzo = $this->student(2, 'Enzo Simon');

        $silent = $this->followUp()->silentStudents([$hugo, $enzo], [
            $this->submission($hugo, 'darkweb', '08:40', WordCloudModerationState::Rejected),
        ]);

        self::assertSame([$enzo], $silent);
    }

    /**
     * @param list<WordCloudStudentRow> $rows
     *
     * @return list<string>
     */
    private function names(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['student']->getDisplayName(), $rows);
    }

    private function followUp(): WordCloudFollowUp
    {
        return new WordCloudFollowUp(new WordCloudAggregator(new WordCloudNormalizer()));
    }

    private function cloud(bool $groupVariants = true): WordCloud
    {
        $cloud = $this->createStub(WordCloud::class);
        $cloud->method('isGroupVariants')->willReturn($groupVariants);

        return $cloud;
    }

    private function student(int $id, string $displayName): User
    {
        $student = $this->createStub(User::class);
        $student->method('getId')->willReturn($id);
        $student->method('getDisplayName')->willReturn($displayName);

        return $student;
    }

    private function submission(
        User $student,
        string $text,
        string $time,
        WordCloudModerationState $state = WordCloudModerationState::Approved,
    ): WordCloudSubmission {
        $submission = $this->createStub(WordCloudSubmission::class);
        $submission->method('getStudent')->willReturn($student);
        $submission->method('getText')->willReturn($text);
        $submission->method('getSubmittedAt')->willReturn(new \DateTimeImmutable('2026-09-08 '.$time));
        $submission->method('getModerationState')->willReturn($state);
        $submission->method('isCounted')->willReturn(WordCloudModerationState::Approved === $state);

        return $submission;
    }
}
