<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ProgramEcfSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * UFA > Formations > {formation} > « Livret ECF ». The switch, the dates the ministry's template
 * prints and the organisme - the titre itself is read from « Dénomination » (App\Service\Ecf\EcfTitle).
 * Whether the switch may be on is checked by the controller.
 */
class EcfSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('enabled', CheckboxType::class, [
            'label' => 'ecfSettingsEnabledFieldLabel',
            'required' => false,
        ]);

        foreach (['organisation' => 255, 'place' => 255] as $field => $max) {
            $builder->add($field, TextType::class, [
                'label' => 'ecfSettings'.ucfirst($field).'FieldLabel',
                'required' => false,
                'attr' => ['maxlength' => $max],
            ]);
        }

        foreach (['decreeDate', 'journalDate', 'effectiveDate', 'modelUpdatedDate'] as $field) {
            $builder->add($field, DateType::class, [
                'label' => 'ecfSettings'.ucfirst($field).'FieldLabel',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ]);
        }

        $builder->add('submit', SubmitType::class, ['label' => 'submitSaveAction']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProgramEcfSettings::class]);
    }
}
