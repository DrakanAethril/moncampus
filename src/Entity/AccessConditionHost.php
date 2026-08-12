<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccessConditionDisplay;
use App\Service\AccessConditionTree;

/**
 * An object whose access can be made conditional: Assignment, SequenceInstance,
 * LibraryResourceInstance and QuizInstance - see design/comparaison/conception_1_3_5.md, "Point 3".
 * All four implement it through AccessConditionTrait; the interface is what lets one form, one
 * controller and one evaluator serve the four hosts instead of four of each.
 */
interface AccessConditionHost
{
    public function getId(): ?int;

    public function getAccessConditionTree(): ?AccessConditionTree;

    public function setAccessConditionTree(?AccessConditionTree $tree): static;

    public function getAccessConditionDisplay(): AccessConditionDisplay;

    public function setAccessConditionDisplay(AccessConditionDisplay $display): static;

    /** Whose staff and teachers read straight through the condition, and whose students do not. */
    public function getAccessConditionProgram(): ?Program;

    /** What the object is called on the teacher's form and in a student's reason sentence. */
    public function getAccessConditionLabel(): string;
}
