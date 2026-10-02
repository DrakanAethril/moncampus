<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

use App\Entity\ClassBoard;
use App\Enum\ClassBoardWidgetType;
use App\Service\FileUploadService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The drawings of a board (design/validated/tableau-virtuel.md, §3): one PNG per « Dessin » widget,
 * in the uploads storage, replaced at every save of the drawing and removed with its widget or its
 * board through the usual path (FileUploadService::delete(), i.e. ObjectStore). The layout keeps
 * the storage key and nothing else.
 *
 * A key names its board and its widget - `class-board/{board}/{widget}-{random}.png` - which is
 * what lets the layout accept, from the page, a key it has not stored yet without ever accepting one
 * that points elsewhere.
 */
final class ClassBoardDrawings
{
    public const string ORIGIN = 'class-board';
    public const int MAX_BYTES = 3 * 1024 * 1024;
    public const int MAX_SIDE = 4096;

    public function __construct(
        private readonly FileUploadService $fileUploadService,
    ) {
    }

    public static function isKeyOf(string $key, int $boardId, string $widgetId): bool
    {
        return 1 === preg_match('#^class-board/'.$boardId.'/'.preg_quote($widgetId, '#').'-[a-f0-9]{16}\.png$#', $key);
    }

    /**
     * Stores a new drawing for a widget of the board, puts its key into the stored layout when the
     * widget is already saved there, and schedules the deletion of the drawing it replaces.
     *
     * @return string the new key
     *
     * @throws \InvalidArgumentException when the bytes are not a PNG the board accepts
     */
    public function store(ClassBoard $board, string $widgetId, string $bytes, ?string $replacedKey): string
    {
        $boardId = (int) $board->getId();
        if (!ClassBoardLayout::isValidId($widgetId)) {
            throw new \InvalidArgumentException('Invalid widget id.');
        }
        self::assertPng($bytes);

        $path = tempnam(sys_get_temp_dir(), 'class-board-drawing') ?: throw new \RuntimeException('No temporary file.');
        try {
            file_put_contents($path, $bytes);
            $key = $this->fileUploadService->upload(
                'class-board/',
                \sprintf('%d/%s-%s.png', $boardId, $widgetId, bin2hex(random_bytes(8))),
                new UploadedFile($path, 'dessin.png', 'image/png', null, true),
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $layout = $board->getLayout();
        $previous = null;
        foreach ($layout as $index => $widget) {
            if (($widget['id'] ?? null) === $widgetId && ClassBoardWidgetType::Drawing->value === ($widget['type'] ?? null)) {
                $config = \is_array($widget['config'] ?? null) ? $widget['config'] : [];
                $previous = \is_string($config['key'] ?? null) ? $config['key'] : null;
                $layout[$index]['config'] = ['key' => $key] + $config;
                // The layout's own revision does not move: the page saves its document on top of
                // this, and a drawing must not make that save look stale.
                $board->setLayout($layout)->markModified();
            }
        }

        foreach (array_unique(array_filter([$previous, $replacedKey])) as $old) {
            if ($old !== $key && self::isKeyOf($old, $boardId, $widgetId)) {
                $this->fileUploadService->delete($old, self::ORIGIN);
            }
        }

        return $key;
    }

    public function read(string $key): string
    {
        return $this->fileUploadService->read($key);
    }

    /**
     * The drawings a layout points at, by widget id.
     *
     * @param list<array<string, mixed>> $layout
     *
     * @return array<string, string>
     */
    public static function keys(array $layout): array
    {
        $keys = [];
        foreach ($layout as $widget) {
            $config = \is_array($widget['config'] ?? null) ? $widget['config'] : [];
            if (ClassBoardWidgetType::Drawing->value === ($widget['type'] ?? null) && \is_string($widget['id'] ?? null) && \is_string($config['key'] ?? null) && '' !== $config['key']) {
                $keys[$widget['id']] = $config['key'];
            }
        }

        return $keys;
    }

    /**
     * After a save: the drawings of the widgets that are gone.
     *
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     */
    public function forgetRemoved(array $before, array $after): void
    {
        foreach (array_diff(self::keys($before), self::keys($after)) as $key) {
            $this->fileUploadService->delete($key, self::ORIGIN);
        }
    }

    /**
     * The board is going: every drawing with it.
     */
    public function forgetAll(ClassBoard $board): void
    {
        foreach (self::keys($board->getLayout()) as $key) {
            $this->fileUploadService->delete($key, self::ORIGIN);
        }
    }

    /**
     * A duplicated board gets copies of the drawings, under its own keys - deleting one board must
     * never take the other's drawing with it. The copy must already have its id.
     */
    public function copyInto(ClassBoard $copy): void
    {
        $copyId = (int) $copy->getId();
        $layout = $copy->getLayout();
        foreach ($layout as $index => $widget) {
            $config = \is_array($widget['config'] ?? null) ? $widget['config'] : [];
            $key = $config['key'] ?? null;
            if (ClassBoardWidgetType::Drawing->value !== ($widget['type'] ?? null) || !\is_string($key) || '' === $key || !\is_string($widget['id'] ?? null)) {
                continue;
            }
            $newKey = \sprintf('class-board/%d/%s-%s.png', $copyId, $widget['id'], bin2hex(random_bytes(8)));
            try {
                $this->fileUploadService->copy($key, $newKey);
                $layout[$index]['config'] = ['key' => $newKey] + $config;
            } catch (\Throwable) {
                // The source drawing is gone: the copy starts with a blank one rather than failing.
                $layout[$index]['config'] = ['key' => null] + $config;
            }
        }
        $copy->setLayout($layout);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assertPng(string $bytes): void
    {
        if ('' === $bytes || \strlen($bytes) > self::MAX_BYTES || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw new \InvalidArgumentException('Not a PNG the board accepts.');
        }
        $size = @getimagesizefromstring($bytes);
        if (false === $size || \IMAGETYPE_PNG !== $size[2] || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            throw new \InvalidArgumentException('Not a PNG the board accepts.');
        }
    }
}
