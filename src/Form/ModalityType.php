<?php

namespace App\Form;

use App\Entity\Cohort;
use App\Entity\LdapManageGroup;
use App\Entity\Modality;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
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
            ])
            ->add('cohorts', EntityType::class, [
                'class' => Cohort::class,
                'choice_label' => 'name',
                'label' => 'structureCohortsColumnLabel',
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
            'data_class' => Modality::class,
            // Modality's constructor requires a name, so a fresh entity can't be built via
            // plain reflection - construct it here once the name field has actually been
            // submitted.
            'empty_data' => static function (FormInterface $form): Modality {
                return new Modality($form->get('name')->getData() ?? '');
            },
        ]);
    }
}
