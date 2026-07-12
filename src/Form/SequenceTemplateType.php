<?php

namespace App\Form;

use App\Entity\Bloc;
use App\Entity\Cohort;
use App\Entity\Option;
use App\Entity\SequenceTemplate;
use App\Repository\BlocRepository;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SequenceTemplateType extends AbstractType
{
    public function __construct(private readonly BlocRepository $blocRepository)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, [
                'label' => 'sequenceTemplateTitreFieldLabel',
            ])
            ->add('cohort', EntityType::class, [
                'class' => Cohort::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('c')
                    ->where('c.inactiveDate IS NULL')
                    ->orderBy('c.name', 'ASC'),
                'choice_label' => 'name',
                'label' => 'sequenceTemplateNiveauFieldLabel',
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('option', EntityType::class, [
                'class' => Option::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('o')
                    ->where('o.inactiveDate IS NULL')
                    ->orderBy('o.shortName', 'ASC'),
                'choice_label' => 'shortName',
                'label' => 'sequenceTemplateOptionFieldLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('blocs', EntityType::class, [
                'class' => Bloc::class,
                'choices' => $this->blocRepository->findAllActiveOrderedByCode(),
                'choice_label' => static fn (Bloc $bloc): string => sprintf('%s - %s', $bloc->getCode(), $bloc->getLabel()),
                'label' => 'sequenceTemplateBlocsFieldLabel',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('capacitesAttendues', TextareaType::class, [
                'label' => 'sequenceTemplateCapacitesAttenduesFieldLabel',
                'required' => false,
            ])
            ->add('preRequis', TextareaType::class, [
                'label' => 'sequenceTemplatePreRequisFieldLabel',
                'required' => false,
            ])
            ->add('objectifs', TextareaType::class, [
                'label' => 'sequenceTemplateObjectifsFieldLabel',
                'required' => false,
            ])
            ->add('transversalites', TextareaType::class, [
                'label' => 'sequenceTemplateTransversalitesFieldLabel',
                'required' => false,
            ])
            ->add('situationProblematique', TextareaType::class, [
                'label' => 'sequenceTemplateSituationProblematiqueFieldLabel',
                'required' => false,
            ])
            ->add('supportsGeneraux', TextareaType::class, [
                'label' => 'sequenceTemplateSupportsGenerauxFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SequenceTemplate::class]);
    }
}
