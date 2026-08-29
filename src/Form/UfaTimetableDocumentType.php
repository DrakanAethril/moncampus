<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * UFA > Formations > une formation > « Documents » > « Emploi du temps » - the one field of that
 * section.
 *
 * **This is a document, not a timetable.** Nothing reads the PDF it uploads: it is not the
 * `LessonSession` timetable, it feeds no screen, no export and no API, and the only way back to it
 * is the tab that put it there. It exists because the UFA team keeps a printed schedule alongside
 * the formation and wants it in the same place as the alternance calendar.
 *
 * A second type rather than a second field on UfaAlternanceCalendarType, because the two are
 * submitted independently: the tab renders two forms, and saving one must not re-validate - or
 * clear - the other.
 */
class UfaTimetableDocumentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('timetableDocumentFile', FilePickerType::class, [
                'label' => 'ufaFormationTimetableDocumentFieldLabel',
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'mapped' => false,
                'required' => false,
                'policy' => UploadPolicy::pdf(),
                // A programme document rather than a teacher's own course material: no library tab,
                // exactly as UfaAlternanceCalendarType declares its own field.
                'library' => false,
            ])
            ->add('submit', SubmitType::class, ['label' => 'submitSaveAction'])
        ;
    }
}
