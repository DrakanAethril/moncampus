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
 * (students/teachers) are only meaningful - and only enforced - when audienceType is actually
 * Program. A plain per-field constraint can't express "required only when this other field has
 * this value", so this runs as a SUBMIT listener instead, once the real submitted values are
 * available on every field.
 */
final class AudienceFormValidation
{
    public static function addProgramAudienceValidation(FormBuilderInterface $builder): void
    {
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();

            // Absent entirely for MessageComposeType's lockedRecipient path (no audience picker at
            // all in that case) - nothing to validate.
            if (!$form->has('audienceType') || !$form->has('programs')) {
                return;
            }

            if (MessageAudienceType::Program !== $form->get('audienceType')->getData()) {
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
