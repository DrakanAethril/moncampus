<?php

namespace App\Form;

use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Repository\CohortRepository;
use App\Repository\SchoolYearRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// The "19b Nouvelle UFA" creation form - a deliberately small subset of ProgramType's many
// fields (timetable/financial/syllabus/etc. all keep their defaults, set later if needed from
// the Program's own "Paramétrage" screen): just enough to create the Program row itself.
// "Responsable" (an ajax tom-select field, see UfaController::responsableSearch()) is a plain
// top-level request field, not mapped here - it resolves to a User added to both
// Program::$teachers and Program::$referentTeachers server-side, same "resolve before
// handleRequest()" convention as InternshipTutorLinkType's student field.
class UfaFormationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('shortName', TextType::class, [
                'label' => 'ufaNewShortNameFieldLabel',
                'empty_data' => '',
            ])
            ->add('name', TextType::class, [
                'label' => 'ufaNewNameFieldLabel',
                'empty_data' => '',
            ])
            ->add('cohort', EntityType::class, [
                'class' => Cohort::class,
                'choice_label' => 'name',
                'label' => 'ufaNewCohortFieldLabel',
                'placeholder' => 'structureCohortPlaceholder',
                'query_builder' => static fn (CohortRepository $repository) => $repository->createQueryBuilder('c')
                    ->andWhere('c.inactiveDate IS NULL')
                    ->orderBy('c.name', 'ASC'),
            ])
            ->add('schoolYear', EntityType::class, [
                'class' => SchoolYear::class,
                'choice_label' => static fn (SchoolYear $schoolYear): string => sprintf('%s - %s', $schoolYear->getStartDate()->format('Y'), $schoolYear->getEndDate()->format('Y')),
                'label' => 'structureSchoolYearColumnLabel',
                'placeholder' => 'structureSchoolYearPlaceholder',
                'query_builder' => static fn (SchoolYearRepository $repository) => $repository->createQueryBuilder('s')
                    ->andWhere('s.inactiveDate IS NULL')
                    ->orderBy('s.startDate', 'DESC'),
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'ufaNewSubmitLabel',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Program::class,
            // Same reasoning as ProgramType's empty_data - Program's constructor requires a name,
            // short name, Cohort and SchoolYear up front.
            'empty_data' => static function (FormInterface $form): Program {
                $cohort = $form->get('cohort')->getData() ?? new Cohort('', new Track('', new Section('')));
                $schoolYear = $form->get('schoolYear')->getData() ?? new SchoolYear(new \DateTimeImmutable(), new \DateTimeImmutable());

                return new Program(
                    $form->get('name')->getData() ?? '',
                    $form->get('shortName')->getData() ?? '',
                    $cohort,
                    $schoolYear,
                );
            },
        ]);
    }
}
