<?php

namespace App\Form;

use App\Entity\Cohort;
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

class CohortType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'structureNameColumnLabel',
            ])
            ->add('track', EntityType::class, [
                'class' => Track::class,
                'choice_label' => 'name',
                'label' => 'structureParentTrackColumnLabel',
                'placeholder' => 'structureTrackPlaceholder',
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
            'data_class' => Cohort::class,
            // Same reasoning as TrackType::$empty_data: Cohort's constructor requires a name
            // and a Track, built here from already-submitted sibling fields, with a throwaway
            // fallback so a missing required field is a validation error, not a TypeError.
            'empty_data' => static function (FormInterface $form): Cohort {
                $track = $form->get('track')->getData() ?? new Track('', new Section(''));

                return new Cohort($form->get('name')->getData() ?? '', $track);
            },
        ]);
    }
}
