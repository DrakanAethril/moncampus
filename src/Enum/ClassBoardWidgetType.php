<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The catalogue of the virtual board's widgets (design/validated/tableau-virtuel.md, §5).
 *
 * The one list the dock, the layout validation and the rendering all read: a widget the enum does
 * not name cannot be added, saved or drawn. Each case says which dock family it sits in, which
 * features must be lit for the dock to offer it, and whether it reads a class - those are greyed out
 * on a board linked to none.
 *
 * The value is what the layout JSON stores, and what names the partial
 * (`templates/class_board/widgets/_<value>.html.twig`) and the Stimulus controller
 * (`class-board-<value with dashes>`) of the widget.
 */
enum ClassBoardWidgetType: string
{
    /** The order of the dock, within each family - the mockup's, not the order of the cases. */
    private const array DOCK_ORDER = [
        'timer', 'stopwatch', 'clock',
        'random_draw', 'groups', 'session', 'session_plan', 'work', 'quiz_live', 'word_cloud', 'team_counter',
        'traffic_light', 'sound_level', 'instructions',
        'text', 'drawing', 'media', 'video', 'visualizer', 'qr_code', 'dice',
    ];

    case Timer = 'timer';
    case Stopwatch = 'stopwatch';
    case Clock = 'clock';
    case RandomDraw = 'random_draw';
    case Groups = 'groups';
    case TeamCounter = 'team_counter';
    case TrafficLight = 'traffic_light';
    case Instructions = 'instructions';
    case Text = 'text';
    case QrCode = 'qr_code';
    case Dice = 'dice';
    case Session = 'session';
    case SessionPlan = 'session_plan';
    case Work = 'work';
    case Media = 'media';
    case Video = 'video';

    public function labelKey(): string
    {
        return match ($this) {
            self::Timer => 'classBoardWidgetTimerLabel',
            self::Stopwatch => 'classBoardWidgetStopwatchLabel',
            self::Clock => 'classBoardWidgetClockLabel',
            self::RandomDraw => 'classBoardWidgetRandomDrawLabel',
            self::Groups => 'classBoardWidgetGroupsLabel',
            self::TeamCounter => 'classBoardWidgetTeamCounterLabel',
            self::TrafficLight => 'classBoardWidgetTrafficLightLabel',
            self::Instructions => 'classBoardWidgetInstructionsLabel',
            self::Text => 'classBoardWidgetTextLabel',
            self::QrCode => 'classBoardWidgetQrCodeLabel',
            self::Dice => 'classBoardWidgetDiceLabel',
            self::Session => 'classBoardWidgetSessionLabel',
            self::SessionPlan => 'classBoardWidgetSessionPlanLabel',
            self::Work => 'classBoardWidgetWorkLabel',
            self::Media => 'classBoardWidgetMediaLabel',
            self::Video => 'classBoardWidgetVideoLabel',
        };
    }

    public function family(): ClassBoardWidgetFamily
    {
        return match ($this) {
            self::Timer, self::Stopwatch, self::Clock => ClassBoardWidgetFamily::Time,
            self::RandomDraw, self::Groups, self::Session, self::SessionPlan, self::Work, self::TeamCounter => ClassBoardWidgetFamily::ClassGroup,
            self::TrafficLight, self::Instructions => ClassBoardWidgetFamily::Ambiance,
            self::Text, self::Media, self::Video, self::QrCode, self::Dice => ClassBoardWidgetFamily::Content,
        };
    }

    /**
     * Features that must be lit, on top of class_tools which opens the board itself, for the dock
     * to offer the widget.
     *
     * @return list<Feature>
     */
    public function features(): array
    {
        return match ($this) {
            self::Session => [Feature::Timetable],
            self::Work => [Feature::StudentWork],
            self::Media => [Feature::FileLibrary],
            default => [],
        };
    }

    /**
     * A widget that reads a class: on a board linked to none it is greyed out in the dock and,
     * already placed, shows why it has nothing to show. The team counter sits in the « Classe »
     * family without reading one - its teams can be typed by hand.
     */
    public function readsClass(): bool
    {
        return match ($this) {
            self::RandomDraw, self::Groups, self::Session, self::SessionPlan, self::Work => true,
            default => false,
        };
    }

    /**
     * Whether the widget's head carries the gear that opens its settings panel.
     */
    public function hasSettings(): bool
    {
        return match ($this) {
            self::Clock, self::RandomDraw, self::Groups, self::TeamCounter, self::SessionPlan, self::Work, self::Media, self::Video => true,
            default => false,
        };
    }

    /**
     * The file of assets/icons/ drawn in the dock and in the widget's head.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Timer => 'timer',
            self::Stopwatch => 'stopwatch',
            self::Clock => 'clock',
            self::RandomDraw => 'shuffle',
            self::Groups => 'users',
            self::TeamCounter => 'chart-bar',
            self::TrafficLight => 'traffic-light',
            self::Instructions => 'hand-stop',
            self::Text => 'typography',
            self::QrCode => 'qrcode',
            self::Dice => 'dice',
            self::Session => 'calendar',
            self::SessionPlan => 'list-details',
            self::Work => 'clipboard-check',
            self::Media => 'photo',
            self::Video => 'video',
        };
    }

    /**
     * The size a widget is given when the dock adds it, in percent of the surface.
     *
     * @return array{w: float, h: float}
     */
    public function defaultSize(): array
    {
        [$width, $height] = match ($this) {
            self::Timer => [26, 42],
            self::Stopwatch => [22, 26],
            self::Clock => [13, 30],
            self::RandomDraw => [24, 40],
            self::Groups => [30, 44],
            self::TeamCounter => [26, 38],
            self::TrafficLight => [9, 42],
            self::Instructions => [25, 38],
            self::Text => [26, 30],
            self::QrCode => [18, 44],
            self::Dice => [24, 30],
            self::Session => [24, 36],
            self::SessionPlan => [24, 46],
            self::Work => [28, 32],
            self::Media => [30, 40],
            self::Video => [34, 44],
        };

        return ['w' => (float) $width, 'h' => (float) $height];
    }

    /**
     * The Stimulus identifier of the widget's own controller.
     */
    public function controller(): string
    {
        return 'class-board-'.str_replace('_', '-', $this->value);
    }

    /**
     * The dock, family by family, in the order of the mockup.
     *
     * @return array<string, list<self>>
     */
    public static function dock(): array
    {
        $dock = [];
        foreach (ClassBoardWidgetFamily::cases() as $family) {
            $dock[$family->value] = [];
        }
        foreach (self::DOCK_ORDER as $value) {
            $type = self::tryFrom($value);
            if (null !== $type) {
                $dock[$type->family()->value][] = $type;
            }
        }

        return $dock;
    }
}
