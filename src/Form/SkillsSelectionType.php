<?php

namespace App\Form;

use App\Entity\Program;
use App\Entity\Skill;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Not tied to a persisted entity - a one-off multi-select over the program's own active skills,
// used by the TSF export.
class SkillsSelectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            ->add('skills', EntityType::class, [
                'class' => Skill::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('s')
                    ->where('s.program = :program')
                    ->andWhere('s.inactiveDate IS NULL')
                    ->setParameter('program', $program)
                    ->orderBy('s.name', 'ASC'),
                'choice_label' => 'name',
                'label' => 'exportSkillsFieldLabel',
                'multiple' => true,
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'exportGenerateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // GET, not POST: same reasoning as ExportDateRangeType.
        $resolver
            ->setDefaults(['data_class' => null, 'method' => 'GET', 'csrf_protection' => false])
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
