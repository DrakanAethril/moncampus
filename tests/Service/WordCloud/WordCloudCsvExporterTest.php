<?php

declare(strict_types=1);

namespace App\Tests\Service\WordCloud;

use App\Entity\User;
use App\Entity\WordCloud;
use App\Entity\WordCloudSubmission;
use App\Enum\WordCloudModerationState;
use App\Service\WordCloud\WordCloudAggregator;
use App\Service\WordCloud\WordCloudCsvExporter;
use App\Service\WordCloud\WordCloudFollowUp;
use App\Service\WordCloud\WordCloudNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The file a teacher opens after the activity. What it must carry, and what would make it useless:
 * the refused words (taken out of the cloud, not out of the record) and the students who wrote
 * nothing - the very question both follow-up screens are built around.
 */
class WordCloudCsvExporterTest extends TestCase
{
    public function testOneLinePerWordWithItsAuthorItsHourAndItsState(): void
    {
        $lucas = $this->student(1, 'Lucas Martin');

        $csv = $this->export([$lucas], [
            $this->submission($lucas, 'pare-feu', '08:34'),
            $this->submission($lucas, 'darkweb', '08:40', WordCloudModerationState::Rejected),
        ]);

        self::assertStringContainsString('"Lucas Martin";"pare-feu";"08/09/2026 08:34";"Retenu"', $csv);
        self::assertStringContainsString('"Lucas Martin";"darkweb";"08/09/2026 08:40";"Refusé"', $csv);
    }

    public function testAStudentWhoWroteNothingStillHasALine(): void
    {
        $lucas = $this->student(1, 'Lucas Martin');
        $enzo = $this->student(2, 'Enzo Simon');

        $csv = $this->export([$lucas, $enzo], [$this->submission($lucas, 'pare-feu', '08:34')]);

        self::assertStringContainsString('"Enzo Simon";"";"";"Sans réponse"', $csv);
    }

    public function testItOpensOnAByteOrderMarkAndAHeaderRow(): void
    {
        $csv = $this->export([], []);

        self::assertStringStartsWith("\u{FEFF}\"Étudiant\";\"Mot\";\"Horodatage\";\"État\"\r\n", $csv);
    }

    /** A word carrying the separator must not split the row it is on. */
    public function testASemicolonInsideAWordDoesNotBreakTheRow(): void
    {
        $lucas = $this->student(1, 'Lucas Martin');

        $csv = $this->export([$lucas], [$this->submission($lucas, 'a;b', '08:34')]);

        self::assertStringContainsString('"Lucas Martin";"a;b";', $csv);
    }

    /** Transliterated, not stripped: « Cybersécurité » must not come out as « cybers-curit ». */
    public function testTheFilenameIsBuiltFromTheCloudsName(): void
    {
        self::assertSame('cybersecurite-premiere-impression.csv', (new WordCloudCsvExporter())->filename(
            $this->cloud('Cybersécurité — première impression'),
        ));
    }

    public function testACloudWhoseNameSlugsToNothingStillHasAFilename(): void
    {
        self::assertSame('nuage-de-mots.csv', (new WordCloudCsvExporter())->filename($this->cloud('???')));
    }

    /**
     * @param list<User>                $roster
     * @param list<WordCloudSubmission> $submissions
     */
    private function export(array $roster, array $submissions): string
    {
        $followUp = new WordCloudFollowUp(new WordCloudAggregator(new WordCloudNormalizer()));

        return (new WordCloudCsvExporter())->export($this->cloud('Cybersécurité'), $roster, $submissions, $followUp);
    }

    private function cloud(string $name): WordCloud
    {
        $cloud = $this->createStub(WordCloud::class);
        $cloud->method('getName')->willReturn($name);
        $cloud->method('isGroupVariants')->willReturn(true);

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
