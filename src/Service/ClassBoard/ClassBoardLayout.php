<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

use App\Enum\ClassBoardWidgetType;
use App\Enum\GroupCreationMode;
use App\Enum\GroupMixite;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * The only door a board's layout goes through (design/validated/tableau-virtuel.md, §3).
 *
 * The page sends the whole document at every save; this turns it into what is stored, or refuses
 * it whole at the first invalid element - an unknown widget type, a configuration of the wrong
 * shape - the way the imports refuse a file. Refusing is never a 500: the controller answers 422
 * and the page says « Non enregistré » while keeping what it shows.
 *
 * What is *corrected* rather than refused: coordinates pulled back inside the surface, sizes raised
 * to their minimum, numbers clamped to their range. A widget dragged one pixel past the edge is not
 * a malformed document.
 *
 * What a widget keeps is what was set - a duration, a label, a link to a saved draw - never what
 * runs: no remaining time, no current phase, no last name drawn. A reference to another object is
 * an id and nothing more; whether it may still be read is asked at every opening
 * (ClassBoardWidgetData), never here.
 */
final class ClassBoardLayout
{
    public const int MAX_WIDGETS = 40;
    public const int MAX_BYTES = 262_144;
    public const float MIN_WIDTH = 6.0;
    public const float MIN_HEIGHT = 10.0;
    public const int CLOCK_LABEL_MAX = 60;
    public const int TEAM_NAME_MAX = 40;
    public const int URL_MAX = 2_000;
    public const int TEXT_MAX = 50_000;
    public const int MAX_PHASES = 30;
    public const int PHASE_NAME_MAX = 160;

    /** The pictograms of the « Consignes » widget, in display order. */
    public const array INSTRUCTIONS = ['silence', 'whisper', 'alone', 'pair', 'group', 'hand', 'screens_off', 'screens_on'];

    public function __construct(
        #[Target('app.library_content')] private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    /**
     * The document as the page posts it: `{"background": …, "widgets": [ … ]}` - only the widget
     * list is read here; the background is a plain enum the controller reads itself.
     *
     * @param list<array<string, mixed>> $previous the stored layout, for the values only the server
     *                                             may set (a drawing's storage key)
     * @param int|null                   $boardId  the board, to recognise its own drawings' keys
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidClassBoardLayoutException
     */
    public function normalizeJson(string $json, array $previous = [], ?int $boardId = null): array
    {
        if (\strlen($json) > self::MAX_BYTES) {
            throw new InvalidClassBoardLayoutException('Layout document too large.');
        }

        $decoded = json_decode($json, true);
        if (!\is_array($decoded) || !\is_array($decoded['widgets'] ?? null)) {
            throw new InvalidClassBoardLayoutException('Layout document has no widget list.');
        }

        return $this->normalize($decoded['widgets'], $previous, $boardId);
    }

    /**
     * @param array<array-key, mixed>    $widgets
     * @param list<array<string, mixed>> $previous
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidClassBoardLayoutException
     */
    public function normalize(array $widgets, array $previous = [], ?int $boardId = null): array
    {
        if (!array_is_list($widgets)) {
            throw new InvalidClassBoardLayoutException('Widgets must be a list.');
        }
        if (\count($widgets) > self::MAX_WIDGETS) {
            throw new InvalidClassBoardLayoutException(\sprintf('More than %d widgets.', self::MAX_WIDGETS));
        }

        $previousById = [];
        foreach ($previous as $widget) {
            if (\is_string($widget['id'] ?? null)) {
                $previousById[$widget['id']] = $widget;
            }
        }

        $normalized = [];
        $seen = [];
        foreach ($widgets as $index => $widget) {
            if (!\is_array($widget)) {
                throw new InvalidClassBoardLayoutException(\sprintf('Widget #%d is not an object.', $index));
            }
            $entry = $this->widget($widget, $previousById, $boardId);
            if (isset($seen[$entry['id']])) {
                throw new InvalidClassBoardLayoutException(\sprintf('Widget id "%s" used twice.', $entry['id']));
            }
            $seen[$entry['id']] = true;
            $normalized[] = $entry;
        }

        if (\strlen((string) json_encode($normalized)) > self::MAX_BYTES) {
            throw new InvalidClassBoardLayoutException('Layout document too large.');
        }

        return $normalized;
    }

    /**
     * A widget as the dock adds it: its default size, placed in a cascade so that several added in
     * a row do not stack exactly on top of each other.
     *
     * @return array<string, mixed>
     */
    public function newWidget(ClassBoardWidgetType $type, string $id, int $existingCount, int $z): array
    {
        if (!self::isValidId($id)) {
            throw new InvalidClassBoardLayoutException('Invalid widget id.');
        }

        $size = $type->defaultSize();
        $step = $existingCount % 6;

        return $this->widget([
            'id' => $id,
            'type' => $type->value,
            'x' => 30 + $step * 2.5,
            'y' => 18 + $step * 3,
            'w' => $size['w'],
            'h' => $size['h'],
            'z' => $z,
            'config' => [],
        ], []);
    }

    public static function isValidId(string $id): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id);
    }

