<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

use App\Enum\ClassImportAction;

/**
 * The whole read-only verdict on one uploaded file: every line, plus the counts the operator
 * actually validates against ("18 créations, 4 rattachements, 1 décision à prendre").
 *
 * Built once by ClassImportAnalyzer and built again from the same rows just before writing - see
 * ClassImportExecutor, which refuses to run against an analysis that has moved since.
 */
final readonly class ClassImportAnalysis
{
    /** @param list<AnalyzedStudent> $students */
    public function __construct(
        public string $fileName,
        public array $students,
    ) {
    }

    /**
     * Everything has to be true at once: nothing blocking, no decision left open, and at least one
     * line that actually writes something. A file that would change nothing is not an import - the
     * common case being the same file uploaded twice, which must be idempotent and say so.
     */
    public function isImportable(): bool
    {
        return 0 === $this->blockingCount() && 0 === $this->decideCount() && $this->writingCount() > 0;
    }

    public function createCount(): int
    {
        return $this->countWith(ClassImportAction::Create);
    }

    public function attachCount(): int
    {
        return $this->countWith(ClassImportAction::Attach);
    }

    public function updateCount(): int
    {
        return $this->countWith(ClassImportAction::Update);
    }

    public function decideCount(): int
    {
        return $this->countWith(ClassImportAction::Decide);
    }

    public function blockingCount(): int
    {
        return $this->countWith(ClassImportAction::Blocked);
    }

    public function writingCount(): int
    {
        return \count(array_filter($this->students, static fn (AnalyzedStudent $student): bool => $student->writes()));
    }

    public function warningCount(): int
    {
        $count = 0;
        foreach ($this->students as $student) {
            $count += \count($student->warnings());
        }

        return $count;
    }

    /** @return list<AnalyzedStudent> */
    public function studentsWith(ClassImportAction $action): array
    {
        return array_values(array_filter($this->students, static fn (AnalyzedStudent $student): bool => $student->action === $action));
    }

    /** @return list<AnalyzedStudent> */
    public function blockedStudents(): array
    {
        return $this->studentsWith(ClassImportAction::Blocked);
    }

    /** @return list<AnalyzedStudent> */
    public function studentsWithWarnings(): array
    {
        return array_values(array_filter($this->students, static fn (AnalyzedStudent $student): bool => [] !== $student->warnings()));
    }

    /**
     * The lines whose namesakes have to be read rather than answered in one click.
     *
     * @return list<AnalyzedStudent>
     */
    public function studentsToExamine(): array
    {
        return array_values(array_filter(
            $this->students,
            static fn (AnalyzedStudent $student): bool => [] !== $student->candidates && !$student->isObviousDecision(),
        ));
    }

    /**
     * The answers the header's bulk action would give - line => account id. Only the lines with a
     * single active student namesake; a disabled account or an ambiguity is never answered in bulk.
     *
     * @return array<int, int>
     */
    public function obviousDecisions(): array
    {
        $decisions = [];
        foreach ($this->students as $student) {
            if ($student->isObviousDecision()) {
                $decisions[$student->line] = $student->candidates[0]->id;
            }
        }

        return $decisions;
    }

    private function countWith(ClassImportAction $action): int
    {
        return \count($this->studentsWith($action));
    }
}
