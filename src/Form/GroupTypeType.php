<?php

namespace App\Form;

use App\Entity\GroupType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Shared by "add a group type" and "edit an existing one" - see settings/groups/group_type_new.html.twig.
class GroupTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'groupTypeNameFieldLabel',
                'empty_data' => '',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GroupType::class,
            // GroupType's constructor requires a name, so a fresh entity can't be built via
            // plain reflection - same convention as App\Form\GroupType's own empty_data closure.
            'empty_data' => static fn (FormInterface $form): GroupType => new GroupType($form->get('name')->getData() ?? ''),
        ]);
    }
}
