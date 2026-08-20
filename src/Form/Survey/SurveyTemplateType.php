<?php

declare(strict_types=1);

namespace App\Form\Survey;

use App\Entity\SurveyTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The three fields a survey model carries besides its questions. No launch defaults here, unlike
 * QuizTemplateSettingsType: everything about a wave - audience, anonymity, dates - is chosen at
 * launch and belongs to the campaign, because it is what a replay must be able to change.
 */
class SurveyTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'surveyTemplateNameFieldLabel',
            ])
            ->add('subject', TextType::class, [
                'label' => 'surveyTemplateSubjectFieldLabel',
                'help' => 'surveyTemplateSubjectFieldHelpText',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'surveyTemplateDescriptionFieldLabel',
                'help' => 'surveyTemplateDescriptionFieldHelpText',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SurveyTemplate::class]);
    }
}