    /**
     * @param array<array-key, mixed>              $widget
     * @param array<string, array<string, mixed>> $previousById
     *
     * @return array{id: string, type: string, x: float, y: float, w: float, h: float, z: int, config: array<string, mixed>}
     */
    private function widget(array $widget, array $previousById, ?int $boardId = null): array
    {
        $id = $widget['id'] ?? null;
        if (!\is_string($id) || !self::isValidId($id)) {
            throw new InvalidClassBoardLayoutException('Invalid widget id.');
        }

        $type = \is_string($widget['type'] ?? null) ? ClassBoardWidgetType::tryFrom($widget['type']) : null;
        if (null === $type) {
            throw new InvalidClassBoardLayoutException(\sprintf('Unknown widget type on "%s".', $id));
        }

        $width = $this->clamp($this->number($widget, 'w', $id), self::MIN_WIDTH, 100.0);
        $height = $this->clamp($this->number($widget, 'h', $id), self::MIN_HEIGHT, 100.0);
        $x = $this->clamp($this->number($widget, 'x', $id), 0.0, 100.0 - $width);
        $y = $this->clamp($this->number($widget, 'y', $id), 0.0, 100.0 - $height);

        $z = $widget['z'] ?? 0;
        if (!\is_int($z)) {
            throw new InvalidClassBoardLayoutException(\sprintf('Invalid z on "%s".', $id));
        }

        $config = $widget['config'] ?? [];
        if (!\is_array($config) || ([] !== $config && array_is_list($config))) {
            throw new InvalidClassBoardLayoutException(\sprintf('Config of "%s" is not an object.', $id));
        }

        $previousConfig = $previousById[$id]['config'] ?? [];

        return [
            'id' => $id,
            'type' => $type->value,
            'x' => round($x, 2),
            'y' => round($y, 2),
            'w' => round($width, 2),
            'h' => round($height, 2),
            'z' => max(0, min(100_000, $z)),
            'config' => $this->config($type, new ConfigReader($config, $id), \is_array($previousConfig) ? $previousConfig : [], $id, $boardId),
        ];
    }

    /**
     * One configuration shape per widget. A missing key takes its default; a key of the wrong type
     * refuses the document.
     *
     * @param array<array-key, mixed> $previousConfig
     *
     * @return array<string, mixed>
     */
    private function config(ClassBoardWidgetType $type, ConfigReader $config, array $previousConfig, string $widgetId, ?int $boardId): array
    {
        return match ($type) {
            ClassBoardWidgetType::Timer => [
                'durationSeconds' => $config->int('durationSeconds', 10, 5 * 3600, 300),
            ],
            ClassBoardWidgetType::Stopwatch => [],
            ClassBoardWidgetType::Dice => [
                'count' => $config->int('count', 1, 6, 2),
            ],
            ClassBoardWidgetType::Clock => $this->clockConfig($config),
            ClassBoardWidgetType::RandomDraw => [
                'drawId' => $config->id('drawId'),
                'optionId' => $config->id('optionId'),
                'allowRepeat' => $config->bool('allowRepeat', false),
            ],
            // The settings of the group creation panel, and the saved lot the widget opens on. The
            // groups drawn are never kept, nor the absentees and the pairs, which are of the day.
            ClassBoardWidgetType::Groups => [
                'batchId' => $config->id('batchId'),
                'mode' => $config->choice('mode', array_column(GroupCreationMode::cases(), 'value'), GroupCreationMode::Size->value),
                'value' => $config->int('value', 2, 10, 3),
                'optionId' => $config->id('optionId'),
                'mixite' => $config->choice('mixite', array_column(GroupMixite::cases(), 'value'), GroupMixite::Free->value),
                'nameFormat' => $config->choice('nameFormat', ['short', 'full'], 'short'),
                'panel' => $config->bool('panel', true),
            ],
            ClassBoardWidgetType::TeamCounter => [
                'batchId' => $config->id('batchId'),
                'teams' => $this->teams($config),
            ],
            ClassBoardWidgetType::TrafficLight => [
                'color' => $config->choice('color', ['red', 'orange', 'green'], 'green'),
            ],
            ClassBoardWidgetType::Instructions => [
                'on' => $config->choices('on', self::INSTRUCTIONS),
            ],
            ClassBoardWidgetType::Text => [
                'html' => $this->sanitizer->sanitize($config->string('html', self::TEXT_MAX, '')),
            ],
            ClassBoardWidgetType::QrCode => [
                'url' => trim($config->string('url', self::URL_MAX, '')),
            ],
            // Today's slot is found again at every opening - nothing of it is kept.
            ClassBoardWidgetType::Session => [],
            ClassBoardWidgetType::SessionPlan => [
                'source' => $config->choice('source', ['session', 'library', 'manual'], 'session'),
                'seanceId' => $config->id('seanceId'),
                'phases' => $this->phases($config),
            ],
            ClassBoardWidgetType::Work => [
                'rows' => $config->int('rows', 1, 10, 5),
            ],
            // A library file is kept as its id, never as an address: the address is signed again
            // at every opening.
            ClassBoardWidgetType::Media => [
                'fileId' => $config->id('fileId'),
            ],
            ClassBoardWidgetType::Video => [
                'url' => trim($config->string('url', self::URL_MAX, '')),
                'fileId' => $config->id('fileId'),
            ],
            // A relative level, not decibels: only the threshold above which the widget warns.
            ClassBoardWidgetType::SoundLevel => [
                'threshold' => $config->int('threshold', 30, 95, 70),
            ],
            // The camera is chosen at every opening - device ids change from one computer to the next.
            ClassBoardWidgetType::Visualizer => [
                'mirror' => $config->bool('mirror', false),
            ],
            ClassBoardWidgetType::Drawing => [
                'key' => $this->drawingKey($config, $previousConfig, $widgetId, $boardId),
            ],
            // The session is found again at every opening; nothing of it is kept.
            ClassBoardWidgetType::QuizLive => [],
            ClassBoardWidgetType::WordCloud => [
                'cloudId' => $config->id('cloudId'),
            ],
        };
    }

