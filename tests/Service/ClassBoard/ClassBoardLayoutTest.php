<?php

declare(strict_types=1);

namespace App\Tests\Service\ClassBoard;

use App\Enum\ClassBoardWidgetType;
use App\Service\ClassBoard\ClassBoardLayout;
use App\Service\ClassBoard\InvalidClassBoardLayoutException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The one door of a board's layout (design/validated/tableau-virtuel.md, §3): a malformed document
 * is refused whole, a widget pushed past the edge is brought back, and what is stored is what was
 * set - sanitized, bounded.
 */
class ClassBoardLayoutTest extends TestCase
{
    private ClassBoardLayout $layout;

    protected function setUp(): void
    {
        $this->layout = new ClassBoardLayout(new HtmlSanitizer((new HtmlSanitizerConfig())->allowSafeElements()));
    }

    public function testAnUnknownWidgetTypeRefusesTheWholeDocument(): void
    {
        $this->expectException(InvalidClassBoardLayoutException::class);

        $this->layout->normalize([
            $this->widget('timer', 'a'),
            $this->widget('karaoke', 'b'),
        ]);
    }

    public function testAConfigOfTheWrongTypeRefusesTheDocument(): void
    {
        $this->expectException(InvalidClassBoardLayoutException::class);

        $this->layout->normalize([$this->widget('timer', 'a', ['durationSeconds' => 'quinze minutes'])]);
    }

    public function testCoordinatesOutsideTheSurfaceAreBroughtBack(): void
    {
        $widget = $this->layout->normalize([[
            'id' => 'a', 'type' => 'dice', 'x' => 95, 'y' => -12, 'w' => 2, 'h' => 140, 'z' => 3, 'config' => [],
        ]])[0];

        self::assertSame(ClassBoardLayout::MIN_WIDTH, $widget['w']);
        self::assertSame(100.0, $widget['h']);
        self::assertSame(94.0, $widget['x']);
        self::assertSame(0.0, $widget['y']);
    }

    public function testMoreThanFortyWidgetsAreRefused(): void
    {
        $this->expectException(InvalidClassBoardLayoutException::class);

        $this->layout->normalize(array_map(fn (int $index): array => $this->widget('dice', 'w'.$index), range(1, 41)));
    }

    public function testADocumentOverItsSizeIsRefused(): void
    {
        $this->expectException(InvalidClassBoardLayoutException::class);

        $this->layout->normalizeJson((string) json_encode(['widgets' => [], 'padding' => str_repeat('x', ClassBoardLayout::MAX_BYTES)]));
    }

    public function testTheSameIdTwiceIsRefused(): void
    {
        $this->expectException(InvalidClassBoardLayoutException::class);

        $this->layout->normalize([$this->widget('dice', 'a'), $this->widget('timer', 'a')]);
    }

    public function testTheTextIsSanitized(): void
    {
        $widget = $this->layout->normalize([$this->widget('text', 'a', ['html' => '<p>Bonjour</p><script>alert(1)</script><img src="x" onerror="alert(1)">'])])[0];

        $html = $this->config($widget)['html'];
        self::assertIsString($html);
        self::assertStringContainsString('<p>Bonjour</p>', $html);
        self::assertStringNotContainsString('script', $html);
        self::assertStringNotContainsString('onerror', $html);
    }

    public function testAClockLabelLongerThanSixtyCharactersIsRefused(): void
    {
        $this->layout->normalize([$this->widget('clock', 'a', ['label' => str_repeat('é', 60)])]);

        $this->expectException(InvalidClassBoardLayoutException::class);
        $this->layout->normalize([$this->widget('clock', 'a', ['label' => str_repeat('é', 61)])]);
    }

    public function testAnInvalidFixedTimeIsRefused(): void
    {
        $widget = $this->layout->normalize([$this->widget('clock', 'a', ['mode' => 'fixed', 'time' => '09:40'])])[0];
        self::assertSame('09:40', $this->config($widget)['time']);

        $this->expectException(InvalidClassBoardLayoutException::class);
        $this->layout->normalize([$this->widget('clock', 'a', ['mode' => 'fixed', 'time' => '25:61'])]);
    }

    /** What runs is never stored: a key the widget does not know is dropped, not kept. */
    public function testOnlyTheSettingsAreKept(): void
    {
        $widget = $this->layout->normalize([$this->widget('timer', 'a', ['durationSeconds' => 900, 'remaining' => 512])])[0];

        self::assertSame(['durationSeconds' => 900], $widget['config']);
    }

