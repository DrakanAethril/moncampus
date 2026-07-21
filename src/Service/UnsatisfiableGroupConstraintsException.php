<?php

namespace App\Service;

/**
 * Thrown by App\Service\GroupCreationService when the requested separate/together pair
 * constraints can't all be honored (a direct or transitive conflict between "réunir" and
 * "séparer", a "réunir" cluster too large for the group capacity, or capacity/conflicts that
 * still can't be reconciled after every retry) - see design/design_campus_manager/
 * PROMPT_CLAUDE_CODE_groupes.md's acceptance criteria: this must surface as a clear error to the
 * teacher, never as a silently-violated constraint or a crash.
 */
class UnsatisfiableGroupConstraintsException extends \RuntimeException
{
}
