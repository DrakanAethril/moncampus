<?php

namespace App\Form;

use App\Entity\LdapManageGroup;
use App\Entity\Section;
use App\Entity\Track;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TrackType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'structureNameColumnLabel',
            ])
            ->add('section', EntityType::class, [
                'class' => Section::class,
                'choice_label' => 'name',
                'label' => 'structureParentSectionColumnLabel',
                'placeholder' => 'structureSectionPlaceholder',
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
            'data_class' => Track::class,
            // Track's constructor requires a name and a Section, so a fresh entity can't be
            // built via plain reflection - construct it here once those fields have actually
            // been submitted. Falls back to a throwaway Section if none was selected, so a
            // missing required field surfaces as a normal validation error instead of a
            // TypeError - the fallback is never persisted since the form won't be valid.
            'empty_data' => static function (FormInterface $form): Track {
                $section = $form->get('section')->getData() ?? new Section('');

                return new Track($form->get('name')->getData() ?? '', $section);
            },
        ]);
    }
}