    /**
     * The groups widget keeps the settings of the group creation panel - never the groups drawn, the
     * absentees or the pairs - and a layout stored before it had any reads as the panel's defaults.
     */
    public function testTheGroupsWidgetKeepsThePanelSettingsAndNothingDrawn(): void
    {
        $bare = $this->layout->normalize([$this->widget('groups', 'a', ['batchId' => 4])])[0];
        self::assertSame(
            ['batchId' => 4, 'mode' => 'taille', 'value' => 3, 'optionId' => null, 'mixite' => 'libre', 'nameFormat' => 'short', 'panel' => true],
            $bare['config'],
        );

        $set = $this->layout->normalize([$this->widget('groups', 'a', [
            'mode' => 'nombre', 'value' => 25, 'optionId' => 7, 'mixite' => 'mixte', 'nameFormat' => 'full', 'panel' => false,
            'groups' => [[1, 2], [3]], 'absentIds' => [4], 'separatePairs' => [[1, 3]],
        ])])[0];
        self::assertSame(
            ['batchId' => null, 'mode' => 'nombre', 'value' => 10, 'optionId' => 7, 'mixite' => 'mixte', 'nameFormat' => 'full', 'panel' => false],
            $set['config'],
        );

        $this->expectException(InvalidClassBoardLayoutException::class);
        $this->layout->normalize([$this->widget('groups', 'a', ['mixite' => 'au hasard'])]);
    }

    /** No ceiling on the teams of a counter: a lot of thirty groups makes thirty teams. */
    public function testATeamCounterTakesAsManyTeamsAsWanted(): void
    {
        $teams = array_map(static fn (int $index): array => ['name' => 'Équipe '.$index, 'score' => $index], range(1, 30));

        $widget = $this->layout->normalize([$this->widget('team_counter', 'a', ['teams' => $teams])])[0];

        self::assertSame($teams, $this->config($widget)['teams']);
    }

    public function testAReferenceIsAPositiveIdOrNothing(): void
    {
        $widget = $this->layout->normalize([$this->widget('random_draw', 'a', ['drawId' => 12])])[0];
        self::assertSame(12, $this->config($widget)['drawId']);

        $this->expectException(InvalidClassBoardLayoutException::class);
        $this->layout->normalize([$this->widget('random_draw', 'a', ['drawId' => '12; DROP'])]);
    }

    /** A drawing's key is the server's: the stored one wins, a sent one must name this very widget. */
    public function testADrawingKeyCannotPointAtAnotherObject(): void
    {
        $own = 'class-board/7/w1-0123456789abcdef.png';
        $kept = $this->layout->normalize([$this->widget('drawing', 'w1', ['key' => $own])], [], 7)[0];
        self::assertSame($own, $this->config($kept)['key']);

        foreach (['avatars/someone.png', 'class-board/8/w1-0123456789abcdef.png', 'class-board/7/w2-0123456789abcdef.png'] as $forged) {
            $widget = $this->layout->normalize([$this->widget('drawing', 'w1', ['key' => $forged])], [], 7)[0];
            self::assertNull($this->config($widget)['key'], $forged);
        }

        $stored = [['id' => 'w1', 'type' => 'drawing', 'config' => ['key' => $own]]];
        $widget = $this->layout->normalize([$this->widget('drawing', 'w1', ['key' => 'class-board/7/w1-ffffffffffffffff.png'])], $stored, 7)[0];
        self::assertSame($own, $this->config($widget)['key']);
    }

    public function testTheDockOffersEveryWidgetOnce(): void
    {
        $offered = array_merge(...array_values(ClassBoardWidgetType::dock()));

        self::assertCount(\count(ClassBoardWidgetType::cases()), $offered);
        self::assertSame(ClassBoardWidgetType::cases(), array_values(array_filter(ClassBoardWidgetType::cases(), static fn (ClassBoardWidgetType $type): bool => \in_array($type, $offered, true))));
    }

    public function testEveryWidgetTypeHasAPartialAndAnIcon(): void
    {
        $root = \dirname(__DIR__, 3);
        foreach (ClassBoardWidgetType::cases() as $type) {
            self::assertFileExists($root.'/templates/class_board/widgets/_'.$type->value.'.html.twig');
            self::assertFileExists($root.'/assets/icons/'.$type->icon().'.svg');
            if ($type->hasSettings()) {
                self::assertFileExists($root.'/templates/class_board/settings/_'.$type->value.'.html.twig');
            }
            // A new widget gets a valid default configuration from the same door.
            $widget = $this->layout->newWidget($type, 'w1', 0, 1);
            self::assertSame($type->value, $widget['type']);
        }
    }

    /**
     * @param array<string, mixed> $widget
     *
     * @return array<array-key, mixed>
     */
    private function config(array $widget): array
    {
        self::assertIsArray($widget['config']);

        return $widget['config'];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function widget(string $type, string $id, array $config = []): array
    {
        return ['id' => $id, 'type' => $type, 'x' => 10, 'y' => 10, 'w' => 20, 'h' => 20, 'z' => 1, 'config' => $config];
    }
}
