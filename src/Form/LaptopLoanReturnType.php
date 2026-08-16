<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\LaptopConditionType;
use App\Entity\LaptopLoan;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LaptopLoanReturnType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Return date, editable as on mockup 25f - the return is not always recorded on the day
            // the laptop comes back.
            ->add('returnedAt', DateType::class, [
                'label' => 'laptopLoanReturnedAtFieldLabel',
                // Default value carried by the field and not by the entity: filling $returnedAt on
                // the loan would make it look returned (LaptopLoan::isReturned()), and the screen
                // would immediately lose its overdue indication.
                'data' => new \DateTimeImmutable(),
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('returnConditionType', EntityType::class, [
                'class' => LaptopConditionType::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('t')
                    ->where('t.inactiveDate IS NULL')
                    ->orderBy('t.orderIndex', 'ASC'),
                'choice_label' => 'name',
                'choice_attr' => static fn (LaptopConditionType $type): array => ['data-color' => $type->getColor()],
                'label' => 'laptopLoanReturnConditionFieldLabel',
                // An empty choice at the top, and not 'placeholder' => false: the condition observed
                // on return is an entry, not a carry-over. Without it the browser selects the first
                // condition of the list and a return recorded without touching it asserts a condition
                // nobody observed.
                'placeholder' => 'laptopConditionPlaceholder',
                'required' => false,
                'attr' => ['data-controller' => 'tom-select'],
            ])
            ->add('returnStateNotes', TextareaType::class, [
                'label' => 'laptopLoanReturnStateNotesFieldLabel',
                'required' => false,
                'attr' => ['rows' => 5],
                // Explicit '' (not the default) activates TextareaType's own null->'' safety net
                // for blank submissions on this non-nullable-at-return-time field.
                'empty_data' => '',
            ])
            // "État des accessoires", the return-time counterpart of the lend form's "Accessoires
            // prêtés" section. Optional on both sides: a loan with no accessory has nothing to
            // record here.
            ->add('returnAccessoryConditionType', EntityType::class, [
                'class' => LaptopConditionType::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('t')
                    ->where('t.inactiveDate IS NULL')
                    ->orderBy('t.orderIndex', 'ASC'),
                'choice_label' => 'name',
                'choice_attr' => static fn (LaptopConditionType $type): array => ['data-color' => $type->getColor()],
                'label' => 'laptopLoanReturnAccessoryConditionFieldLabel',
                'placeholder' => 'laptopConditionPlaceholder',
                'required' => false,
                'attr' => ['data-controller' => 'tom-select'],
            ])
            ->add('returnAccessoryNotes', TextareaType::class, [
                'label' => 'laptopLoanReturnAccessoryNotesFieldLabel',
                'required' => false,
                'attr' => ['rows' => 3],
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
