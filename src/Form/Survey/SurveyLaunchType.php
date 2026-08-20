<?php

declare(strict_types=1);

namespace App\Form\Survey;

use App\Entity\Program;
use App\Entity\SurveyCampaign;
use App\Enum\MessageAudienceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The launch form - audience, anonymity, dates, and the travail à faire.
 *
 * The audience half is wired exactly like SignupListType/AnnouncementType: checkbox groups over
 * MessageAudienceType, plus the programs and the two include flags. No new audience rule is written
 * for surveys, deliberately.
 *
 * $anonymous is a plain field here and immutable afterwards - SurveyCampaign::setAnonymous() throws
 * once the target is frozen, which is the server-side half of « ce choix ne pourra plus être
 * modifié ».
 */
class SurveyLaunchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Program> $programs */
        $programs = $options['programs'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'surveyCampaignNameFieldLabel',
            ])
            ->add('audienceTypes', EnumType::class, [
                'class' => MessageAudienceType::class,
                'choice_label' => static fn (MessageAudienceType $type): string => $type->labelKey(),
                // Checkboxes rather than radios: audiences are cumulative, « les étudiants de SIO1
                // et tous les enseignants » is one selection.
                'expanded' => true,
                'multiple' => true,
                'label' => 'surveyAudienceTypesFieldLabel',
                'help' => 'surveyAudienceTypesFieldHelpText',
            ])
            ->add('programs', EntityType::class, [
                'class' => Program::class,
                'choices' => $programs,
                'choice_label' => static fn (Program $program): string => $program->getDisplayShortName(),
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'label' => 'surveyProgramsFieldLabel',
            ])
            ->add('includeStudents', CheckboxType::class, [
                'label' => 'surveyIncludeStudentsFieldLabel',
                'required' => false,
            ])
            ->add('includeTeachers', CheckboxType::class, [
                'label' => 'surveyIncludeTeachersFieldLabel',
                'required' => false,
            ])
            ->add('anonymous', CheckboxType::class, [
                'label' => 'surveyAnonymousFieldLabel',
                'help' => 'surveyAnonymousFieldHelpText',
                'required' => false,
            ])
            ->add('opensAt', DateTimeType::class, [
                'label' => 'surveyOpensAtFieldLabel',
                'help' => 'surveyOpensAtFieldHelpText',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('closesAt', DateTimeType::class, [
                'label' => 'surveyClosesAtFieldLabel',
                'help' => 'surveyClosesAtFieldHelpText',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('resultsVisibleToRespondents', CheckboxType::class, [
                'label' => 'surveyResultsVisibleFieldLabel',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SurveyCampaign::class, 'programs' => []]);
        $resolver->setAllowedTypes('programs', 'array');
    }
}
