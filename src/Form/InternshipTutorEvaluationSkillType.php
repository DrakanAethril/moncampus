<?php

namespace App\Form;

use App\Entity\InternshipSkillLevel;
use App\Entity\InternshipTutorEvaluationSkill;
use App\Repository\InternshipSkillLevelRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Entry type for the InternshipTutorEvaluationType's 'skillEvaluations' CollectionType - unlike
// InternshipTutorEvaluationBehaviorType, every row shares the same choice list (the
// establishment-wide active skill levels), so a plain injected repository lookup is enough
// (same constructor-injected-FormType pattern as LdapManageUserType).
class InternshipTutorEvaluationSkillType extends AbstractType
{
    public function __construct(
        private readonly InternshipSkillLevelRepository $skillLevelRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('skillLevel', EntityType::class, [
            'class' => InternshipSkillLevel::class,
            'choices' => $this->skillLevelRepository->findAllActive(),
            'choice_label' => 'label',
            'label' => false,
            'required' => false,
            'placeholder' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InternshipTutorEvaluationSkill::class]);
    }
}
