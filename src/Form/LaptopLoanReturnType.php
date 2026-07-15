<?php

namespace App\Form;

use App\Entity\LaptopConditionType;
use App\Entity\LaptopLoan;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LaptopLoanReturnType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('returnConditionType', EntityType::class, [
                'class' => LaptopConditionType::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('t')
                    ->where('t.inactiveDate IS NULL')
                    ->orderBy('t.name', 'ASC'),
                'choice_label' => 'name',
                'label' => 'laptopLoanConditionFieldLabel',
                'placeholder' => false,
            ])
            ->add('returnStateNotes', TextareaType::class, [
                'label' => 'laptopLoanReturnStateNotesFieldLabel',
                // Explicit '' (not the default) activates TextareaType's own null->'' safety net
                // for blank submissions on this non-nullable-at-return-time field.
                'empty_data' => '',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'returnLaptopSubmitAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LaptopLoan::class,
        ]);
    }
}
