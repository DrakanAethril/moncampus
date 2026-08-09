<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether a Program's Syllabus nav entry points at the existing Topic/TopicGroup-derived page
 * (App\Controller\ProgramSyllabusController) or serves an uploaded PDF instead
 * (Program::$syllabusFileKey) - same route either way, the controller branches on this value.
 */
enum ProgramSyllabusMode: string
{
    case Topics = 'topics';
    case File = 'file';

    public function labelKey(): string
    {
        return match ($this) {
            self::Topics => 'programSyllabusModeTopicsLabel',
            self::File => 'programSyllabusModeFileLabel',
        };
    }
}
