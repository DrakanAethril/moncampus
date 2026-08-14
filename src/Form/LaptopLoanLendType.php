<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\LaptopConditionType;
use App\Entity\LaptopLoan;
use App\Enum\LaptopLoanType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// The laptop and borrower are set on the entity by the controller before this form is built,
// not editable form fields here - see LaptopController::lendForm(). The borrower is instead
// picked via a plain ajax tom-select field embedded directly in lend.html.twig (resolved from a
// top-level "borrower" POST field, same convention as AssignmentType's manual_recipients).
class LaptopLoanLendType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Fixes which of the two paper conventions this loan prints on, and is therefore asked
            // at lend time rather than at print time - see App\Enum\LaptopLoanType. Placeholder
            // because it is an input select: nothing must be picked by default.
            ->add('loanType', EnumType::class, [
                'class' => LaptopLoanType::class,
                'choice_label' => static fn (LaptopLoanType $type): string => $type->labelKey(),
                'label' => 'laptopLoanTypeFieldLabel',
                'placeholder' => 'laptopLoanTypePlaceholder',
            ])
            // La date du prêt est saisissable, comme sur la maquette 25e : elle était jusqu'ici
            // figée à l'instant de l'enregistrement, ce qui interdisait de saisir un prêt après
            // coup - le cas courant quand l'ordinateur est remis avant le passage au bureau.
            ->add('lentAt', DateType::class, [
                'label' => 'laptopLoanLentAtFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('dueAt', DateType::class, [
                'label' => 'laptopLoanDueAtFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            // Obligatoires malgré l'absence d'astérisque sur la maquette : l'entité impose l'état
            // et ses notes au prêt (colonne non nulle + Assert), c'est ce qui documente l'état du
            // matériel remis. La maquette est ici moins stricte que le modèle, pas l'inverse.
            ->add('lentConditionType', EntityType::class, [
                'class' => LaptopConditionType::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('t')
                    ->where('t.inactiveDate IS NULL')
                    ->orderBy('t.orderIndex', 'ASC'),
                'choice_label' => 'name',
                // La couleur de l'état voyage en data-color, que tom_select_controller.js rend en
                // pastille devant le libellé.
                'choice_attr' => static fn (LaptopConditionType $type): array => ['data-color' => $type->getColor()],
                'label' => 'laptopLoanConditionFieldLabel',
                'placeholder' => 'laptopConditionPlaceholder',
                // Sans tom-select le data-color ci-dessus n'est jamais lu : un <select> natif ne
                // sait pas peindre une pastille devant un <option>.
                'attr' => ['data-controller' => 'tom-select'],
            ])
            ->add('lentStateNotes', TextareaType::class, [
                'label' => 'laptopLoanLentStateNotesFieldLabel',
                'attr' => ['rows' => 4, 'placeholder' => 'laptopLoanLentStateNotesPlaceholder'],
                // Explicit '' (not the default) activates TextareaType's own null->'' safety net
                // for blank submissions on this non-nullable property.
                'empty_data' => '',
            ])
            // "Accessoires prêtés", shaped like the material-condition section just above but
            // entirely optional: a loan may well be the machine and nothing else.
            ->add('lentAccessories', TextareaType::class, [
                'label' => 'laptopLoanLentAccessoriesFieldLabel',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'laptopLoanLentAccessoriesPlaceholder'],
            ])
            ->add('lentAccessoryConditionType', EntityType::class, [
                'class' => LaptopConditionType::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('t')
                    ->where('t.inactiveDate IS NULL')
                    ->orderBy('t.orderIndex', 'ASC'),
                'choice_label' => 'name',
                'choice_attr' => static fn (LaptopConditionType $type): array => ['data-color' => $type->getColor()],
                'label' => 'laptopLoanLentAccessoryConditionFieldLabel',
                'placeholder' => 'laptopConditionPlaceholder',
                'required' => false,
                'attr' => ['data-controller' => 'tom-select'],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'lendLaptopSubmitAction',
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
