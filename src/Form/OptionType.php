<?php

namespace App\Form;

use App\Entity\LdapManageGroup;
use App\Entity\Option;
use App\Entity\Program;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OptionType extends AbstractType
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
            ->add('shortName', TextType::class, [
                'label' => 'structureShortNameColumnLabel',
                'empty_data' => '',
            ])
            ->add('color', ColorType::class, [
                'label' => 'structureColorColumnLabel',
            ])
            ->add('programs', EntityType::class, [
                'class' => Program::class,
                'choice_label' => 'name',
                'label' => 'structureProgramsColumnLabel',
                'multiple' => true,
                'required' => false,
            ])
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
            'data_class' => Option::class,
            // Same reasoning as TrackType::$empty_data: Option's constructor requires a name,
            // a short name and a color, built here from already-submitted sibling fields, with
            // a throwaway fallback so a missing required field is a validation error, not a
            // TypeError.
            'empty_data' => static function (FormInterface $form): Option {
                return new Option(
                    $form->get('name')->getData() ?? '',
                    $form->get('shortName')->getData() ?? '',
                    $form->get('color')->getData() ?? '#206bc4',
                );
            },
        ]);
    }
}
