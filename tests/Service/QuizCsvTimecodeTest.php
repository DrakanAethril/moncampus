<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuizCsvImporter;
use App\Service\QuizCsvImportException;
use App\Service\VideoImportContext;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one thing a video adds to a quiz import: the minute at which a question is asked (créas 5B,
 * screen 3 bis). Everything else - the delimiter, the header aliases, the per-row rejection - is
 * the CSV importer that already exists, which is why this reads through parseRows() rather than
 * through a second parser.
 *
 * The column is named `timecode` and not `temps`: `temps` already means "how long the student has
 * to answer" in COLUMN_ALIASES, and reusing it would read a minute mark as a stopwatch, silently.
 */
final class QuizCsvTimecodeTest extends TestCase
{
    private QuizCsvImporter $importer;

    protected function setUp(): void
    {
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id.(isset($parameters['%line%']) ? ' L'.$parameters['%line%'] : '');
            }

            public function getLocale(): string
            {
                return 'fr';
            }
        };

        $this->importer = new QuizCsvImporter($translator);
    }

    // --- read from a video ---

    public function testEveryAcceptedSpellingLandsOnTheQuestion(): void
    {
        $payload = $this->importer->parseRows([
            ['timecode', 'type', 'enonce', 'reponse_1', 'reponse_2', 'bonnes'],
            ['02:15', 'qcm', 'À quoi sert un VLAN ?', 'À segmenter', 'À router', '1'],
            ['1:05:40', 'qcm', 'Et le trunk ?', 'À transporter plusieurs VLAN', 'À rien', '1'],
            ['340', 'qcm', 'Et le mode access ?', 'Un seul VLAN', 'Tous', '1'],
        ], 'vlan.csv', new VideoImportContext(4000));

        self::assertSame([135, 3940, 340], array_column($payload['questions'], 'timecode'));
        self::assertSame([], $payload['errors']);
    }

    public function testTheColumnIsFoundUnderItsFrenchSpellings(): void
    {
        foreach (['minutage', 'top', 'position', 'Timecode'] as $header) {
            $payload = $this->importer->parseRows([
                [$header, 'enonce', 'reponse_1', 'reponse_2', 'bonnes'],
                ['02:15', 'À quoi sert un VLAN ?', 'À segmenter', 'À router', '1'],
            ], 'vlan.csv', new VideoImportContext(4000));

            self::assertSame(135, $payload['questions'][0]['timecode'], $header.' should name the timecode column');
        }
    }

    public function testARowWhoseTimecodeIsUnreadableIsTheOnlyOneLost(): void
    {
        $payload = $this->importer->parseRows([
            ['timecode', 'enonce', 'reponse_1', 'reponse_2', 'bonnes'],
            ['02:15', 'À quoi sert un VLAN ?', 'À segmenter', 'À router', '1'],
            ['plus tard', 'Et le trunk ?', 'Plusieurs VLAN', 'Rien', '1'],
            ['05:40', 'Et le mode access ?', 'Un seul VLAN', 'Tous', '1'],
        ], 'vlan.csv', new VideoImportContext(4000));

        self::assertSame([135, 340], array_column($payload['questions'], 'timecode'));
        self::assertCount(1, $payload['errors']);
        self::assertStringContainsString('L3', $payload['errors'][0]);
    }

    public function testARowPastTheEndOfTheVideoIsRejectedLikeAnyOtherBadCell(): void
    {
        // The créa's own example: a 12:40 video and a question written at 12:55.
        $payload = $this->importer->parseRows([
            ['timecode', 'enonce', 'reponse_1', 'reponse_2', 'bonnes'],
            ['02:15', 'À quoi sert un VLAN ?', 'À segmenter', 'À router', '1'],
            ['12:55', 'Quelle commande vérifie les VLAN ?', 'show vlan', 'show ip', '1'],
        ], 'vlan.csv', new VideoImportContext(760));

        self::assertCount(1, $payload['questions']);
        self::assertCount(1, $payload['errors']);
    }

    public function testTwoQuestionsAtTheSameTimecodeAreAWarningRatherThanAnError(): void
    {
        // They simply follow one another - which is a thing a teacher may well have meant.
        $payload = $this->importer->parseRows([
            ['timecode', 'enonce', 'reponse_1', 'reponse_2', 'bonnes'],
            ['02:15', 'À quoi sert un VLAN ?', 'À segmenter', 'À router', '1'],
            ['02:15', 'Et un trunk ?', 'Plusieurs VLAN', 'Rien', '1'],
        ], 'vlan.csv', new VideoImportContext(4000));

        self::assertCount(2, $payload['questions']);
        self::assertSame([], $payload['errors']);
        self::assertCount(1, $payload['warnings']);
    }

    public function testAFileWithoutTheColumnCannotBeImportedIntoAVideo(): void
    {
        // Nothing would say where to put the questions, and putting them all at 0:00 is not a
        // guess anybody wants made for them.
        $this->expectException(QuizCsvImportException::class);

        $this->importer->parseRows([
            ['enonce', 'reponse_1', 'reponse_2', 'bonnes'],
            ['À quoi sert un VLAN ?', 'À segmenter', 'À router', '1'],
        ], 'vlan.csv', new VideoImportContext(4000));
    }

    // --- read from the library, where a video is nowhere in sight ---

    public function testTheLibraryImportIsUnchangedByTheNewColumn(): void
    {
        $payload = $this->importer->parseRows([
            ['enonce', 'reponse_1', 'reponse_2', 'bonnes'],
            ['À quoi sert un VLAN ?', 'À segmenter', 'À router', '1'],
        ]);

        self::assertCount(1, $payload['questions']);
        self::assertNull($payload['questions'][0]['timecode']);
        self::assertSame([], $payload['warnings']);
    }

    public function testATimecodeColumnImportedIntoTheLibraryIsIgnoredAndSaidSo(): void
    {
        $payload = $this->importer->parseRows([
            ['timecode', 'enonce', 'reponse_1', 'reponse_2', 'bonnes'],
            ['02:15', 'À quoi sert un VLAN ?', 'À segmenter', 'À router', '1'],
        ]);

        self::assertCount(1, $payload['questions']);
        self::assertNull($payload['questions'][0]['timecode'], 'a bank has no timeline to place it on');
        self::assertCount(1, $payload['warnings']);
    }
}
