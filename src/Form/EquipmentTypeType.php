<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\EquipmentCategory;
use App\Entity\EquipmentLocation;
use App\Entity\EquipmentType;
use App\Repository\EquipmentCategoryRepository;
use App\Repository\EquipmentLocationRepository;
use App\Service\Equipment\EquipmentLedger;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A type of Gestion > Matériel, created or edited.
 *
 * Two fields exist only at creation (`is_creation`):
 *
 * - `unitTracked` - « Suivi à l'unité ». It is the tracking mode, and it is frozen once saved: a
 *   type that switched after the fact would leave its pieces or its numbers meaning nothing;
 * - `quantity`, unmapped - « Nombre d'exemplaires » when the box is ticked (one row and one code
 *   per piece, at most EquipmentLedger::MAX_UNITS_PER_BATCH), « Quantité initiale » otherwise. The
 *   template swaps the two labels; the server reads the box, not the label.
 */
class EquipmentTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'equipmentTypeNameFieldLabel'])
            ->add('category', EntityType::class, [
                'label' => 'equipmentTypeCategoryFieldLabel',
                'class' => EquipmentCategory::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'equipmentNoCategoryPlaceholder',
                'query_builder' => static fn (EquipmentCategoryRepository $repository) => $repository->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
            ]);

        if ($options['is_creation']) {
            $builder
                ->add('unitTracked', CheckboxType::class, [
                    'label' => 'equipmentTypeUnitTrackedFieldLabel',
                    'help' => 'equipmentTypeUnitTrackedFieldHelp',
                    'required' => false,
                ])
                ->add('quantity', IntegerType::class, [
                    'label' => 'equipmentTypeInitialQuantityFieldLabel',
                    'mapped' => false,
                    'data' => 1,
                    'attr' => ['min' => 0],
                    'constraints' => [
                        new Assert\NotNull(),
                        new Assert\PositiveOrZero(),
                        // The cap applies to pieces only - a quantity of 500 cable ties is not a typo.
                        new Assert\Callback(static function (mixed $quantity, ExecutionContextInterface $context): void {
                            $form = $context->getRoot();
                            $unitTracked = $form instanceof FormInterface && $form->has('unitTracked') && true === $form->get('unitTracked')->getData();

                            if ($unitTracked && \is_int($quantity) && $quantity > EquipmentLedger::MAX_UNITS_PER_BATCH) {
                                $context->buildViolation('equipmentTooManyUnitsMessage')->addViolation();
                            }
                        }),
                    ],
                ]);
        }

        $builder
            ->add('brand', TextType::class, ['label' => 'equipmentTypeBrandFieldLabel', 'required' => false])
            ->add('model', TextType::class, ['label' => 'equipmentTypeModelFieldLabel', 'required' => false])
            ->add('unitPrice', NumberType::class, [
                'label' => 'equipmentTypeUnitPriceFieldLabel',
                'help' => 'equipmentTypeUnitPriceFieldHelp',
                'required' => false,
                'input' => 'string',
                'html5' => false,
                'scale' => 2,
            ])
            ->add('alertThreshold', IntegerType::class, [
                'label' => 'equipmentTypeAlertThresholdFieldLabel',
                'help' => 'equipmentTypeAlertThresholdFieldHelp',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('targetStock', IntegerType::class, [
                'label' => 'equipmentTypeTargetStockFieldLabel',
                'help' => 'equipmentTypeTargetStockFieldHelp',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('location', EntityType::class, [
                'label' => 'equipmentTypeLocationFieldLabel',
                'class' => EquipmentLocation::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'equipmentNoLocationPlaceholder',
                'query_builder' => static fn (EquipmentLocationRepository $repository) => $repository->createQueryBuilder('l')->orderBy('l.name', 'ASC'),
            ])
            ->add('supplierReference', TextType::class, [
                'label' => 'equipmentTypeSupplierReferenceFieldLabel',
                'help' => 'equipmentTypeSupplierReferenceFieldHelp',
                'required' => false,
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'equipmentNotesFieldLabel',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('submit', SubmitType::class, ['label' => 'submitSaveAction'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EquipmentType::class,
            'is_creation' => false,
        ]);
        $resolver->setAllowedTypes('is_creation', 'bool');
    }
}
