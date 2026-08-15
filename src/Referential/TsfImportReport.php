<?php

declare(strict_types=1);

namespace App\Referential;

/**
 * What App\Command\ImportTsfReferentialCommand did, so the same figures can be printed after a dry
 * run and after the real one. A plain object rather than an array: the counters are read in a
 * dozen places and a typo on an array key would silently report zero.
 */
class TsfImportReport
{
    public int $groupsCreated = 0;
    public int $groupsMatched = 0;
    public int $skillsCreated = 0;
    public int $skillsMatched = 0;
    public int $fieldsWritten = 0;
    public int $certificationsCreated = 0;

    /** @var list<string> Entries the import refused to place, with the reason */
    public array $problems = [];

    /** @var list<string> "F. Sautour (C.1)" - names no single program teacher answers to */
    public array $unresolvedTeachers = [];

    public function addProblem(string $problem): void
    {
        $this->problems[] = $problem;
    }

    public function addUnresolvedTeacher(string $written, ?string $code): void
    {
        $this->unresolvedTeachers[] = sprintf('%s (%s)', $written, $code ?? '?');
    }

    /** @return list<string> */
    public function summary(): array
    {
        return [
            sprintf('Blocs : %d créés, %d retrouvés', $this->groupsCreated, $this->groupsMatched),
            sprintf('Compétences : %d créées, %d retrouvées', $this->skillsCreated, $this->skillsMatched),
            sprintf('Champs renseignés : %d', $this->fieldsWritten),
            sprintf('Certifications : %d créées', $this->certificationsCreated),
        ];
    }

    /** @return list<string> */
    public function distinctUnresolvedTeachers(): array
    {
        return array_values(array_unique($this->unresolvedTeachers));
    }
}
