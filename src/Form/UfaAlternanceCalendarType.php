<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * UFA > Formations > une formation > « Documents » - the one field of that tab.
 *
 * A deliberately narrower door onto the same column as Paramètres > Pédagogique > Formations: the
 * calendar PDF, and nothing else. **The mode is not offered here.** Choosing between the generated
 * calendar and an uploaded one is a decision about how the formation works; putting a file on this
 * tab *is* that decision, so the controller switches the mode to `File` on its own. Somebody who
 * wants the generated calendar back says so from the formation's own settings sheet, where the
 * choice reads as a choice.
 */
class UfaAlternanceCalendarType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('alternanceCalendarFile', FilePickerType::class, [
                'label' => 'programAlternanceCalendarFileFieldLabel',
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'mapped' => false,
                'required' => false,
                'policy' => UploadPolicy::pdf(),
                // A programme document rather than a teacher's own course material: no library tab,
                // exactly as ProgramType declares the same field.
                'library' => false,
            ])
            ->add('submit', SubmitType::class, ['label' => 'submitSaveAction'])
        ;
    }
}
