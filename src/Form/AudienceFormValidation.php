<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\MessageAudienceType;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Shared cross-field validation for the Program audience type, reused by AgendaEventType,
 * AnnouncementType, and MessageComposeType: "at least one Program" and "at least one role"
 * (students/teachers) are only meaningful - and only enforced - when the Program audience is
 * actually selected. A plain per-field constraint can't express "required only when this other
 * field has this value", so this runs as a SUBMIT listener instead, once the real submitted
 * values are available on every field.
 *
 * AgendaEventType/AnnouncementType/SignupListType carry a multi-select `audienceTypes` EnumType
 * field (Program is one of several tickable audiences). MessageComposeType instead has an
 * independent `audienceProgram` checkbox, because its audiences are rendered as the handoff's
 * vignettes rather than as a plain checkbox list (design/design_handoff_messagerie - see that
 * form's own docblock) - both shapes are handled here so this stays the one place that expresses
 * the "Program needs >=1 program and >=1 role" rule.
 */
final class AudienceFormValidation
{
    public static function addProgramAudienceValidation(FormBuilderInterface $builder): void
    {
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();

            // Absent entirely for MessageComposeType's lockedRecipient path (no audience picker at
            // all in that case) - nothing to validate.
            if (!$form->has('programs')) {
                return;
            }

            $submittedTypes = $form->has('audienceTypes') ? $form->get('audienceTypes')->getData() : null;

            $programAudienceSelected = match (true) {
                \is_array($submittedTypes) => \in_array(MessageAudienceType::Program, $submittedTypes, true),
                $form->has('audienceProgram') => true === $form->get('audienceProgram')->getData(),
                default => false,
            };

            if (!$programAudienceSelected) {
                return;
            }

            $programs = $form->get('programs')->getData();
            if (!$programs instanceof Collection || $programs->isEmpty()) {
                $form->get('programs')->addError(new FormError('messageAudienceProgramsRequiredError'));
            }

            if (!$form->get('includeStudents')->getData() && !$form->get('includeTeachers')->getData()) {
                $form->get('includeTeachers')->addError(new FormError('messageAudienceRolesRequiredError'));
            }
        });
    }
}
