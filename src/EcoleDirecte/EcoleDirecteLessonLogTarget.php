<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

use App\Enum\EcoleDirecteSendState;

/**
 * One École Directe slot that would receive something: its session content, its homework, or both.
 * The raw slot is kept as École Directe answered it, because sending means handing the whole slot
 * back with only the content changed.
 */
final readonly class EcoleDirecteLessonLogTarget
{
    /**
     * @param array<array-key, mixed> $slot
     * @param list<string>            $sources the MonCampus séances the content comes from
     */
    public function __construct(
        public string $key,
        public array $slot,
        public string $date,
        public string $start,
        public string $className,
        public string $subject,
        public ?string $contentHtml,
        public ?EcoleDirecteSendState $contentState,
        public ?string $homeworkHtml,
        public ?EcoleDirecteSendState $homeworkState,
        public string $homeworkGivenOn,
        public array $sources,
    ) {
    }

    public function sendsSomething(): bool
    {
        return true === $this->contentState?->sends() || true === $this->homeworkState?->sends();
    }
}
