<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Laptop;
use App\Entity\LaptopConditionType;
use App\Repository\LaptopConditionTypeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LaptopType extends AbstractType
{
    private const string DEFAULT_REPLACEMENT_VALUE = '500.00';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('assetTag', TextType::class, [
                'label' => 'laptopAssetTagFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('serialNumber', TextType::class, [
                'label' => 'laptopSerialNumberFieldLabel',
                'help' => 'laptopSerialNumberFieldHelp',
                'empty_data' => '',
            ])
            ->add('brand', TextType::class, [
                'label' => 'laptopBrandFieldLabel',
                'required' => false,
            ])
            ->add('model', TextType::class, [
                'label' => 'laptopModelFieldLabel',
                'required' => false,
            ])
            ->add('currentConditionType', EntityType::class, [
                'class' => LaptopConditionType::class,
                'choice_label' => 'name',
                // Color chip before the label (see tom_select_controller.js), as on mockup 25b.
                'choice_attr' => static fn (LaptopConditionType $type): array => ['data-color' => $type->getColor()],
                'label' => 'laptopInitialConditionFieldLabel',
                'placeholder' => 'laptopConditionPlaceholder',
                'required' => false,
                'attr' => ['data-controller' => 'tom-select'],
                'query_builder' => static fn (LaptopConditionTypeRepository $repository) => $repository->createQueryBuilder('t')
                    ->andWhere('t.inactiveDate IS NULL')
                    ->orderBy('t.orderIndex', 'ASC'),
            ])
            // 'input' => 'string' keeps the money a decimal string from the field down to the
            // column, the way the entity stores it - the default 'number' would route it through a
            // PHP float. html5 => false for the same reason LessonTypeType's defaultCost does it:
            // a native number input insists on a dot while the French locale types a comma.
            ->add('replacementValue', NumberType::class, [
                'label' => 'laptopReplacementValueFieldLabel',
                'help' => 'laptopReplacementValueFieldHelp',
                'input' => 'string',
                'html5' => false,
                'scale' => 2,
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'laptopNotesFieldLabel',
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => 'laptopNotesPlaceholder'],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;

        // The usual replacement value of a fleet machine, offered on creation only - a laptop that
        // already carries one keeps it. POST_SET_DATA and not PRE_SET_DATA: the data mapper runs
        // between the two and would write the property's null straight over anything set earlier.
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $laptop = $event->getData();

            if (!$laptop instanceof Laptop || null === $laptop->getReplacementValue()) {
                $event->getForm()->get('replacementValue')->setData(self::DEFAULT_REPLACEMENT_VALUE);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Laptop::class,
            // Laptop's constructor requires an assetTag, so a fresh entity can't be built via
            // plain reflection - construct it here once the field has actually been submitted.
            'empty_data' => static function (FormInterface $form): Laptop {
                return new Laptop($form->get('assetTag')->getData() ?? '');
            },
        ]);
    }
}
