<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ProgramEcfSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The ECF part of UFA > Formations > {formation} > « Dénomination »: the switch and the organisme,
 * which hold whatever the option. Hung off the tab's single form by
 * App\Service\Ecf\EcfSettingsEditor, which also says whether the switch may be on - so it carries
 * no submit button of its own.
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProgramEcfSettings::class]);
    }
}