    /** @return array{label: string, mode: string, time: string, details: bool} */
    private function clockConfig(ConfigReader $config): array
    {
        // Plain text, on one line: the label sits above the hour and is escaped at display. The
        // platform never fills it itself (§8) - it is whatever the teacher typed, or nothing.
        $label = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $config->string('label', self::CLOCK_LABEL_MAX, '')));

        $time = $config->string('time', 5, '');
        if ('' !== $time && 1 !== preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new InvalidClassBoardLayoutException('Invalid fixed time on a clock.');
        }

        return [
            'label' => $label,
            'mode' => $config->choice('mode', ['live', 'fixed'], 'live'),
            'time' => $time,
            'details' => $config->bool('details', true),
        ];
    }

    /**
     * The storage key of a drawing is the server's, never the page's: the one already stored wins,
     * and a key sent by the page is only taken when nothing is stored yet and it names this very
     * widget's own drawing (ClassBoardDrawings::isKeyOf()) - a forged key cannot point the board,
     * and later its deletion, at somebody else's object.
     *
     * @param array<array-key, mixed> $previousConfig
     */
    private function drawingKey(ConfigReader $config, array $previousConfig, string $widgetId, ?int $boardId): ?string
    {
        $stored = $previousConfig['key'] ?? null;
        if (\is_string($stored) && '' !== $stored) {
            return $stored;
        }

        $sent = $config->string('key', 200, '');

        return null !== $boardId && ClassBoardDrawings::isKeyOf($sent, $boardId, $widgetId) ? $sent : null;
    }

    /**
     * Phases typed by hand: a name and a duration in MINUTES, like a library séance's.
     *
     * @return list<array{name: string, minutes: int}>
     */
    private function phases(ConfigReader $config): array
    {
        $phases = [];
        foreach ($config->objects('phases', self::MAX_PHASES) as $phase) {
            $phases[] = [
                'name' => trim($phase->string('name', self::PHASE_NAME_MAX, '')),
                'minutes' => $phase->int('minutes', 1, 600, 10),
            ];
        }

        return $phases;
    }

    /**
     * As many teams as the teacher adds: a lot of groups makes one team per group, and a count
     * stopped at a number would refuse the whole board for a lot one group larger. The document's
     * own size (MAX_BYTES) is the only bound.
     *
     * @return list<array{name: string, score: int}>
     */
    private function teams(ConfigReader $config): array
    {
        $teams = [];
        foreach ($config->objects('teams') as $team) {
            $teams[] = [
                'name' => trim($team->string('name', self::TEAM_NAME_MAX, '')),
                'score' => $team->int('score', -9999, 99999, 0),
            ];
        }

        return $teams;
    }

    /**
     * @param array<array-key, mixed> $widget
     */
    private function number(array $widget, string $key, string $id): float
    {
        $value = $widget[$key] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            throw new InvalidClassBoardLayoutException(\sprintf('Invalid "%s" on "%s".', $key, $id));
        }

        return (float) $value;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
