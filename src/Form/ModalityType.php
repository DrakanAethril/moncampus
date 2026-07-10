<?php

namespace App\Form;

use App\Entity\LdapManageGroup;
use App\Entity\Modality;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ModalityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'structureNameColumnLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('color', ColorType::class, [
                'label' => 'structureColorColumnLabel',
            ])
            // Not editable here - Modalities are only linked to a Program through the Program's
            // own form (see ProgramType::$modalities / Program::addModality()), same reasoning
            // as OptionType dropping its equivalent "programs" field.
            ->add('ldapGroup', EntityType::class, [
                'class' => LdapManageGroup::class,
                'choice_label' => 'name',
                'label' => 'structureLdapGroupColumnLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Modality::class,
            // Modality's constructor requires a name and a color, so a fresh entity can't be
            // built via plain reflection - construct it here once those fields are submitted.
            'empty_data' => static function (FormInterface $form): Modality {
                return new Modality(
                    $form->get('name')->getData() ?? '',
                    $form->get('color')->getData() ?? '#206bc4',
                );
            },
        ]);
    }
}
