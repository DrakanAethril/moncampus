<?php

namespace App\Form;

use App\Entity\LessonType;
use App\Entity\Program;
use App\Entity\ProgramFinancialItem;
use App\Enum\FinancialItemSource;
use App\Enum\FinancialItemType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// The item's "source" (lesson/student/manual) is fixed at creation via which route was used and
// never changes afterward - it drives which extra field is shown (lessonType or quantity) but
// isn't itself a form field.
class ProgramFinancialItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];
        /** @var FinancialItemSource $source */
        $source = $options['source'];

        $builder
            ->add('title', TextType::class, [
                'label' => 'financialItemTitleFieldLabel',
            ])
            ->add('type', EnumType::class, [
                'class' => FinancialItemType::class,
                'choice_label' => static fn (FinancialItemType $type): string => $type->labelKey(),
                'label' => 'financialItemTypeFieldLabel',
            ])
        ;

        if (FinancialItemSource::Lesson === $source) {
            $builder->add('lessonType', EntityType::class, [
                'class' => LessonType::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('l')
                    ->where('l.inactiveDate IS NULL')
                    ->orderBy('l.name', 'ASC'),
                'choice_label' => 'name',
                'label' => 'financialItemLessonTypeFieldLabel',
            ]);
        }

        if (FinancialItemSource::Manual === $source) {
            $builder->add('quantity', NumberType::class, [
                'label' => 'financialItemQuantityFieldLabel',
                'html5' => false,
            ]);
        }

        $builder
            ->add('value', NumberType::class, [
                'label' => 'financialItemValueFieldLabel',
                'html5' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'financialItemDescriptionFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;

        // ProgramFinancialItem's constructor requires a title, source, type and Program - built
        // here from the submitted "title"/"type" and the "source"/"program" form options,
        // captured directly since configureOptions() below has no access to per-request option
        // values.
        $builder->setEmptyData(static function (FormInterface $form) use ($program, $source): ProgramFinancialItem {
            /** @var FinancialItemType $type */
            $type = $form->get('type')->getData() ?? FinancialItemType::Cost;

            return new ProgramFinancialItem($form->get('title')->getData() ?? '', $source, $type, $program);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => ProgramFinancialItem::class])
            ->setRequired(['program', 'source'])
            ->setAllowedTypes('program', Program::class)
            ->setAllowedTypes('source', FinancialItemSource::class)
        ;
    }
}
