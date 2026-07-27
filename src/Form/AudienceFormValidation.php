<?php

namespace App\Form;

use App\Enum\MessageAudienceType;
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
 * AgendaEventType/AnnouncementType still have a single-select `audienceType` EnumType field
 * (Program is one exclusive choice among others). MessageComposeType instead has an independent
 * `audienceProgram` checkbox (design/design_handoff_messagerie's cumulative audience vignettes -
 * see that form's own docblock) - both shapes are handled here so this stays the one place that
 * expresses the "Program needs >=1 program and >=1 role" rule.
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

            $programAudienceSelected = match (true) {
                $form->has('audienceType') => MessageAudienceType::Program === $form->get('audienceType')->getData(),
                $form->has('audienceProgram') => true === $form->get('audienceProgram')->getData(),
                default => false,
            };

            if (!$programAudienceSelected) {
                return;
            }

            if ($form->get('programs')->getData()->isEmpty()) {
                $form->get('programs')->addError(new FormError('messageAudienceProgramsRequiredError'));
            }

            if (!$form->get('includeStudents')->getData() && !$form->get('includeTeachers')->getData()) {
                $form->get('includeTeachers')->addError(new FormError('messageAudienceRolesRequiredError'));
            }
        });
    }
}
