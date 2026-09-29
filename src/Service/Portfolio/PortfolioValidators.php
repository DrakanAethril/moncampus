<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\Option;
use App\Entity\Portfolio;
use App\Entity\PortfolioValidator;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\PortfolioValidatorRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * **The one rule of who decides about a portfolio** (R2) - read by the Voter, the queue, the menu
 * and the home card, so they cannot disagree.
 *
 * A teacher may review (and endorse the deposits of) a student's portfolio when all three hold:
 *
 * 1. an active App\Entity\PortfolioValidator names them for the student's **current formation**
 *    (App\Service\Portfolio\PortfolioContext) - last year's validateurs are not this year's;
 * 2. that row names the student's **option** - a SISR validateur does not decide about SLAM. A
 *    student with no option yet is open to the validateurs of every option of the class (§14,
 *    question 2), and the Paramétrage screen counts those students so the gap gets closed;
 * 3. they **still teach** in that formation - a designation outliving a departure opens nothing.
 *
 * Nothing else passes: no administrator, no referent teacher, no teacher of the class who was not
 * designated. That is the decision of §1, and why ROLE_ADMIN appears nowhere below.
 */
class PortfolioValidators implements ResetInterface
{
    /** @var array<int, list<PortfolioValidator>> */
    private array $designationsByTeacher = [];

    public function __construct(
        private readonly PortfolioValidatorRepository $validators,
        private readonly PortfolioContext $context,
    ) {
    }

    public function reset(): void
    {
        $this->designationsByTeacher = [];
    }

    /**
     * The teacher's live designations: active **and** still teaching in the formation.
     *
     * @return list<PortfolioValidator>
     */
    public function designationsOf(User $teacher): array
    {
        return $this->designationsByTeacher[(int) $teacher->getId()] ??= array_values(array_filter(
            $this->validators->findActiveForTeacher($teacher),
            static fn (PortfolioValidator $row): bool => null !== $row->getProgram() && self::teaches($row->getProgram(), $teacher),
        ));
    }

    public function isValidator(User $teacher): bool
    {
        return [] !== $this->designationsOf($teacher);
    }

    public function canReview(User $teacher, Portfolio $portfolio): bool
    {
        $student = $portfolio->getStudent();
        $referential = $portfolio->getReferential();

        if (null === $student || null === $referential) {
            return false;
        }

        $program = $this->context->currentProgram($student, $referential);

        if (null === $program) {
            return false;
        }

        $option = $this->context->optionOf($student, $program, $referential);

        return $this->covers($teacher, $program, $option);
    }

    /**
     * Does one of the teacher's live designations cover this (formation, option)? A null option -
     * a student not yet placed in SLAM or SISR - is covered by any designation on the formation.
     */
    public function covers(User $teacher, Program $program, ?Option $option): bool
    {
        foreach ($this->designationsOf($teacher) as $row) {
            if ($row->getProgram()?->getId() !== $program->getId()) {
                continue;
            }

            if (null === $option || $row->getOption()?->getId() === $option->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The validateurs a student may ask for by name - those who would see the piece anyway.
     *
     * @return list<User>
     */
    public function validatorsFor(Program $program, ?Option $option): array
    {
        $people = [];

        foreach ($this->validators->findActiveForProgram($program) as $row) {
            $teacher = $row->getTeacher();
            if (null === $teacher || !self::teaches($program, $teacher)) {
                continue;
            }

            if (null === $option || $row->getOption()?->getId() === $option->getId()) {
                $people[(int) $teacher->getId()] = $teacher;
            }
        }

        return array_values($people);
    }

    private static function teaches(Program $program, User $teacher): bool
    {
        return $program->getTeachers()->exists(static fn (int $key, User $candidate): bool => $candidate->getId() === $teacher->getId());
    }
}
