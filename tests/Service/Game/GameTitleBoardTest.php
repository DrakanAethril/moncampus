<?php

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Enum\GameTrack;
use App\Repository\GameLevelLabelRepository;
use App\Service\Game\GameLevelBoard;
use App\Service\Game\GameTitleBoard;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Which titles a student may display, and which they may not (App\Service\Game\GameTitleBoard).
 *
 * Two rules, and the screen exists for the first: a student playing in two filières chooses among
 * both wordings, because a SIO student whose option names neither is playing in both. The second is
 * the one that has to hold server-side - a title is only offered from a level already reached, and
 * the form posts a string, so the check cannot live in the template.
 */
class GameTitleBoardTest extends TestCase
{
    private GameTitleBoard $titles;

    protected function setUp(): void
    {
        $repository = $this->createStub(GameLevelLabelRepository::class);
        $repository->method('allByTrackAndLevel')->willReturn([
            'SLAM|1' => 'Stagiaire « Hello World »',
            'SLAM|2' => 'Apprenti·e du code',
            'SLAM|3' => 'Chasseur·se de bugs',
            'SISR|1' => 'Stagiaire du help desk',
            'SISR|2' => 'Apprenti·e câbleur·se',
            'SISR|3' => 'Chasseur·se de pannes',
        ]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => 'Niveau '.$parameters['%level%'],
        );

        $this->titles = new GameTitleBoard(new GameLevelBoard($repository, $translator));
    }

    public function testAStudentOfTwoFilieresIsOfferedOneColumnEach(): void
    {
        $columns = $this->titles->columnsFor([GameTrack::Slam, GameTrack::Sisr], 0);

        self::assertCount(2, $columns);
        self::assertSame(GameTrack::Slam, $columns[0]['track']);
        self::assertSame(GameTrack::Sisr, $columns[1]['track']);
        self::assertCount(6, $columns[0]['entries'], 'the six levels, reached or not');
        self::assertSame('Stagiaire du help desk', $columns[1]['entries'][0]['title']);
    }

    public function testALevelIsReachedOnTheTotalAndNotOnTheWordingBeingWritten(): void
    {
        // 700 is level 3's threshold exactly, and level 4 asks 1 200.
        $entries = $this->titles->columnsFor([GameTrack::Slam], 700)[0]['entries'];

        self::assertTrue($entries[2]['reached']);
        self::assertFalse($entries[3]['reached']);
        // Level 4 has no stored wording here, and still names itself rather than showing a hole.
        self::assertSame('Niveau 4', $entries[3]['title']);
    }

    public function testOnlyTheTitlesOfReachedLevelsAreUnlockedAcrossBothFilieres(): void
    {
        $unlocked = $this->titles->unlockedTitles([GameTrack::Slam, GameTrack::Sisr], 300);

        self::assertSame([
            'Stagiaire « Hello World »',
            'Apprenti·e du code',
            'Stagiaire du help desk',
            'Apprenti·e câbleur·se',
        ], $unlocked);
    }

    public function testTheFirstTitleIsAlwaysReachableSoNobodyIsLeftWithNoChoice(): void
    {
        self::assertSame(['Niveau 1'], $this->titles->unlockedTitles([], 0), 'no filière: the generic wording');
    }

    public function testATitleFromAnUnreachedLevelIsRefused(): void
    {
        self::assertTrue($this->titles->allows([GameTrack::Slam], 300, 'Apprenti·e du code'));
        self::assertFalse($this->titles->allows([GameTrack::Slam], 299, 'Apprenti·e du code'));
    }

    public function testATitleOfAFiliereTheStudentDoesNotPlayInIsRefused(): void
    {
        // The form posts a string: without this check, anybody could send themselves the wording of
        // a filière they are not in, or of a level they have not reached.
        self::assertFalse($this->titles->allows([GameTrack::Slam], 2500, 'Chasseur·se de pannes'));
        self::assertTrue($this->titles->allows([GameTrack::Slam, GameTrack::Sisr], 2500, 'Chasseur·se de pannes'));
    }
}
